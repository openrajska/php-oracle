<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\BindsTest;

use Closure;
use DateTimeImmutable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\Execution;
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
    expect(fn () => $this->connection->select('select :at from dual', ['at' => new DateTimeImmutable()]))->toThrow(UsageException::class, "Bind 'at': a DateTimeInterface does not bind by itself; use In\Date or In\Timestamp.");
});

/**
 * @return iterable<string, array{Closure}>
 */
dataset('rejectedValues', function () {
    yield 'In\Number, not a decimal literal' => [static fn (#[SensitiveParameter] string $secret): mixed => new In\Number("1e5$secret")];
    yield 'InOut\Number, not a decimal literal' => [static fn (#[SensitiveParameter] string $secret): mixed => new InOut\Number("1e5$secret")];
    yield 'In\Boolean, a string' => [static fn (#[SensitiveParameter] string $value): mixed => new In\Boolean($value)]; // @phpstan-ignore argument.type (the mistake is the point)
    yield 'InOut\PlsInteger, a string' => [static fn (#[SensitiveParameter] string $value): mixed => new InOut\PlsInteger($value)]; // @phpstan-ignore argument.type (the mistake is the point)
});

test('a value a typed bind rejects never appears in its exception', function (Closure $factory): void {
    expect(\ini_get('zend.exception_ignore_args'))->toBe('0', "the suite keeps exceptions' arguments");
    $secret = 'zq32secret';

    try {
        $factory($secret);
        $this->fail('The value was accepted.');
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
        'server error' => fn (): Execution => $this->connection->run('begin :n := to_number(:text); end;', ['n' => new Out\Number(), 'text' => $secret]),
        'typed bind, server error' => fn (): Execution => $this->connection->run('begin :n := to_number(:text); end;', ['n' => new Out\Number(), 'text' => new In\Varchar($secret)]),
        'refused bind' => fn (): Execution => $this->connection->run('begin :n := :text; end;', ['n' => new Out\Number(), 'text' => [$secret]]),
    ];
    foreach ($calls as $kind => $call) {
        try {
            $call();
            $this->fail("$kind: '$secret' was taken for a number.");
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
    expect(fn () => $this->connection->select('select * from fx_items where id = :id', [1]))->toThrow(UsageException::class, "key every value by its placeholder name without ':'");
});
