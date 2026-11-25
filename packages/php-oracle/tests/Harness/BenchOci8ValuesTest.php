<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bench\Oci8Values;

/**
 * The benchmark's oci8 side turns oci8's strings into the values Core
 * returns, the way an application over oci8 would have to.
 */
final class BenchOci8ValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|string}>
     */
    public static function exactNumbers(): iterable
    {
        yield 'an integer' => ['42', 42];
        yield 'a negative integer' => ['-7', -7];
        yield 'zero' => ['0', 0];
        yield 'a decimal' => ['12.3', '12.3'];
        yield 'oci8 drops the leading zero' => ['.5', '0.5'];
        yield 'and keeps the sign' => ['-.25', '-0.25'];
        yield 'beyond 64 bits stays a string' => ['12345678901234567890', '12345678901234567890'];
        yield 'the smallest 64-bit integer' => ['-9223372036854775808', PHP_INT_MIN];
    }

    #[Test]
    #[DataProvider('exactNumbers')]
    public function an_exact_number_is_an_int_when_integral_and_within_64_bits(string $oci8, int | string $core): void
    {
        self::assertSame($core, Oci8Values::exact($oci8));
    }

    /**
     * @return iterable<string, array{string, float|int}>
     */
    public static function floatNumbers(): iterable
    {
        yield 'an integer' => ['42', 42];
        yield 'a decimal' => ['12.5', 12.5];
        yield 'oci8 drops the leading zero' => ['.5', 0.5];
        yield 'and keeps the sign' => ['-.25', -0.25];
    }

    #[Test]
    #[DataProvider('floatNumbers')]
    public function a_float_number_is_an_int_when_integral(string $oci8, float | int $core): void
    {
        self::assertSame($core, Oci8Values::float($oci8));
    }

    #[Test]
    public function a_date_in_the_benchmark_session_format_is_the_wall_clock_in_the_default_zone(): void
    {
        $date = Oci8Values::dateTime('2026-01-01 00:00:00.003000000');

        self::assertSame('2026-01-01 00:00:00.003000', $date->format('Y-m-d H:i:s.u'));
        self::assertSame(date_default_timezone_get(), $date->getTimezone()->getName());
    }

    #[Test]
    public function the_canonical_form_tells_types_apart(): void
    {
        self::assertNotSame(Oci8Values::canonical(['A' => 1]), Oci8Values::canonical(['A' => '1']));
        self::assertNotSame(Oci8Values::canonical(['A' => 1]), Oci8Values::canonical(['A' => 1.0]));
        self::assertNotSame(Oci8Values::canonical(['A' => 1]), Oci8Values::canonical(['B' => 1]));
    }

    #[Test]
    public function the_canonical_form_takes_bytes_and_large_strings(): void
    {
        self::assertNotSame(Oci8Values::canonical(["\xff\x00"]), Oci8Values::canonical(["\xff\x01"]));
        self::assertNotSame(
            Oci8Values::canonical([str_repeat('a', 100_000)]),
            Oci8Values::canonical([str_repeat('a', 99_999) . 'b']),
        );
    }

    #[Test]
    public function the_canonical_form_of_a_date_carries_its_zone(): void
    {
        $utc = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $lisbon = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('Europe/Lisbon'));

        self::assertSame(Oci8Values::canonical([$utc]), Oci8Values::canonical([clone $utc]));
        self::assertNotSame(Oci8Values::canonical([$utc]), Oci8Values::canonical([$lisbon]));
    }
}
