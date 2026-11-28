<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Connection\PoolTest;

use Closure;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\ArrayDmlFailedException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;
use Rajska\Oracle\Tracing;

uses(TestCase::class);

const INVALID_UNIT = 'create or replace procedure invalid_unit is begin no_such_thing; end;';

beforeEach(function (): void {
    schema()->run(<<<'SQL'
        create package pool_counter as
            function bump return number;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body pool_counter as
            g_counter number := 0;

            function bump return number is
            begin
                g_counter := g_counter + 1;
                return g_counter;
            end;
        end;
        SQL);
    schema()->run('create table pool_rows (id number(10) primary key, note varchar2(100))');

    schema()->run('insert into pool_rows (id) values (1)');
});

/**
 * @return iterable<string, array{array<string, mixed>}>
 */
dataset('fieldsOutsideThePoolKey', function () {
    yield 'values' => [['values' => new ValueMapping(numbers: NumberMode::Float)]];
    yield 'rowShape' => [['rowShape' => RowShape::List]];
    yield 'keyCase' => [['keyCase' => KeyCase::Lower]];
    yield 'tracing' => [['tracing' => new Tracing(module: 'another module')]];
    yield 'callTimeout' => [['callTimeout' => 30]];
    yield 'fetchSize' => [['fetchSize' => 7]];
});

/**
 * What the last calls before a Release left: never a transaction.
 *
 * @return iterable<string, array{Closure(Session): mixed}>
 */
dataset('nothingOpen', function () {
    $autocommit = TestCase::options();
    yield 'no call' => [static fn (Session $c): null => null];
    yield 'a query' => [static fn (Session $c): array => TestCase::rows($c, 'select id from pool_rows')];
    yield 'a DML under Autocommit' => [static fn (Session $c): mixed => $c->execute('insert into pool_rows (id) values (2)', [], $autocommit)];
    yield 'a block under Autocommit' => [static fn (Session $c): mixed => $c->execute("begin update pool_rows set note = 'block' where id = 1; end;", [], $autocommit)];
    yield 'SELECT … FOR UPDATE under Autocommit' => [static fn (Session $c): array => TestCase::rows($c, 'select id from pool_rows for update')];
    yield 'commit()' => [static function (Session $c): void {
        $c->execute('insert into pool_rows (id) values (2)', [], TestCase::options(autocommit: false));
        $c->commit();
    }];
    yield 'rollback()' => [static function (Session $c): void {
        $c->execute('insert into pool_rows (id) values (2)', [], TestCase::options(autocommit: false));
        $c->rollback();
    }];
});

/**
 * Work the server keeps open after the call, with Autocommit off: each locks
 * pool_rows row 1 or holds the transaction some other way.
 *
 * @return iterable<string, array{Closure(Session): mixed}>
 */
dataset('leftOpen', function () {
    $open = TestCase::options(autocommit: false);
    $lock = static fn (Session $c): mixed => $c->execute("update pool_rows set note = 'open' where id = 1", [], $open);

    yield 'DML' => [$lock];
    yield 'DML in a PL/SQL block' => [static fn (Session $c): mixed => $c->execute(
        "begin update pool_rows set note = 'open' where id = 1; insert into pool_rows (id) values (2); end;",
        [],
        $open,
    )];
    yield 'SELECT … FOR UPDATE' => [static fn (Session $c): array => TestCase::rows($c, 'select id from pool_rows where id = 1 for update', [], $open)];
    yield 'LOCK TABLE' => [static fn (Session $c): mixed => $c->execute('lock table pool_rows in exclusive mode', [], $open)];
    yield 'SET TRANSACTION READ ONLY' => [static fn (Session $c): mixed => $c->execute('set transaction read only', [], $open)];
    yield 'runMany()' => [static fn (Session $c): mixed => $c->executeMany('insert into pool_rows (id) values (:id)', [['id' => 2], ['id' => 3]], $open)];
    yield 'runMany() with a failing row, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        expect(static fn (): mixed => $c->executeMany('insert into pool_rows (id) values (:id)', [['id' => 2], ['id' => 1]], $open))->toThrow(ArrayDmlFailedException::class);
    }];
    yield 'a failed execute, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        expect(static fn (): mixed => $c->execute('insert into pool_rows (id) values (1)', [], $open))->toThrow(UniqueViolationException::class);
    }];
    yield 'a query, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        TestCase::rows($c, 'select id from pool_rows', [], $open);
    }];
    yield 'DBMS_OUTPUT calls, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        $c->execute("begin dbms_output.enable(null); dbms_output.put_line('line'); end;", [], $open);
        $c->execute('begin dbms_output.disable; end;', [], $open);
    }];
    yield 'a DML with a LOB bind' => [static fn (Session $c): mixed => $c->execute(
        'update pool_rows set note = dbms_lob.substr(:note, 10) where id = 1',
        ['note' => new In\Clob(str_repeat('x', 40_000))],
        $open,
    )];
    yield 'a LOB write, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        TestCase::rows($c, 'select dbms_lob.getlength(:text) as n from dual', ['text' => new In\Clob(str_repeat('x', 40_000))], $open);
    }];
});

