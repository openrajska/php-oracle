<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\ErrorTest;

use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

const INVALID_UNIT = 'create or replace procedure invalid_unit is begin no_such_thing; end;';

beforeEach(function (): void {
    schema()->run('create table error_rows (id number(10) primary key, code varchar2(10) not null unique, note varchar2(100))');

    schema()->run("insert into error_rows (id, code) values (1, 'one')");
});

test('a duplicate gives unique violation exception', function (): void {
    $connection = $this->connect();
    $sql = "insert into error_rows (id, code) values (1, 'duplicate')";
    $thrown = null;

    try {
        $connection->execute($sql, [], $this->options());
    } catch (UniqueViolationException $uniqueViolationException) {
        $thrown = $uniqueViolationException;
    }

    expect($thrown?->getCode())->toBe(1)
        ->and($thrown?->getMessage())->toStartWith('ORA-00001')
        ->and($thrown?->sql())->toBe($sql)
        ->and($thrown?->call())->toBeNull();
});

test('a unique violation on a fresh connection carries no ora 03301 line and no bound value', function (): void {
    $connection = $this->connect(...$this->ownPool());
    $insert = 'insert into error_rows (id, code) values (:id, :code)';
    $connection->execute($insert, ['id' => 2, 'code' => 'zq39secret'], $this->options(autocommit: false));
    $thrown = null;

    try {
        $connection->execute($insert, ['id' => 3, 'code' => 'zq39secret'], $this->options(autocommit: false));
    } catch (UniqueViolationException $uniqueViolationException) {
        $thrown = $uniqueViolationException;
    }

    expect($thrown?->getMessage())->toStartWith('ORA-00001')
        ->not->toContain('ORA-03301')
        ->not->toContain('zq39secret');
});

test('a syntax error gives query exception with the server offset', function (string $sql, int $code, int $offset): void {
    $connection = $this->connect();
    $thrown = null;

    try {
        $connection->execute($sql, [], $this->options());
    } catch (QueryException $queryException) {
        $thrown = $queryException;
    }

    assert($thrown instanceof QueryException);
    expect($thrown::class)->toBe(QueryException::class)
        ->and($thrown->getCode())->toBe($code)
        ->and($thrown->offset())->toBe($offset)
        ->and($thrown->sql())->toBe($sql);
})->with([
    'a misspelled keyword' => ['select 1 fro dual', 923, 13],
    'an unknown column' => ['select nosuchcolumn from dual', 904, 7],
]);

test('a compilation error gives a warning every time it runs', function (): void {
    $connection = $this->connect();

    $warnings = [
        $connection->execute(INVALID_UNIT, [], $this->options())->warning(),
        $connection->execute(INVALID_UNIT, [], $this->options())->warning(),
    ];

    expect($warnings[0])->toContain('compilation errors')
        ->and($warnings[1])->toContain('compilation errors');
});

test('a statement after a compilation error gives no warning', function (string $sql): void {
    $connection = $this->connect();
    $connection->execute(INVALID_UNIT, [], $this->options());

    $warning = $connection->execute($sql, [], $this->options())->warning();

    expect($warning)->toBeNull();
})->with([
    'a query' => ['select 1 from dual'],
    'a block' => ['begin null; end;'],
    'a valid unit' => ['create or replace procedure invalid_unit is begin null; end;'],
]);

test('a warning never reaches the next connection on the pooled session', function (): void {
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

test('a session dead after the last call is released without a throw and not returned', function (bool $destroy): void {
    $fields = $this->ownPool() + ['maxConnections' => 1];
    $connection = $this->driver()->connect($this->config(...$fields));
    $session = $this->session($connection);
    $connection->execute("update error_rows set note = 'uncommitted' where id = 1", [], $this->options(autocommit: false));
    kill($this->sid($connection));

    if ($destroy) {
        unset($connection);
    } else {
        $connection->close();
    }

    expect($this->session($this->connect(...$fields)))->not->toBe($session);
})->with(['close()' => [false], 'the destructor' => [true]]);

test('an injected panic gives connection lost and makes the connection broken', function (): void {
    $fields = $this->ownPool() + ['maxConnections' => 1];
    $this->connect(...$fields)->close();
    $connection = $this->connect(...$fields);
    $session = $this->session($connection);

    try {
        $connection->execute('/* oracledb: panic */ select 1 from dual', [], $this->options());
        $this->markTestSkipped('The panic hook exists in debug builds of the Extension only.');
    } catch (ConnectionLostException $connectionLostException) {
        $panic = $connectionLostException;
    }

    expect($panic->getCode())->toBe(0)
        ->and($panic->getMessage())->toStartWith('internal error in oracledb: ')
        ->and(fn () => $connection->execute('select 1 from dual', [], $this->options()))
        ->toThrow(ConnectionLostException::class, 'Connection is broken: ' . $panic->getMessage());
    $connection->close();
    for ($i = 0; $i < 3; ++$i) {
        $next = $this->connect(...$fields);
        expect($this->session($next))->not->toBe($session);
        $next->close();
    }
});
