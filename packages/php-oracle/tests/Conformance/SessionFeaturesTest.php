<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\SessionFeaturesTest;

use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tracing;

beforeEach(function (): void {
    $this->counter = RoundTripCounter::open();
    $this->observer = IndependentSession::open();
});
afterEach(function (): void {
    $this->counter->close();
    $this->observer->close();
});
test('config tracing shows in v session after the first round trip', function (): void {
    $connection = $this->connect(tracing: new Tracing(
        module: 'billing',
        action: 'close month',
        clientIdentifier: 'user-42',
        clientInfo: 'worker 7',
    ));
    $sid = self::sid($connection);

    expect(tracing($this->observer, $sid))->toBe([
        'MODULE' => 'billing',
        'ACTION' => 'close month',
        'CLIENT_IDENTIFIER' => 'user-42',
        'CLIENT_INFO' => 'worker 7',
    ]);
});
test('the default fetch size reads up to 100 rows in one round trip', function (): void {
    $connection = $this->connect();
    $sid = self::sid($connection);

    foreach ([1, 99, 100] as $count) {
        $rows = [];
        expect($this->counter->during($sid, function () use ($connection, $count, &$rows): void {
            $rows = readAll($connection, $count, self::options());
        }))->toBe(1, "$count rows")
            ->and(array_map(count(...), $rows))->toBe([$count], "$count rows");
    }
});
test('a fetch size of 10 reads 25 rows in 3 round trips', function (): void {
    $rows = 'select level n from dual connect by level <= :n';

    $connection = $this->connect();
    $sid = self::sid($connection);
    $cursor = null;
    $trips = [];

    $trips[] = $this->counter->during($sid, function () use ($connection, $rows, &$cursor): void {
        $cursor = $connection->execute($rows, ['n' => 25], self::options(fetchSize: 10))->cursor();
    });
    expect($cursor)->not->toBeNull();
    $sizes = [];
    for ($i = 0; $i < 5; ++$i) {
        $trips[] = $this->counter->during($sid, function () use ($cursor, &$sizes): void {
            $sizes[] = \count($cursor->fetchBatch());
        });
    }

    expect($sizes)->toBe([10, 10, 5, 0, 0])
        ->and(array_sum($trips))->toBe(3)
        ->and(\array_slice($trips, 4))->toBe([0, 0], 'no Round trip at the end')
        ->and(max($trips))->toBeLessThanOrEqual(1);
});
test('the fetch size of an execute governs its cursor', function (): void {
    $rows = 'select level n from dual connect by level <= :n';

    $connection = $this->connect();
    $small = $connection->execute($rows, ['n' => 25], self::options(fetchSize: 7))->cursor();
    $large = $connection->execute($rows, ['n' => 25], self::options(fetchSize: 20))->cursor();
    expect($small)->not->toBeNull()
        ->and($large)->not->toBeNull()
        ->and(\count($small->fetchBatch()))->toBe(7)
        ->and(\count($large->fetchBatch()))->toBe(20)
        ->and(\count($small->fetchBatch()))->toBe(7);
});
test('trace makes no round trip and replaces the whole set', function (): void {
    $connection = $this->connect(tracing: new Tracing(
        module: 'billing',
        action: 'close month',
        clientIdentifier: 'user-42',
        clientInfo: 'worker 7',
    ));
    $sid = self::sid($connection);

    expect($this->counter->during($sid, static function () use ($connection): void {
        $connection->trace(new Tracing(module: 'reports', clientInfo: 'worker 8'));
    }))->toBe(0)
        ->and(tracing($this->observer, $sid)['MODULE'])->toBe('billing', 'still pending');

    self::sid($connection);
    expect(tracing($this->observer, $sid))->toBe([
        'MODULE' => 'reports',
        'ACTION' => null,
        'CLIENT_IDENTIFIER' => null,
        'CLIENT_INFO' => 'worker 8',
    ]);
});
/**
 * @return iterable<string, array{bool}>
 */
