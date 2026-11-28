<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Connection\SessionTest;

use Closure;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Tests\Driver\TestCase;
use Rajska\Oracle\Tracing;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run(<<<'SQL'
        create function session_slow(p_value number, p_seconds number) return number is
        begin
            dbms_session.sleep(p_seconds);
            return p_value;
        end;
        SQL);
    schema()->run('create table session_bulk (id number(10) primary key)');
    schema()->run('insert into session_bulk (id) select level from dual connect by level <= 10000');
});

/**
 * @return iterable<string, array{int, int, bool}>
 */
dataset('fullBatches', function () {
    yield 'one row at the default fetch size' => [1, 100, true];
    yield '99 rows at the default fetch size' => [99, 100, true];
    yield '100 rows at the default fetch size' => [100, 100, true];
    yield '3 rows at a fetch size of 3' => [3, 3, true];
    yield '3 rows at a fetch size of 3 without autocommit' => [3, 3, false];
});

/**
 * Each kind of cursor over 25 rows, and the Round trips opening and reading
 * it costs at a fetch size of 10: a query brings its first batch with the
 * execute; a ref cursor and an Implicit result fetch all three.
 *
 * @return iterable<string, array{Closure(Session, Options): Cursor, int}>
 */
dataset('cursorKinds', function () {
    $levels = 'select level n from dual connect by level <= 25';
    yield 'a query' => [static function (Session $c, Options $o) use ($levels): Cursor {
        $cursor = $c->execute($levels, [], $o)->cursor();
        assert($cursor instanceof Cursor);

        return $cursor;
    }, 3];
    yield 'a ref cursor' => [static function (Session $c, Options $o) use ($levels): Cursor {
        $cursor = $c->execute("begin open :o for $levels; end;", ['o' => new Out\Cursor()], $o)->outValues()['o'][0] ?? null;
        assert($cursor instanceof Cursor);

        return $cursor;
    }, 4];
    yield 'an implicit result' => [static function (Session $c, Options $o) use ($levels): Cursor {
        $results = $c->execute("declare c sys_refcursor; begin open c for $levels; dbms_sql.return_result(c); end;", [], $o)->implicitResults();
        assert(isset($results[0]));

        return $results[0];
    }, 4];
});

describe('server version', function (): void {
    it('has five parts, matches version full and costs no round trip', function (): void {
        $connection = $this->connect();
        $sid = $this->sid($connection);
        $version = null;

        $trips = roundTrips($sid, static function () use ($connection, &$version): void {
            $version = $connection->serverVersion();
        });

        expect($trips)->toBe(0)
            ->and($version)->toMatch('/^\d+\.\d+\.\d+\.\d+\.\d+$/')
            ->toBe($this->value($connection, "select version_full from product_component_version where product like 'Oracle%'"));
    });
});

