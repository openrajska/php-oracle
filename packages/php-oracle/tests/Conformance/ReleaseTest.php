<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ReleaseTest;

use Closure;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Contracts\Driver\ArrayDml;
use Rajska\Oracle\Contracts\Driver\Response;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Tests\Conformance\ConformanceTestCase;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TransactionObserver;
use RuntimeException;

beforeEach(function (): void {
    $this->pool = $this->ownPool() + ['maxConnections' => 1];
    $this->transactionObserver = TransactionObserver::open();
    $this->counter = RoundTripCounter::open();
    $this->transactionObserver->clear();
    $this->transactionObserver->execute('insert into fx_tx_rows (id) values (1)');
});

afterEach(function (): void {
    $this->counter->close();
    $this->transactionObserver->close();
});

/**
 * What the last calls before a Release left: never a transaction.
 *
 * @return iterable<string, array{Closure(Session): mixed}>
 */
dataset('nothingOpen', function () {
    yield 'no call' => [static fn (Session $c): null => null];
    yield 'a query' => [static fn (Session $c): array => ConformanceTestCase::rows($c, 'select id from fx_tx_rows')];
    yield 'a DML under Autocommit' => [static fn (Session $c): Response => $c->execute('insert into fx_tx_rows (id) values (2)', [], ConformanceTestCase::options())];
    yield 'a block under Autocommit' => [static fn (Session $c): Response => $c->execute("begin update fx_tx_rows set note = 'block' where id = 1; end;", [], ConformanceTestCase::options())];
    yield 'SELECT … FOR UPDATE under Autocommit' => [static fn (Session $c): array => ConformanceTestCase::rows($c, 'select id from fx_tx_rows for update')];
    yield 'commit()' => [static function (Session $c): void {
        $c->execute('insert into fx_tx_rows (id) values (2)', [], ConformanceTestCase::options(autocommit: false));
        $c->commit();
    }];
    yield 'rollback()' => [static function (Session $c): void {
        $c->execute('insert into fx_tx_rows (id) values (2)', [], ConformanceTestCase::options(autocommit: false));
        $c->rollback();
    }];
});

test('a release with no transaction open makes no round trip', function (Closure $work): void {
    $fields = $this->pool;
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $sid = $this->sid($connection);
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
 * @return iterable<string, array{Closure(Session): mixed}>
 */
dataset('leftOpen', function () {
    $open = ConformanceTestCase::options(autocommit: false);
    $lock = static fn (Session $c): Response => $c->execute("update fx_tx_rows set note = 'open' where id = 1", [], $open);

    yield 'DML in a PL/SQL block' => [static fn (Session $c): Response => $c->execute(
        "begin update fx_tx_rows set note = 'open' where id = 1; insert into fx_tx_rows (id) values (2); end;",
        [],
        $open,
    )];
    yield 'SELECT … FOR UPDATE' => [static fn (Session $c): array => ConformanceTestCase::rows($c, 'select id from fx_tx_rows where id = 1 for update', [], $open)];
    yield 'LOCK TABLE' => [static fn (Session $c): Response => $c->execute('lock table fx_tx_rows in exclusive mode', [], $open)];
    yield 'SET TRANSACTION READ ONLY' => [static fn (Session $c): Response => $c->execute('set transaction read only', [], $open)];
    yield 'runMany()' => [static fn (Session $c): ArrayDml => $c->executeMany('insert into fx_tx_rows (id) values (:id)', [['id' => 2], ['id' => 3]], $open)];
    yield 'runMany() with a failing row, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        fails(static fn (): ArrayDml => $c->executeMany('insert into fx_tx_rows (id) values (:id)', [['id' => 2], ['id' => 1]], $open));
    }];
    yield 'a failed execute, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        fails(static fn (): Response => $c->execute('insert into fx_tx_rows (id) values (1)', [], $open));
    }];
    yield 'a query, after a DML' => [static function (Session $c) use ($lock): void {
        $lock($c);
        ConformanceTestCase::rows($c, 'select id from fx_tx_rows', [], ConformanceTestCase::options(autocommit: false));
    }];
    yield 'DBMS_OUTPUT calls, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        $c->execute("begin dbms_output.enable(null); dbms_output.put_line('line'); end;", [], $open);
        $c->execute('begin dbms_output.disable; end;', [], $open);
    }];
    yield 'a DML with a LOB bind' => [static fn (Session $c): Response => $c->execute(
        'update fx_tx_rows set note = dbms_lob.substr(:note, 10) where id = 1',
        ['note' => new In\Clob(str_repeat('x', 40_000))],
        $open,
    )];
    yield 'a LOB write, after a DML' => [static function (Session $c) use ($lock, $open): void {
        $lock($c);
        ConformanceTestCase::rows($c, 'select dbms_lob.getlength(:text) as n from dual', ['text' => new In\Clob(str_repeat('x', 40_000))], $open);
    }];
});

test('a release rolls back whatever the server reports open', function (Closure $work): void {
    $fields = $this->pool;
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $work($connection);

    $connection->close();

    expect($this->transactionObserver->select('select id, note from fx_tx_rows order by id'))->toBe([['ID' => '1', 'NOTE' => null]])
        ->and($this->transactionObserver->locked(1))->toBeFalse('The Release left row 1 locked.');

    $next = $this->connect(...$fields);
    $next->execute("update fx_tx_rows set note = 'next' where id = 1", [], $this->options());

    expect($this->transactionObserver->select('select id, note from fx_tx_rows order by id'))->toBe([['ID' => '1', 'NOTE' => 'next']]);
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
