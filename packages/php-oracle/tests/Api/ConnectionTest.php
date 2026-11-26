<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\ConnectionTest;

use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});

afterEach(function (): void {
    $this->connection->close();
});

test('select value returns an integral number as an int', function (): void {
    expect($this->connection->select('select id from fx_items where id = :id', ['id' => 1])->value())->toBe(1);
});

test('a string bind matches a varchar2 that arrives as a string', function (): void {
    expect($this->connection->select(
        'select name from fx_items where name = :name',
        ['name' => 'Item 0002'],
    )->value())->toBe('Item 0002');
});

test('a non integral number arrives as its exact string', function (): void {
    expect($this->connection->select('select price from fx_items where id = 2')->value())->toBe('2.5');
});

test('null arrives as null', function (): void {
    expect($this->connection->select('select cast(null as number) from dual')->value())->toBeNull()
        ->and($this->connection->select('select cast(null as varchar2(10)) from dual')->value())->toBeNull();
});

test('value of an empty result is null', function (): void {
    expect($this->connection->select('select id from fx_items where id = :id', ['id' => -1])->value())->toBeNull();
});

test('run returns the dml count', function (): void {
    expect($this->connection->run('update fx_items set name = name where id <= :last', ['last' => 3])->affected())->toBe(3);
});

test('a query affects nothing', function (): void {
    expect($this->connection->run('select id from fx_items')->affected())->toBe(0);
});

test('rows of a non query fail', function (): void {
    expect(fn () => $this->connection->select('update fx_items set name = name where id = 1'))->toThrow(UsageException::class);
});

test('a wrong password throws connection exception with code 1017', function (): void {
    try {
        Oracle::connect(TestDatabase::fromEnvironment()->config(password: 'not-the-password'));
        $this->fail('connect() succeeded with a wrong password.');
    } catch (ConnectionException $connectionException) {
        expect($connectionException->getCode())->toBe(1017)
            ->and($connectionException->getMessage())->toStartWith('ORA-01017');
    }
});

test('a call after close fails and a second close is fine', function (): void {
    $this->connection->close();

    expect(fn () => $this->connection->run('select 1 from dual'))->toThrow(function (UsageException $usageException): void {
        expect($usageException->getMessage())->toBe('The Connection is closed.')
            ->and($usageException->sql())->toBe('select 1 from dual');
    });
    $this->connection->close();
});

test('server version is the five part version full with no round trip', function (): void {
    $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
    assert(\is_string($sid));
    $counter = RoundTripCounter::open();

    try {
        $version = null;
        $trips = $counter->during((int) $sid, function () use (&$version): void {
            $version = $this->connection->serverVersion();
        });
    } finally {
        $counter->close();
    }

    expect($trips)->toBe(0)
        ->and($version)->toBeString()
        ->toMatch('/^\d+\.\d+\.\d+\.\d+\.\d+$/')
        ->toBe($this->connection->select("select version_full from product_component_version where product like 'Oracle%'")->value());
});

test('server version after close fails', function (): void {
    $this->connection->close();
    expect(fn () => $this->connection->serverVersion())->toThrow(UsageException::class, 'The Connection is closed.');
});