/**
 * Each way a Connection goes: the Release.
 *
 * @return iterable<string, array{bool, Closure(Session|null): void}>
 */
dataset('releases', function () {
    yield 'close()' => [false, static fn (Session | null &$c) => $c?->close()];
    yield 'destroying the Connection' => [false, static function (Session | null &$c): void {
        $c = null;
    }];
    yield 'close() of a standalone Connection' => [true, static fn (Session | null &$c) => $c?->close()];
});

describe('standalone connections', function (): void {
    it('starts clean and logs off', function (): void {
        $fields = ['standalone' => true] + $this->ownPool();
        $first = $this->connect(...$fields);
        $this->value($first, 'select pool_counter.bump from dual');
        $session = $this->session($first);

        $first->close();

        $second = $this->connect(...$fields);
        expect(eventually(fn (): bool => otherSession()->select('select count(*) from v$session where audsid = :audsid', ['audsid' => $session])->value() === 0))->toBeTrue()
            ->and($this->session($second))->not->toBe($session)
            ->and($this->value($second, 'select pool_counter.bump from dual'))->toBe(1);
    });

    it('is not held to max connections', function (): void {
        $fields = ['standalone' => true, 'maxConnections' => 1, 'acquireTimeout' => 0.25] + $this->ownPool();
        $first = $this->connect(...$fields);

        $second = $this->connect(...$fields);

        expect($this->sid($second))->not->toBe($this->sid($first));
    });
});

describe('pool size', function (): void {
    it('makes a connect wait the acquire timeout and fail when every session is held', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 3, 'acquireTimeout' => 0.25];
        $sids = array_map(fn (): int => $this->sid($this->connect(...$fields)), range(1, 3));
        $started = hrtime(true);

        $connect = fn (): Session => $this->connect(...$fields);

        expect(array_unique($sids))->toHaveCount(3)
            ->and($connect)->toThrow(function (PoolExhaustedException $exception) use ($started): void {
                expect($exception->getCode())->toBe(0)
                    ->and($exception->getMessage())->toContain('acquireTimeout')
                    ->and($exception->sql())->toBeNull()
                    ->and((hrtime(true) - $started) / 1e9)->toBeGreaterThanOrEqual(0.25)->toBeLessThan(1.25);
            });
    });

    it('gives a released session to the next connect', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $held = $this->connect(...$fields);
        $sid = $this->sid($held);

        $held->close();

        expect($this->sid($this->connect(...$fields)))->toBe($sid);
    });

    it('never shares a session between connections held at once', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 2];
        $first = $this->connect(...$fields);

        $second = $this->connect(...$fields);

        expect($this->sid($second))->not->toBe($this->sid($first));
    });
});

describe('pool keys', function (): void {
    it('shares one pool between configs that differ only outside the pool key', function (array $difference): void {
        $fields = $this->ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $held = $this->connect(...$fields);
        $sid = $this->sid($held);
        expect(fn (): Session => $this->connect(...$difference, ...$fields))->toThrow(PoolExhaustedException::class);
        $held->close();

        $next = $this->connect(...$difference, ...$fields);

        expect($this->sid($next))->toBe($sid);
    })->with('fieldsOutsideThePoolKey');

    it('gives another pool for a different statement cache size', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $held = $this->connect(...$fields);

        $other = $this->connect(...['statementCacheSize' => 7] + $fields);

        expect($this->sid($other))->not->toBe($this->sid($held));
    });

    it('gives another pool for a different password', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $this->connect(...$fields)->close();

        // The first Pool's Session is free: a shared Pool would hand it over,
        // while a Pool of its own logs on and fails.
        $connect = fn (): Session => $this->connect(...['password' => 'not-the-password'] + $fields);

        expect($connect)->toThrow(function (ConnectionException $exception): void {
            expect($exception->getCode())->toBe(1017);
        });
    });
});

describe('idle sessions', function (): void {
    it('pings a session idle for 60 seconds on acquire and drops it if the ping fails', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 2];
        $live = $this->connect(...$fields);
        $killed = $this->connect(...$fields);
        $liveSid = $this->sid($live);
        kill($this->sid($killed));
        $live->close();
        $killed->close();
        sleep(61);

        $sids = [$this->sid($this->connect(...$fields)), $this->sid($this->connect(...$fields))];

        expect($sids)->toContain($liveSid);
    })->group('slow');
});

