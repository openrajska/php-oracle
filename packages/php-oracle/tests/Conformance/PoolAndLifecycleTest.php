<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\PoolAndLifecycleTest;

use Closure;
use Rajska\Oracle\Driver\DriverArrayDml;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Conformance\ConformanceTestCase;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tracing;
use Rajska\Oracle\ValueMapping;

test('two connects give two sessions that do not see each others uncommitted changes', function (): void {
    $first = $this->connect();
    $second = $this->connect();

    expect(self::sid($second))->not->toBe(self::sid($first));

    $first->execute(
        "update fx_items set name = 'changed by the first' where id = 1",
        [],
        self::options(autocommit: false),
    );

    expect(self::value($first, 'select name from fx_items where id = 1'))->toBe('changed by the first')
        ->and(self::value($second, 'select name from fx_items where id = 1'))->toBe('Item 0001');
});
test('commit makes the work visible to another session', function (): void {
    $connection = $this->connect();
    $observer = IndependentSession::open();

    try {
        $connection->execute(
            "insert into fx_batch_rows (id, code, qty) values (35001, 'commit', 1)",
            [],
            self::options(autocommit: false),
        );
        $connection->commit();

        expect($observer->select('select count(*) as n from fx_batch_rows where id = 35001'))->toBe([['N' => '1']]);
    } finally {
        $observer->execute('delete from fx_batch_rows where id = 35001');
        $observer->commit();
        $observer->close();
    }
});
test('rollback undoes the work', function (): void {
    $connection = $this->connect();
    $connection->execute(
        "insert into fx_batch_rows (id, code, qty) values (35002, 'rollback', 1)",
        [],
        self::options(autocommit: false),
    );

    $connection->rollback();

    expect(self::value($connection, 'select count(*) from fx_batch_rows where id = 35002'))->toBe(0);
});
test('the server version has five parts', function (): void {
    expect($this->connect()->serverVersion())->toMatch('/^\d+\.\d+\.\d+\.\d+\.\d+$/');
});
test('a standalone connection starts clean and logs off', function (): void {
    $fields = self::ownPool() + ['standalone' => true];
    $first = $this->connect(...$fields);
    expect(self::value($first, 'select fx_pool.bump_counter from dual'))->toBe(1)
        ->and(self::value($first, 'select fx_pool.bump_counter from dual'))->toBe(2);

    $session = self::value($first, "select sys_context('userenv', 'sessionid') from dual");
    expect($session)->toBeString();

    $first->close();

    $observer = IndependentSession::open();
    try {
        expect(eventually(static fn(): bool => $observer->select(
            'select count(*) as n from v$session where audsid = :audsid',
            ['audsid' => $session],
        ) === [['N' => '0']]))->toBeTrue('The standalone Session logged off.');
    } finally {
        $observer->close();
    }
    $second = $this->connect(...$fields);
    expect(self::value($second, "select sys_context('userenv', 'sessionid') from dual"))->not->toBe($session)
        ->and(self::value($second, 'select fx_pool.bump_counter from dual'))->toBe(1);
});
test('standalone connections are not held to max connections', function (): void {
    $fields = self::ownPool() + ['standalone' => true, 'maxConnections' => 1, 'acquireTimeout' => 0.25];
    $first = $this->connect(...$fields);
    $second = $this->connect(...$fields);

    expect(self::sid($second))->not->toBe(self::sid($first));
});
test('at max connections a connect fails as pool exhausted after acquire timeout and succeeds after a release', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
    $held = $this->connect(...$fields);
    $sid = self::sid($held);

    $started = hrtime(true);
    try {
        $this->connect(...$fields);
        self::fail('A second Session came out of a Pool of one.');
    } catch (PoolExhaustedException) {
        $waited = (hrtime(true) - $started) / 1e9;
        expect($waited)->toBeGreaterThanOrEqual(0.25)
            ->toBeLessThan(1.25);
    }

    $held->close();

    expect(self::sid($this->connect(...$fields)))->toBe($sid);
});
test('the pool grows one session at a time up to max connections', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 3, 'acquireTimeout' => 0.25];
    $sids = [];
    for ($i = 0; $i < 3; ++$i) {
        $sids[] = self::sid($this->connect(...$fields));
    }

    expect(array_unique($sids))->toHaveCount(3)
        ->and(fn() => $this->connect(...$fields))->toThrow(PoolExhaustedException::class);
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
test('configs differing only outside the pool key share one pool', function (array $difference): void {
    $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
    $held = $this->connect(...$fields);
    $sid = self::sid($held);

    try {
        $this->connect(...$difference, ...$fields);
        self::fail('The config got a Pool of its own.');
    } catch (PoolExhaustedException) {
        $this->addToAssertionCount(1);
    }

    $held->close();

    expect(self::sid($this->connect(...$difference, ...$fields)))->toBe($sid);
})->with('fieldsOutsideThePoolKey');
test('a different statement cache size or password gives another pool', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
    $held = $this->connect(...$fields);

    expect(self::sid($this->connect(...['statementCacheSize' => 7] + $fields)))->not->toBe(self::sid($held));

    $held->close();
    try {
        // The first Pool's Session is free now: a shared Pool would hand
        // it over, while a Pool of its own logs on and fails.
        $this->connect(...['password' => 'not-the-password'] + $fields);
        self::fail('A wrong password got a Session.');
    } catch (ConnectionException $connectionException) {
        expect($connectionException::class)->toBe(ConnectionException::class)
            ->and($connectionException->getCode())->toBe(1017);
    }
});
test('connections held at once never share a session', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 2];
    $first = $this->connect(...$fields);
    $second = $this->connect(...$fields);

    expect(self::sid($second))->not->toBe(self::sid($first));
});
test('a session idle for 60 seconds is pinged on acquire and dropped if the ping fails', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 2];
    $live = $this->connect(...$fields);
    $killed = $this->connect(...$fields);
    $liveSid = self::sid($live);
    $killedSid = self::sid($killed);
    $live->close();
    $killed->close();

    $observer = IndependentSession::open();
    try {
        $serial = $observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $killedSid])[0]['SERIAL'];
        $observer->execute("alter system kill session '$killedSid,$serial' immediate");
    } finally {
        $observer->close();
    }

    sleep(61);

    $sids = [self::sid($this->connect(...$fields)), self::sid($this->connect(...$fields))];
    expect($sids)->toContain($liveSid);
})->group('slow');
/**
 * @return iterable<string, array{string, bool}>
 */
