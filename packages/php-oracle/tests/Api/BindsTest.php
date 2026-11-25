<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\BindsTest;

use Closure;
use DateTimeImmutable;
use Rajska\Oracle\Bind\Boolean;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\PlsInteger;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Execution;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\Binds\Priority;
use Rajska\Oracle\Tests\Support\TestDatabase;
use SensitiveParameter;
use TypeError;

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});
afterEach(function (): void {
    $this->connection->close();
});
test('plain values bind by name case insensitively', function (): void {
    expect($this->connection->select(
        'select count(*) from fx_items where id = :Id and :price = 0.1 and :priority = 2 and :flag and :nothing is null',
        ['id' => 1, 'PRICE' => 0.1, 'priority' => Priority::High, 'flag' => true, 'nothing' => null],
    )->value())->toBe(1);
});
test('a date fails naming the typed binds', function (): void {
    expect(fn() => $this->connection->select('select :at from dual', ['at' => new DateTimeImmutable()]))->toThrow(UsageException::class, "Bind 'at': a DateTimeInterface does not bind by itself; use Date::in() or Timestamp::in().");
});
/**
 * @return iterable<string, array{Closure}>
 */
dataset('rejectedValues', function () {
    yield 'Number::in(), not a decimal literal' => [static fn(#[SensitiveParameter] string $secret): mixed => Number::in("1e5$secret")];
    yield 'Number::inOut(), not a decimal literal' => [static fn(#[SensitiveParameter] string $secret): mixed => Number::inOut("1e5$secret")];
    yield 'Boolean::in(), a string' => [Boolean::in(...)];
    yield 'PlsInteger::inOut(), a string' => [PlsInteger::inOut(...)];
});
test('a value a typed bind rejects never appears in its exception', function (Closure $factory): void {
    expect(\ini_get('zend.exception_ignore_args'))->toBe('0', "the suite keeps exceptions' arguments");
    $secret = 'zq32secret';

    try {
        $factory($secret);
        self::fail('The value was accepted.');
    } catch (TypeError | UsageException $e) {
        expect($e->getMessage())->not->toContain($secret)
            ->and($e->getTraceAsString())->not->toContain($secret)
            ->and((string) $e)->not->toContain($secret);
    }
})->with('rejectedValues');
test('a bound value never appears in a run error', function (): void {
    expect(\ini_get('zend.exception_ignore_args'))->toBe('0', "the suite keeps exceptions' arguments");
    $secret = 'zq32secret';
    $calls = [
        'server error' => fn(): Execution => $this->connection->run('begin :n := to_number(:text); end;', ['n' => Number::out(), 'text' => $secret]),
        'typed bind, server error' => fn(): Execution => $this->connection->run('begin :n := to_number(:text); end;', ['n' => Number::out(), 'text' => Varchar::in($secret)]),
        'refused bind' => fn(): Execution => $this->connection->run('begin :n := :text; end;', ['n' => Number::out(), 'text' => [$secret]]),
    ];
    foreach ($calls as $kind => $call) {
        try {
            $call();
            self::fail("$kind: '$secret' was taken for a number.");
        } catch (OracleException $e) {
            expect($e->getMessage())->not->toContain($secret)
                ->and((string) $e->sql())->not->toContain($secret)
                ->and($e->getTraceAsString())->not->toContain($secret)
                ->and((string) $e)->not->toContain($secret);
            if ($kind !== 'refused bind') {
                expect($e)->toBeInstanceOf(QueryException::class, $kind);
            }
        }
    }
});
test('a list of binds fails naming the named form', function (): void {
    // @phpstan-ignore argument.type (static analysis already flags a list)
    expect(fn() => $this->connection->select('select * from fx_items where id = :id', [1]))->toThrow(UsageException::class, "key every value by its placeholder name without ':'");
});