dataset('sentOrPending', function () {
    yield 'sent' => [true];
    yield 'still pending' => [false];
});
test('tracing set by one connection is empty for the next on the same session', function (bool $sent): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $first = $this->connect(...$fields);
    $sid = self::sid($first);
    $first->trace(new Tracing(module: 'A', action: 'A', clientIdentifier: 'A', clientInfo: 'A'));
    if ($sent) {
        self::sid($first);
    }
    $first->close();

    $second = $this->connect(...$fields);

    expect(self::rows($second, <<<'SQL'
                select sys_context('userenv', 'sid') sid,
                       sys_context('userenv', 'module') module,
                       sys_context('userenv', 'action') action,
                       sys_context('userenv', 'client_identifier') client_identifier,
                       sys_context('userenv', 'client_info') client_info
                  from dual
                SQL)[0])->toBe(['SID' => (string) $sid, 'MODULE' => null, 'ACTION' => null, 'CLIENT_IDENTIFIER' => null, 'CLIENT_INFO' => null]);
})->with('sentOrPending');
test('a long read whose round trips are each under the call timeout completes', function (): void {
    $connection = $this->connect();
    $started = microtime(true);

    $rows = self::rows(
        $connection,
        'select fx_pool.slow(level, 0.3) n from dual connect by level <= 6',
        [],
        self::options(fetchSize: 1, timeout: 1),
    );

    expect($rows)->toBe([['N' => 1], ['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5], ['N' => 6]])
        ->and(microtime(true) - $started)->toBeGreaterThan(1.5, 'The read as a whole outlasted the Call timeout.');
});
test('a fetch batch over the call timeout throws timeout exception', function (): void {
    $connection = $this->connect(...self::ownPool() + ['maxConnections' => 2]);
    $cursor = $connection->execute(
        'select fx_pool.slow(level, case when level > 2 then 1 else 0 end) n from dual connect by level <= 4',
        [],
        self::options(fetchSize: 1, timeout: 0.5),
    )->cursor();
    expect($cursor)->not->toBeNull()
        ->and($cursor->fetchBatch())->toBe([['N' => 1]])
        ->and($cursor->fetchBatch())->toBe([['N' => 2]])
        ->and(fn() => $cursor->fetchBatch())->toThrow(TimeoutException::class);
});
test('on a killed session tracing and the call timeout give way to connection lost', function (): void {
    $connection = $this->connect(...self::ownPool() + ['maxConnections' => 2]);
    self::kill($connection);

    $connection->trace(new Tracing(module: 'after the kill'));
    $started = microtime(true);
    try {
        $connection->execute('select 1 from dual', [], self::options(timeout: 30));
        self::fail('A killed Session ran a statement.');
    } catch (ConnectionLostException) {
        expect(microtime(true) - $started)->toBeLessThan(5, 'No wait for the Call timeout.');
    }

    $this->expectException(ConnectionLostException::class);
    $this->expectExceptionMessageMatches('/^Connection is broken: /');
    $connection->trace(new Tracing(module: 'broken'));
});
/**
 * @return iterable<string, array{int, int}>
 */
dataset('statementCacheSizes', function () {
    yield 'the default Statement cache' => [20, 1];
    yield 'statementCacheSize: 0' => [0, 3];
});
test('a repeated statement parses once unless the statement cache is off', function (int $size, int $parses): void {
    $connection = $this->connect(...self::ownPool() + ['statementCacheSize' => $size]);
    $sid = self::sid($connection);
    $sql = 'select :n * 2 doubled from dual';

    expect(statistic($this->observer, $sid, 'parse count (total)', static function () use ($connection, $sql): void {
        foreach ([1, 2, 3] as $n) {
            self::assertSame([['DOUBLED' => 2 * $n]], self::rows($connection, $sql, ['n' => $n]));
        }
    }))->toBe($parses);
})->with('statementCacheSizes');
/**
 * How much a statistic of V$SESSTAT grew for a Session while $action ran.
 */
function statistic(IndependentSession $observer, int $sid, string $name, callable $action): int
{
    $read = fn(): int => (int) $observer->select(
        'select s.value from v$sesstat s join v$statname n on n.statistic# = s.statistic# where n.name = :name and s.sid = :sid',
        ['name' => $name, 'sid' => $sid],
    )[0]['VALUE'];
    $before = $read();
    $action();

    return $read() - $before;
}
/**
 * @return array<string, string|null>
 */
function tracing(IndependentSession $observer, int $sid): array
{
    return $observer->select(
        'select module, action, client_identifier, client_info from v$session where sid = :sid',
        ['sid' => $sid],
    )[0];
}
/**
 * @return list<list<array<int|string, mixed>>> the batches
 */
function readAll(DriverConnection $connection, int $count, DriverOptions $options): array
{
    $rows = 'select level n from dual connect by level <= :n';

    $cursor = $connection->execute($rows, ['n' => $count], $options)->cursor();
    expect($cursor)->not->toBeNull();
    $batches = [];
    while (($batch = $cursor->fetchBatch()) !== []) {
        $batches[] = $batch;
    }
    $cursor->close();

    return $batches;
}