dataset('releases', function () {
    yield 'destroying the Connection' => ['destroy', false];
    yield 'close()' => ['close', false];
    yield 'a Standalone Connection' => ['close', true];
});
test('a release rolls back and never commits', function (string $release, bool $standalone): void {
    $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config(...self::ownPool(), standalone: $standalone));
    $connection->execute("update fx_items set name = 'uncommitted' where id = 41", [], self::options(autocommit: false));

    expect(self::locked(41))->toBeTrue('The update holds the row.');

    if ($release === 'destroy') {
        unset($connection);
    } else {
        $connection->close();
    }

    expect(self::locked(41))->toBeFalse('The Release rolled back.');
    $observer = IndependentSession::open();
    try {
        expect($observer->select('select name from fx_items where id = 41'))->toBe([['NAME' => 'Item 0041']]);
    } finally {
        $observer->close();
    }
})->with('releases');
/**
 * @return iterable<string, array{Closure(DriverConnection): mixed}>
 */
dataset('connectionCalls', function () {
    yield 'execute()' => [static fn(DriverConnection $c): DriverExecution => $c->execute('select 1 from dual', [], ConformanceTestCase::options())];
    yield 'executeMany()' => [static fn(DriverConnection $c): DriverArrayDml => $c->executeMany('insert into fx_batch_rows (id) values (:id)', [['id' => 1]], ConformanceTestCase::options())];
    yield 'commit()' => [static fn(DriverConnection $c) => $c->commit()];
    yield 'rollback()' => [static fn(DriverConnection $c) => $c->rollback()];
    yield 'trace()' => [static fn(DriverConnection $c) => $c->trace(new Tracing(module: 'after close'))];
    yield 'serverVersion()' => [static fn(DriverConnection $c): string => $c->serverVersion()];
});
test('every call after close fails as usage', function (Closure $call): void {
    $connection = $this->connect();
    $connection->close();

    expect(function () use ($call, $connection): void {
        $call($connection);
        self::fail('A call after close() succeeded.');
    })->toThrow(UsageException::class, 'The Connection is closed.');
    $connection->close();
})->with('connectionCalls');
test('a live cursor delays the release and freeing it then rolls back', function (): void {
    $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config());
    $connection->execute("update fx_items set name = 'uncommitted' where id = 42", [], self::options(autocommit: false));

    $cursor = $connection->execute('select level as n from dual connect by level <= 5', [], self::options(fetchSize: 2, autocommit: false))->cursor();
    expect($cursor)->not->toBeNull()
        ->and($cursor->fetchBatch())->toBe([['N' => 1], ['N' => 2]]);

    unset($connection);

    expect(self::locked(42))->toBeTrue('The Cursor keeps the Connection, and its transaction.')
        ->and($cursor->fetchBatch())->toBe([['N' => 3], ['N' => 4]])
        ->and($cursor->fetchBatch())->toBe([['N' => 5]])
        ->and($cursor->fetchBatch())->toBe([]);

    unset($cursor);

    expect(self::locked(42))->toBeFalse('Freeing the last Cursor released the Connection, rolling back.');
});
test('package state survives a pooled release', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $first = $this->connect(...$fields);
    $session = self::session($first);
    expect(self::value($first, 'select fx_pool.bump_counter from dual'))->toBe(1);
    $first->close();

    $second = $this->connect(...$fields);

    expect(self::session($second))->toBe($session)
        ->and(self::value($second, 'select fx_pool.bump_counter from dual'))->toBe(2);
});
/**
 * @return iterable<string, array{bool}>
 */
