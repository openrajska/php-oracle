<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\BenchOci8ValuesTest;

use DateTimeImmutable;
use DateTimeZone;
use Rajska\Oracle\Bench\Oci8Values;

/**
 * @return iterable<string, array{string, int|string}>
 */
dataset('exactNumbers', function () {
    yield 'an integer' => ['42', 42];
    yield 'a negative integer' => ['-7', -7];
    yield 'zero' => ['0', 0];
    yield 'a decimal' => ['12.3', '12.3'];
    yield 'oci8 drops the leading zero' => ['.5', '0.5'];
    yield 'and keeps the sign' => ['-.25', '-0.25'];
    yield 'beyond 64 bits stays a string' => ['12345678901234567890', '12345678901234567890'];
    yield 'the smallest 64-bit integer' => ['-9223372036854775808', PHP_INT_MIN];
});

test('an exact number is an int when integral and within 64 bits', function (string $oci8, int | string $core): void {
    expect(Oci8Values::exact($oci8))->toBe($core);
})->with('exactNumbers');

/**
 * @return iterable<string, array{string, float|int}>
 */
dataset('floatNumbers', function () {
    yield 'an integer' => ['42', 42];
    yield 'a decimal' => ['12.5', 12.5];
    yield 'oci8 drops the leading zero' => ['.5', 0.5];
    yield 'and keeps the sign' => ['-.25', -0.25];
});

test('a float number is an int when integral', function (string $oci8, float | int $core): void {
    expect(Oci8Values::float($oci8))->toBe($core);
})->with('floatNumbers');

test('a date in the benchmark session format is the wall clock in the default zone', function (): void {
    $date = Oci8Values::dateTime('2026-01-01 00:00:00.003000000');

    expect($date->format('Y-m-d H:i:s.u'))->toBe('2026-01-01 00:00:00.003000')
        ->and($date->getTimezone()->getName())->toBe(date_default_timezone_get());
});

test('the canonical form tells types apart', function (): void {
    expect(Oci8Values::canonical(['A' => '1']))->not->toBe(Oci8Values::canonical(['A' => 1]))
        ->and(Oci8Values::canonical(['A' => 1.0]))->not->toBe(Oci8Values::canonical(['A' => 1]))
        ->and(Oci8Values::canonical(['B' => 1]))->not->toBe(Oci8Values::canonical(['A' => 1]));
});

test('the canonical form takes bytes and large strings', function (): void {
    expect(Oci8Values::canonical(["\xff\x01"]))->not->toBe(Oci8Values::canonical(["\xff\x00"]))
        ->and(Oci8Values::canonical([str_repeat('a', 99_999) . 'b']))->not->toBe(Oci8Values::canonical([str_repeat('a', 100_000)]));
});

test('the canonical form of a date carries its zone', function (): void {
    $utc = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $lisbon = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('Europe/Lisbon'));

    expect(Oci8Values::canonical([clone $utc]))->toBe(Oci8Values::canonical([$utc]))
        ->and(Oci8Values::canonical([$lisbon]))->not->toBe(Oci8Values::canonical([$utc]));
});