describe('tracing', function (): void {
    it('shows the config tracing in v session after the first round trip', function (): void {
        $connection = $this->connect(tracing: new Tracing(
            module: 'billing',
            action: 'close month',
            clientIdentifier: 'user-42',
            clientInfo: 'worker 7',
        ));

        $sid = $this->sid($connection);

        expect(tracing(otherSession(), $sid))->toBe([
            'MODULE' => 'billing',
            'ACTION' => 'close month',
            'CLIENT_IDENTIFIER' => 'user-42',
            'CLIENT_INFO' => 'worker 7',
        ]);
    });

    it('costs no round trip and replaces the whole set with the next one', function (): void {
        $connection = $this->connect(tracing: new Tracing(
            module: 'billing',
            action: 'close month',
            clientIdentifier: 'user-42',
            clientInfo: 'worker 7',
        ));
        $sid = $this->sid($connection);

        $trips = roundTrips($sid, static fn () => $connection->trace(new Tracing(module: 'reports', clientInfo: 'worker 8')));
        $pending = tracing(otherSession(), $sid)['MODULE'];
        $this->sid($connection);

        expect($trips)->toBe(0)
            ->and($pending)->toBe('billing')
            ->and(tracing(otherSession(), $sid))->toBe([
                'MODULE' => 'reports',
                'ACTION' => null,
                'CLIENT_IDENTIFIER' => null,
                'CLIENT_INFO' => 'worker 8',
            ]);
    });

    it('leaves no tracing to the next connection on the same session', function (bool $sent): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $first = $this->connect(...$fields);
        $sid = $this->sid($first);
        $first->trace(new Tracing(module: 'A', action: 'A', clientIdentifier: 'A', clientInfo: 'A'));
        if ($sent) {
            $this->sid($first);
        }
        $first->close();

        $second = $this->connect(...$fields);

        expect($this->rows($second, <<<'SQL'
            select sys_context('userenv', 'sid') sid,
                   sys_context('userenv', 'module') module,
                   sys_context('userenv', 'action') action,
                   sys_context('userenv', 'client_identifier') client_identifier,
                   sys_context('userenv', 'client_info') client_info
              from dual
            SQL)[0])->toBe(['SID' => (string) $sid, 'MODULE' => null, 'ACTION' => null, 'CLIENT_IDENTIFIER' => null, 'CLIENT_INFO' => null]);
    })->with(['sent' => [true], 'still pending' => [false]]);
});

describe('fetch size', function (): void {
    it('takes one round trip for a query of up to fetch size rows', function (int $count, int $fetchSize, bool $autocommit): void {
        $connection = $this->connect();
        $sid = $this->sid($connection);
        $rows = [];

        $trips = roundTrips($sid, function () use ($connection, $count, $fetchSize, $autocommit, &$rows): void {
            $rows = $this->rows($connection, 'select level n from dual connect by level <= :n', ['n' => $count], $this->options(fetchSize: $fetchSize, autocommit: $autocommit));
        });

        expect($trips)->toBe(1)
            ->and($rows)->toHaveCount($count);
    })->with('fullBatches');

    it('reads fetch size rows per round trip', function (Closure $open, int $expectedTrips): void {
        $connection = $this->connect();
        $sid = $this->sid($connection);
        $sizes = [];

        $trips = roundTrips($sid, function () use ($open, $connection, &$sizes): void {
            $cursor = $open($connection, $this->options(fetchSize: 10));
            assert($cursor instanceof Cursor);
            while (($batch = $cursor->fetchBatch()) !== []) {
                $sizes[] = \count($batch);
            }
        });

        expect($sizes)->toBe([10, 10, 5])
            ->and($trips)->toBe($expectedTrips);
    })->with('cursorKinds');

    it('lets the fetch size of an execute govern its cursor', function (): void {
        $connection = $this->connect();
        $rows = 'select level n from dual connect by level <= 25';

        $small = $connection->execute($rows, [], $this->options(fetchSize: 7))->cursor();
        $large = $connection->execute($rows, [], $this->options(fetchSize: 20))->cursor();

        assert($small !== null);
        assert($large !== null);
        expect($small->fetchBatch())->toHaveCount(7)
            ->and($large->fetchBatch())->toHaveCount(20)
            ->and($small->fetchBatch())->toHaveCount(7);
    });
});

