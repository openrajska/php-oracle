<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Connection\ConnectionTest;

use Closure;
use DateTimeImmutable;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\ArrayDml;
use Rajska\Oracle\Statement\Cursor;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Tests\Functional\TestCase;
use Rajska\Oracle\Tracing;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table connection_items (id number(10) primary key, name varchar2(100) not null)');
    schema()->run("insert into connection_items (id, name) select level, 'Item ' || level from dual connect by level <= 3");

    $this->connection = $this->connect();
});

test('value of an empty result is null', function (): void {
    $value = $this->connection->select('select id from connection_items where id = :id', ['id' => -1])->value();

    expect($value)->toBeNull();
});

test('run returns the dml count', function (): void {
    $execution = $this->connection->run('update connection_items set name = name where id <= :last', ['last' => 3]);

    expect($execution->affected())->toBe(3);
});

test('a query affects nothing', function (): void {
    $execution = $this->connection->run('select id from connection_items');

    expect($execution->affected())->toBe(0);
});

test('select of a statement that is not a query throws usage exception', function (): void {
    $select = fn () => $this->connection->select('update connection_items set name = name where id = 1');

    expect($select)->toThrow(UsageException::class);
});

test('a wrong password throws connection exception with code 1017', function (): void {
    $thrown = null;

    try {
        Oracle::connect($this->config(password: 'not-the-password'));
    } catch (ConnectionException $connectionException) {
        $thrown = $connectionException;
    }

    expect($thrown?->getCode())->toBe(1017)
        ->and($thrown?->getMessage())->toStartWith('ORA-01017');
});

/**
 * Every verb of a Connection.
 *
 * @return iterable<string, array{Closure(Connection): mixed}>
 */
dataset('verbs', function () {
    yield 'run()' => [static fn (Connection $c): Execution => $c->run('select 1 from dual')];
    yield 'select()' => [static fn (Connection $c): Cursor => $c->select('select 1 from dual')];
    yield 'procedure()' => [static fn (Connection $c): Execution => $c->procedure('dbms_output.disable')];
    yield 'function()' => [static fn (Connection $c): DateTimeImmutable | string | null => $c->function('sysdate')->date()];
    yield 'runMany()' => [static fn (Connection $c): ArrayDml => $c->runMany('insert into connection_items (id, name) values (:id, :name)', [])];
    yield 'begin()' => [static fn (Connection $c) => $c->begin()];
    yield 'commit()' => [static fn (Connection $c) => $c->commit()];
    yield 'rollback()' => [static fn (Connection $c) => $c->rollback()];
    yield 'transaction()' => [static fn (Connection $c) => $c->transaction(static fn (): null => null)];
    yield 'trace()' => [static fn (Connection $c) => $c->trace(new Tracing(module: 'm'))];
    yield 'serverVersion()' => [static fn (Connection $c): string => $c->serverVersion()];
    yield 'enableOutput()' => [static fn (Connection $c) => $c->enableOutput()];
    yield 'output()' => [static fn (Connection $c): array => $c->output()];
    yield 'disableOutput()' => [static fn (Connection $c) => $c->disableOutput()];
});

test('every connection verb after close throws usage exception', function (Closure $verb): void {
    $this->connection->close();

    $call = fn () => $verb($this->connection);

    expect($call)->toThrow(UsageException::class, 'The Connection is closed.');
})->with('verbs');

test('a statement refused after close carries its sql', function (): void {
    $this->connection->close();
    $thrown = null;

    try {
        $this->connection->run('select 1 from dual');
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    expect($thrown?->sql())->toBe('select 1 from dual');
});

test('a second close is fine', function (): void {
    $this->connection->close();

    $this->connection->close();

    expect(fn () => $this->connection->run('select 1 from dual'))->toThrow(UsageException::class, 'The Connection is closed.');
});
