<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\ValueMapping;

/**
 * Conformance catalog, Values (#23).
 */
final class ValuesTest extends ConformanceTestCase
{
    /**
     * Values 1: `int` when integral and within 64 bits (decided per value),
     * else the canonical exact string, whatever the NLS settings.
     */
    #[Test]
    public function number_maps_to_int_or_the_canonical_exact_string(): void
    {
        $tiny = '0.'.str_repeat('0', 129).'1';
        $expected = [
            'ONE' => 1,
            'MINUS_ONE' => -1,
            'ZERO' => 0,
            'INT_MAX' => PHP_INT_MAX,
            'INT_MIN' => PHP_INT_MIN,
            'TWO_POW_63' => '9223372036854775808',
            'BELOW_INT_MIN' => '-9223372036854775809',
            'E30' => '1000000000000000000000000000000',
            'TWELVE_30' => '12.3',
            'HALF' => '0.5',
            'MINUS_HALF' => '-0.5',
            'DIGITS_38' => '1234567890123456789.0123456789012345678',
            'TINY' => $tiny,
            'COUNTED' => 3,
            'SUMMED' => 6,
            'UNCONSTRAINED' => 5,
        ];
        $sql = <<<'SQL'
            select 1 one, -1 minus_one, 0 zero,
                   9223372036854775807 int_max, -9223372036854775808 int_min,
                   9223372036854775808 two_pow_63, -9223372036854775809 below_int_min,
                   1e30 e30, 12.30 twelve_30, 0.5 half, -0.5 minus_half,
                   1234567890123456789.0123456789012345678 digits_38, 1e-130 tiny,
                   (select count(*) from fx_items where id <= 3) counted,
                   (select sum(id) from fx_items where id <= 3) summed,
                   cast(5 as number) unconstrained
              from dual
            SQL;

        $connection = $this->connect();
        self::assertSame([$expected], self::rows($connection, $sql));

        $connection->execute("alter session set nls_numeric_characters = ',.'", [], self::options());
        self::assertSame([$expected], self::rows($connection, $sql));
    }

    /**
     * Values 2, query part: `numbers: Float` turns a non-integral NUMBER
     * into a float; an integral one stays an int.
     */
    #[Test]
    public function numbers_float_maps_a_non_integral_number_to_a_float(): void
    {
        $float = self::options(values: new ValueMapping(numbers: NumberMode::Float));

        self::assertSame(
            [['HALF' => 12.5, 'FIVE' => 5, 'TENTH' => 0.1, 'NOTHING' => null]],
            self::rows($this->connect(), 'select 12.5 half, 5 five, 0.1 tenth, cast(null as number) nothing from dual', options: $float),
        );
    }
}
