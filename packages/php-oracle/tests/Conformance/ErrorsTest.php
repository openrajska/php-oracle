<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ErrorsTest;

use LogicException;
use Rajska\Oracle\Exceptions\ArrayDmlException;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\TestDatabase;
use RuntimeException;

/**
 * @return iterable<string, array{class-string, class-string}>
 */
dataset('hierarchy', function () {
    yield 'QueryException' => [QueryException::class, RuntimeException::class];
    yield 'UniqueViolationException' => [UniqueViolationException::class, QueryException::class];
    yield 'ArrayDmlException' => [ArrayDmlException::class, QueryException::class];
    yield 'ConnectionException' => [ConnectionException::class, RuntimeException::class];
    yield 'PoolExhaustedException' => [PoolExhaustedException::class, ConnectionException::class];
    yield 'ConnectionLostException' => [ConnectionLostException::class, ConnectionException::class];
    yield 'TimeoutException' => [TimeoutException::class, RuntimeException::class];
    yield 'UnsupportedException' => [UnsupportedException::class, RuntimeException::class];
    yield 'ConfigurationException' => [ConfigurationException::class, LogicException::class];
    yield 'UsageException' => [UsageException::class, LogicException::class];
});
test('every exception implements oracle exception under its spl parent', function (string $class, string $parent): void {
    expect(is_subclass_of($class, OracleException::class))->toBeTrue("$class implements OracleException")
        ->and(is_subclass_of($class, $parent))->toBeTrue("$class extends $parent");
})->with('hierarchy');
test('a duplicate gives unique violation exception', function (): void {
    $sql = "insert into fx_items (id, category_id, name, price, created_at) values (1, 1, 'duplicate', 1, sysdate)";

    try {
        $this->connect()->execute($sql, [], self::options());
        self::fail('The duplicate was inserted.');
    } catch (UniqueViolationException $uniqueViolationException) {
        expect($uniqueViolationException->getCode())->toBe(1)
            ->and($uniqueViolationException->getMessage())->toStartWith('ORA-00001')
            ->and($uniqueViolationException->sql())->toBe($sql)
            ->and($uniqueViolationException->call())->toBeNull();
    }
});
test('a compilation error gives a warning and a valid unit gives null', function (): void {
    $connection = $this->connect();
    $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));
    $invalid = "create or replace procedure $procedure is begin no_such_thing; end;";
    $valid = "create or replace procedure $procedure is begin null; end;";

    try {
        $warning = $connection->execute($invalid, [], self::options())->warning();
        expect($warning)->not->toBeNull()
            ->toContain('compilation errors')
            ->and($connection->execute('select 1 from dual', [], self::options())->warning())->toBeNull('a later query')
            ->and($connection->execute($valid, [], self::options())->warning())->toBeNull('a valid unit');
    } finally {
        $connection->execute("drop procedure $procedure", [], self::options());
    }
});
test('a unique violation on a fresh connection carries no ora 03301 line and no bound value', function (): void {
    $connection = $this->connect(...self::ownPool());
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)';
    $connection->execute($insert, ['id' => 39001, 'code' => 'zq39secret'], self::options(autocommit: false));

    try {
        $connection->execute($insert, ['id' => 39002, 'code' => 'zq39secret'], self::options(autocommit: false));
        self::fail('The duplicate was inserted.');
    } catch (UniqueViolationException $uniqueViolationException) {
        expect($uniqueViolationException->getMessage())->toStartWith('ORA-00001')
            ->and($uniqueViolationException->getMessage())->not->toContain('ORA-03301')
            ->and($uniqueViolationException->getMessage())->not->toContain('zq39secret');
    } finally {
        $connection->rollback();
    }
});
test('the same compilation error twice in a row gives a warning each time', function (): void {
    $connection = $this->connect();
    $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));
    $invalid = "create or replace procedure $procedure is begin no_such_thing; end;";

    try {
        $first = $connection->execute($invalid, [], self::options())->warning();
        $again = $connection->execute($invalid, [], self::options())->warning();
    } finally {
        $connection->execute("drop procedure $procedure", [], self::options());
    }

    expect($first)->not->toBeNull()
        ->and($again)->not->toBeNull()
        ->and($again)->toContain('compilation errors');
});
test('a warning never leaks into a later execution or the next connection on the pooled session', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));
    $invalid = "create or replace procedure $procedure is begin no_such_thing; end;";
    $first = $this->connect(...$fields);
    $session = self::session($first);

    try {
        expect($first->execute($invalid, [], self::options())->warning())->not->toBeNull()
            ->and($first->execute('begin null; end;', [], self::options())->warning())->toBeNull('a later block')
            ->and($first->execute('select 1 from dual', [], self::options())->warning())->toBeNull('a later query');
        // The Session's last warning again, as the Connection is released.
        expect($first->execute($invalid, [], self::options())->warning())->not->toBeNull();
        $first->close();

        $second = $this->connect(...$fields);
        expect(self::session($second))->toBe($session)
            ->and($second->execute('select 1 from dual', [], self::options())->warning())->toBeNull('a query')
            ->and($second->execute('begin null; end;', [], self::options())->warning())->toBeNull('a block')
            ->and($second->execute("create or replace procedure $procedure is begin null; end;", [], self::options())->warning())->toBeNull('a valid unit');
    } finally {
        $this->connect()->execute("drop procedure $procedure", [], self::options());
    }
});
test('the pool at its ceiling gives pool exhausted exception', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.1];
    $this->connect(...$fields);

    try {
        $this->connect(...$fields);
        self::fail('A second Session came out of a Pool of one.');
    } catch (PoolExhaustedException $poolExhaustedException) {
        expect($poolExhaustedException->getCode())->toBe(0)
            ->and($poolExhaustedException->getMessage())->toContain('acquireTimeout')
            ->and($poolExhaustedException->sql())->toBeNull();
    }
});
test('a call timeout on a cpu bound query throws and the connection survives', function (): void {
    $connection = $this->connect(...self::ownPool());
    $sql = 'select count(*) from fx_bench_rows a, fx_bench_rows b';
    $started = microtime(true);

    try {
        $connection->execute($sql, [], self::options(timeout: 0.5))->cursor()?->fetchBatch();
        self::fail('The query finished within its Call timeout.');
    } catch (TimeoutException $timeoutException) {
        expect($timeoutException->getCode())->toBe(0)
            ->and($timeoutException->getMessage())->toBe('Call timeout of 500 ms exceeded.')
            ->and($timeoutException->sql())->toBe($sql);
    }
    expect(microtime(true) - $started)->toBeLessThan(5, 'The break stopped the query.')
        ->and(self::value($connection, 'select 1 from dual'))->toBe(1);
});
test('a call timeout on a sleep makes the next call throw connection lost', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $connection = $this->connect(...$fields);
    $session = self::session($connection);

    try {
        $connection->execute('begin fx_pool.sleep(3); end;', [], self::options(timeout: 0.5));
        self::fail('The sleep finished within its Call timeout.');
    } catch (TimeoutException $timeoutException) {
        expect($timeoutException->getMessage())->toStartWith('Call timeout of 500 ms exceeded');
    }
    expect(function () use ($connection): void {
        $connection->execute('select 1 from dual', [], self::options());
        self::fail('A Broken Connection ran a statement.');
    })->toThrow(ConnectionLostException::class, 'Connection is broken: ' . $timeoutException->getMessage());
    $connection->close();

    expect(self::session($this->connect(...$fields)))->not->toBe($session);
});
/**
 * @return iterable<string, array{bool}>
 */