dataset('kills', function () {
    yield 'kill immediate' => [true];
    yield 'kill' => [false];
});
test('a killed session makes the connection broken and never comes back', function (bool $immediate): void {
    $fields = self::ownPool() + ['maxConnections' => 1];

    // The Session's second loan: the crate ends the first one with a
    // request-boundary rollback of its own, which would hide a Session
    // going back to the Pool.
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $session = self::session($connection);
    $cursor = $connection->execute('select level as n from dual connect by level <= 5', [], self::options(fetchSize: 2))->cursor();
    expect($cursor)->not->toBeNull();
    $cursor->fetchBatch();
    self::kill($connection, $immediate);

    try {
        self::value($connection, 'select 1 from dual');
        self::fail('A call on a killed Session succeeded.');
    } catch (ConnectionLostException $connectionLostException) {
        $this->assertStringStartsNotWith('Connection is broken: ', $connectionLostException->getMessage());
    }
    foreach ([
        'execute()' => static fn(): DriverExecution => $connection->execute('select 1 from dual', [], self::options()),
        'commit()' => $connection->commit(...),
        'rollback()' => $connection->rollback(...),
        'serverVersion()' => $connection->serverVersion(...),
        'fetchBatch()' => $cursor->fetchBatch(...),
        'columns()' => $cursor->columns(...),
    ] as $what => $call) {
        try {
            $call();
            self::fail("$what on a Broken Connection succeeded.");
        } catch (ConnectionLostException $later) {
            expect($later->getCode())->toBe($connectionLostException->getCode(), $what)
                ->and($later->getMessage())->toBe('Connection is broken: ' . $connectionLostException->getMessage(), $what);
        }
    }

    $cursor->close();
    $connection->close();

    $next = $this->connect(...$fields);
    expect(self::session($next))->not->toBe($session);
})->with('kills');
/**
 * @param callable(): bool $condition
 */
function eventually(callable $condition, float $seconds = 5): bool
{
    $deadline = microtime(true) + $seconds;
    while (!$condition()) {
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(50_000);
    }

    return true;
}
