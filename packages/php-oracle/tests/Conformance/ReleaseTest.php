<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ReleaseTest;

use Closure;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Driver\DriverArrayDml;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Tests\Conformance\ConformanceTestCase;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TransactionObserver;
use RuntimeException;

beforeEach(function (): void {
    $this->pool = self::ownPool() + ['maxConnections' => 1];
    $this->observer = TransactionObserver::open();
    $this->counter = RoundTripCounter::open();
    $this->observer->clear();
    $this->observer->execute('insert into fx_tx_rows (id) values (1)');
});
afterEach(function (): void {
    $this->counter->close();
    $this->observer->close();
});
/**
 * What the last calls before a Release left: never a transaction.
 *
 * @return iterable<string, array{Closure(DriverConnection): mixed}>
 */
dataset('nothingOpen', function () {
    yield 'no call' => [static fn(DriverConnection $c): null => null];
    yield 'a query' => [static fn(DriverConnection $c): array => ConformanceTestCase::rows($c, 'select id from fx_tx_rows')];
    yield 'a DML under Autocommit' => [static fn(DriverConnection $c): DriverExecution => $c->execute('insert into fx_tx_rows (id) values (2)', [], ConformanceTestCase::options())];
    yield 'a block under Autocommit' => [static fn(DriverConnection $c): DriverExecution => $c->execute("begin update fx_tx_rows set note = 'block' where id = 1; end;", [], ConformanceTestCase::options())];
    yield 'SELECT … FOR UPDATE under Autocommit' => [static fn(DriverConnection $c): array => ConformanceTestCase::rows($c, 'select id from fx_tx_rows for update')];
    yield 'commit()' => [static function (DriverConnection $c): void {
        $c->execute('insert into fx_tx_rows (id) values (2)', [], ConformanceTestCase::options(autocommit: false));
        $c->commit();
    }];
    yield 'rollback()' => [static function (DriverConnection $c): void {
        $c->execute('insert into fx_tx_rows (id) values (2)', [], ConformanceTestCase::options(autocommit: false));
        $c->rollback();
    }];
});
test('a release with no transaction open makes no round trip', function (Closure $work): void {
    $fields = $this->pool;
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $sid = self::sid($connection);
    $work($connection);

    expect($this->counter->during($sid, $connection->close(...)))->toBe(0, 'close()')
        ->and($this->counter->during($sid, function () use ($fields): void {
            $this->connect(...$fields)->close();
        }))->toBe(0, 'connect() + close()')
        ->and($this->counter->during($sid, function () use ($fields): void {
            $connection = $this->connect(...$fields);
            unset($connection);
        }))->toBe(0, 'connect() + destroying the Connection');
})->with('nothingOpen');
/**
 * Work the server keeps open after the call, with Autocommit off: each
 * locks fx_tx_rows row 1 or holds the transaction some other way.
 *
 * @return iterable<string, array{Closure(DriverConnection): mixed}>
 */
dataset('leftOpen', function () {
    $open = ConformanceTestCase::options(autocommit: false);
    $lock = static fn(DriverConnection $c): DriverExecution => $c->execute("update fx_tx_rows set note = 'open' where id = 1", [], $open);

    yield 'DML in a PL/SQL block' => [static fn(DriverConnection $c): DriverExecution => $c->execute(
        "begin update fx_tx_rows set note = 'open' where id = 1; insert into fx_tx_rows (id) values (2); end;",
        [],
        $open,
    )];
    yield 'SELECT … FOR UPDATE' => [static fn(DriverConnection $c): array => ConformanceTestCase::rows($c, 'select id from fx_tx_rows where id = 1 for update', [], $open)];
    yield 'LOCK TABLE' => [static fn(DriverConnection $c): DriverExecution => $c->execute('lock table fx_tx_rows in exclusive mode', [], $open)];
    yield 'SET TRANSACTION READ ONLY' => [static fn(DriverConnection $c): DriverExecution => $c->execute('set transaction read only', [], $open)];
    yield 'runMany()' => [static fn(DriverConnection $c): DriverArrayDml => $c->executeMany('insert into fx_tx_rows (id) values (:id)', [['id' => 2], ['id' => 3]], $open)];
    yield 'runMany() with a failing row, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
        $lock($c);
        fails(static fn(): DriverArrayDml => $c->executeMany('insert into fx_tx_rows (id) values (:id)', [['id' => 2], ['id' => 1]], $open));
    }];
    yield 'a failed execute, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
        $lock($c);
        fails(static fn(): DriverExecution => $c->execute('insert into fx_tx_rows (id) values (1)', [], $open));
    }];
    yield 'a query, after a DML' => [static function (DriverConnection $c) use ($lock): void {
        $lock($c);
        ConformanceTestCase::rows($c, 'select id from fx_tx_rows', [], ConformanceTestCase::options(autocommit: false));
    }];
    yield 'DBMS_OUTPUT calls, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
        $lock($c);
        $c->execute("begin dbms_output.enable(null); dbms_output.put_line('line'); end;", [], $open);
        $c->execute('begin dbms_output.disable; end;', [], $open);
    }];
    yield 'a DML with a LOB bind' => [static fn(DriverConnection $c): DriverExecution => $c->execute(
        'update fx_tx_rows set note = dbms_lob.substr(:note, 10) where id = 1',
        ['note' => Clob::in(str_repeat('x', 40_000))],
        $open,
    )];
    yield 'a LOB write, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
        $lock($c);
        ConformanceTestCase::rows($c, 'select dbms_lob.getlength(:text) as n from dual', ['text' => Clob::in(str_repeat('x', 40_000))], $open);
    }];
});
test('a release rolls back whatever the server reports open', function (Closure $work): void {
    $fields = $this->pool;
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $work($connection);

    $connection->close();

    expect($this->observer->select('select id, note from fx_tx_rows order by id'))->toBe([['ID' => '1', 'NOTE' => null]])
        ->and($this->observer->locked(1))->toBeFalse('The Release left row 1 locked.');

    $next = $this->connect(...$fields);
    $next->execute("update fx_tx_rows set note = 'next' where id = 1", [], self::options());

    expect($this->observer->select('select id, note from fx_tx_rows order by id'))->toBe([['ID' => '1', 'NOTE' => 'next']]);
})->with('leftOpen');
/**
 * @param Closure(): mixed $call
 */
function fails(Closure $call): void
{
    try {
        $call();
    } catch (OracleException) {
        return;
    }
    throw new RuntimeException('The call did not fail.');
}