dataset('releases', function () {
    yield 'close()' => [false];
    yield 'the destructor' => [true];
});
test('a session dead after the last call is released without a throw and not returned', function (bool $destroy): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config(...$fields));
    $session = self::session($connection);
    $connection->execute("update fx_items set name = 'uncommitted' where id = 43", [], self::options(autocommit: false));
    self::kill($connection);

    if ($destroy) {
        unset($connection);
    } else {
        $connection->close();
    }

    expect(self::session($this->connect(...$fields)))->not->toBe($session);
})->with('releases');
test('an injected panic gives connection lost and makes the connection broken', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $session = self::session($connection);

    try {
        $connection->execute('/* oracledb: panic */ select 1 from dual', [], self::options());
        self::markTestSkipped('The panic hook exists in debug builds of the Extension only.');
    } catch (ConnectionLostException $connectionLostException) {
        expect($connectionLostException->getCode())->toBe(0)
            ->and($connectionLostException->getMessage())->toStartWith('internal error in oracledb: ');
        $panic = $connectionLostException->getMessage();
    }
    expect(function () use ($connection): void {
        $connection->execute('select 1 from dual', [], self::options());
        self::fail('A Broken Connection ran a statement.');
    })->toThrow(ConnectionLostException::class, 'Connection is broken: ' . $panic);
    $connection->close();

    for ($i = 0; $i < 3; ++$i) {
        $next = $this->connect(...$fields);
        expect(self::session($next))->not->toBe($session);
        $next->close();
    }
});
test('a syntax error gives query exception with the server offset', function (): void {
    $connection = $this->connect();

    foreach (['select 1 fro dual' => [923, 13], 'select nosuchcolumn from dual' => [904, 7]] as $sql => [$code, $offset]) {
        try {
            $connection->execute($sql, [], self::options());
            self::fail("$sql ran.");
        } catch (QueryException $e) {
            expect($e::class)->toBe(QueryException::class)
                ->and($e->getCode())->toBe($code)
                ->and($e->offset())->toBe($offset)
                ->and($e->sql())->toBe($sql);
        }
    }
});