describe('release', function (): void {
    it('makes no round trip when no transaction is open', function (Closure $work): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $sid = $this->sid($connection);
        $work($connection);

        $trips = roundTrips($sid, $connection->close(...));

        expect($trips)->toBe(0);
    })->with('nothingOpen');

    it('rolls back whatever the server keeps open and never commits', function (Closure $work, bool $standalone, Closure $release): void {
        $fields = ['maxConnections' => 1, 'standalone' => $standalone] + $this->ownPool();
        $this->connect(...$fields)->close();
        $connection = $this->driver()->connect($this->config(...$fields));
        $work($connection);

        $release($connection);

        expect(locked('pool_rows', 1))->toBeFalse()
            ->and(otherSession()->select('select id, note from pool_rows order by id')->all())->toBe([['ID' => 1, 'NOTE' => null]]);
    })->with('leftOpen')->with('releases');

    it('waits for a live cursor and rolls back once it is freed', function (): void {
        $connection = $this->driver()->connect($this->config());
        $connection->execute("update pool_rows set note = 'uncommitted' where id = 1", [], $this->options(autocommit: false));

        $cursor = $connection->execute('select level as n from dual connect by level <= 5', [], $this->options(fetchSize: 2, autocommit: false))->cursor();
        assert($cursor !== null);

        unset($connection);
        $lockedWhileTheCursorLives = locked('pool_rows', 1);
        $rest = [...$cursor->fetchBatch(), ...$cursor->fetchBatch(), ...$cursor->fetchBatch()];
        unset($cursor);

        expect($lockedWhileTheCursorLives)->toBeTrue()
            ->and($rest)->toBe([['N' => 1], ['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5]])
            ->and(locked('pool_rows', 1))->toBeFalse();
    });

    it('keeps package state', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $first = $this->connect(...$fields);
        $session = $this->session($first);
        $this->value($first, 'select pool_counter.bump from dual');
        $first->close();

        $second = $this->connect(...$fields);

        expect($this->session($second))->toBe($session)
            ->and($this->value($second, 'select pool_counter.bump from dual'))->toBe(2);
    });

    it('never hands a warning to the next connection', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $first = $this->connect(...$fields);
        $session = $this->session($first);
        $first->execute(INVALID_UNIT, [], $this->options());
        $first->close();

        $second = $this->connect(...$fields);

        expect($this->session($second))->toBe($session)
            ->and($second->execute('select 1 from dual', [], $this->options())->warning())->toBeNull()
            ->and($second->execute('begin null; end;', [], $this->options())->warning())->toBeNull();
    });
});

describe('broken sessions', function (): void {
    it('marks the connection broken and never returns a killed session', function (bool $immediate): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        // The Session's second loan: the crate ends the first one with a
        // request-boundary rollback of its own, which would hide a Session
        // going back to the Pool.
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $session = $this->session($connection);
        $cursor = $connection->execute('select level as n from dual connect by level <= 5', [], $this->options(fetchSize: 2))->cursor();
        assert($cursor !== null);
        kill($this->sid($connection), $immediate);
        $later = [
            'execute()' => fn (): mixed => $connection->execute('select 1 from dual', [], $this->options()),
            'commit()' => $connection->commit(...),
            'rollback()' => $connection->rollback(...),
            'serverVersion()' => $connection->serverVersion(...),
            'fetchBatch()' => $cursor->fetchBatch(...),
            'columns()' => $cursor->columns(...),
        ];

        $first = fn (): mixed => $this->value($connection, 'select 1 from dual');

        expect($first)->toThrow(function (ConnectionLostException $lost) use ($later): void {
            expect($lost->getMessage())->not->toStartWith('Connection is broken: ')
                ->and($later)->each->toThrow(ConnectionLostException::class, 'Connection is broken: ' . $lost->getMessage());
        });
        $cursor->close();
        $connection->close();
        expect($this->session($this->connect(...$fields)))->not->toBe($session);
    })->with(['kill immediate' => [true], 'kill' => [false]]);

    it('releases a session dead after the last call without a throw and does not return it', function (bool $destroy): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $connection = $this->driver()->connect($this->config(...$fields));
        $session = $this->session($connection);
        $connection->execute("update pool_rows set note = 'uncommitted' where id = 1", [], $this->options(autocommit: false));
        kill($this->sid($connection));

        if ($destroy) {
            unset($connection);
        } else {
            $connection->close();
        }

        expect($this->session($this->connect(...$fields)))->not->toBe($session);
    })->with(['close()' => [false], 'the destructor' => [true]]);

    it('turns an injected panic into connection lost and marks the connection broken', function (): void {
        $fields = $this->ownPool() + ['maxConnections' => 1];
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $session = $this->session($connection);

        // The panic hook exists in debug builds of the Extension, the ones
        // the suites run on.
        $panic = fn (): mixed => $connection->execute('/* oracledb: panic */ select 1 from dual', [], $this->options());

        expect($panic)->toThrow(function (ConnectionLostException $panic) use ($connection): void {
            expect($panic->getCode())->toBe(0)
                ->and($panic->getMessage())->toStartWith('internal error in oracledb: ')
                ->and(fn () => $connection->execute('select 1 from dual', [], $this->options()))
                ->toThrow(ConnectionLostException::class, 'Connection is broken: ' . $panic->getMessage());
        });
        $connection->close();
        for ($i = 0; $i < 3; ++$i) {
            $next = $this->connect(...$fields);
            expect($this->session($next))->not->toBe($session);
            $next->close();
        }
    });
});

/**
 * @param callable(): bool $condition
 */
function eventually(callable $condition, float $seconds = 5): bool
{
    $deadline = microtime(true) + $seconds;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(50_000);
    }

    return true;
}