describe('call timeout', function (): void {
    it('throws timeout exception over the call timeout and the connection survives', function (): void {
        $connection = $this->connect(...$this->ownPool());
        $sql = 'select count(*) from session_bulk a, session_bulk b';
        $started = microtime(true);

        $count = fn (): mixed => $connection->execute($sql, [], $this->options(timeout: 0.5))->cursor()?->fetchBatch();

        expect($count)->toThrow(function (TimeoutException $exception) use ($sql, $started): void {
            expect($exception->getCode())->toBe(0)
                ->and($exception->getMessage())->toBe('Call timeout of 500 ms exceeded.')
                ->and($exception->sql())->toBe($sql)
                ->and(microtime(true) - $started)->toBeLessThan(5);
        })
            ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
    });

    it('throws timeout exception for a fetch over the call timeout', function (): void {
        $connection = $this->connect(...$this->ownPool() + ['maxConnections' => 2]);
        $cursor = $connection->execute(
            'select session_slow(level, case when level > 2 then 1 else 0 end) n from dual connect by level <= 4',
            [],
            $this->options(fetchSize: 1, timeout: 0.5),
        )->cursor();
        assert($cursor !== null);
        $cursor->fetchBatch();
        $cursor->fetchBatch();

        expect(fn () => $cursor->fetchBatch())->toThrow(TimeoutException::class);
    });

    it('completes a read whose round trips each stay under the call timeout', function (): void {
        $connection = $this->connect();
        $started = microtime(true);

        $rows = $this->rows(
            $connection,
            'select session_slow(level, 0.3) n from dual connect by level <= 6',
            [],
            $this->options(fetchSize: 1, timeout: 1),
        );

        expect($rows)->toBe([['N' => 1], ['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5], ['N' => 6]])
            ->and(microtime(true) - $started)->toBeGreaterThan(1.5);
    });

    it('breaks the connection when it runs out during a server sleep', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$fields);
        $session = $this->session($connection);

        $sleep = fn (): mixed => $connection->execute('begin dbms_session.sleep(3); end;', [], $this->options(timeout: 0.5));

        expect($sleep)->toThrow(function (TimeoutException $timeout) use ($connection): void {
            expect($timeout->getMessage())->toStartWith('Call timeout of 500 ms exceeded')
                ->and(fn () => $connection->execute('select 1 from dual', [], $this->options()))
                ->toThrow(ConnectionLostException::class, 'Connection is broken: ' . $timeout->getMessage());
        });
        $connection->close();
        expect($this->session($this->connect(...$fields)))->not->toBe($session);
    });
});

describe('killed sessions', function (): void {
    it('turns tracing and the call timeout into connection lost', function (): void {
        $connection = $this->connect(...$this->ownPool() + ['maxConnections' => 2]);
        kill($this->sid($connection));
        $connection->trace(new Tracing(module: 'after the kill'));
        $started = microtime(true);

        $select = fn (): mixed => $connection->execute('select 1 from dual', [], $this->options(timeout: 30));

        expect($select)->toThrow(function (ConnectionLostException $exception) use ($started): void {
            expect(microtime(true) - $started)->toBeLessThan(5);
        })
            ->and(fn () => $connection->trace(new Tracing(module: 'broken')))
            ->toThrow(ConnectionLostException::class, 'Connection is broken: ');
    });
});

describe('the statement cache', function (): void {
    it('parses a repeated statement once unless the cache is off', function (int $size, int $parses): void {
        $connection = $this->connect(...$this->ownPool() + ['statementCacheSize' => $size]);
        $sid = $this->sid($connection);
        $sql = 'select :n * 2 doubled from dual';

        $parsed = parses(otherSession(), $sid, function () use ($connection, $sql): void {
            foreach ([1, 2, 3] as $n) {
                $this->rows($connection, $sql, ['n' => $n]);
            }
        });

        expect($parsed)->toBe($parses);
    })->with(['the default Statement cache' => [20, 1], 'statementCacheSize: 0' => [0, 3]]);
});

/**
 * How many parses the Session with this SID asked for while $action ran.
 */
function parses(Connection $otherSession, int $sid, callable $action): int
{
    $read = static function () use ($otherSession, $sid): int {
        $value = $otherSession->select(
            "select s.value from v\$sesstat s join v\$statname n on n.statistic# = s.statistic# where n.name = 'parse count (total)' and s.sid = :sid",
            ['sid' => $sid],
        )->value();
        assert(\is_int($value));

        return $value;
    };
    $before = $read();
    $action();

    return $read() - $before;
}

/**
 * @return array<string, mixed>
 */
function tracing(Connection $otherSession, int $sid): array
{
    $row = $otherSession->select(
        'select module, action, client_identifier, client_info from v$session where sid = :sid',
        ['sid' => $sid],
    )->first();
    assert(\is_array($row));

    /** @var array<string, mixed> $row */
    return $row;
}
