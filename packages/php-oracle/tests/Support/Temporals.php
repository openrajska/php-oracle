<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

use Closure;
use DateInterval;
use DateTimeImmutable;

/**
 * Temporal values in tests: shown as comparable text, and read under a known
 * PHP default zone.
 */
final class Temporals
{
    /**
     * A temporal value as the tests compare it: a date's fields with its
     * (ISO) signed year, µs and zone (`+2026-03-01 10:00:00.000000 UTC`); an
     * interval's fields with its sign (`-0Y0M3DT4H5M6S.789000`); anything
     * else as it is.
     */
    public static function shown(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeImmutable => $value->format('X-m-d H:i:s.u e'),
            $value instanceof DateInterval => \sprintf(
                '%s%dY%dM%dDT%dH%dM%dS.%06d',
                $value->invert === 1 ? '-' : '+',
                $value->y,
                $value->m,
                $value->d,
                $value->h,
                $value->i,
                $value->s,
                (int) round($value->f * 1e6),
            ),
            default => $value,
        };
    }

    /**
     * Runs `$test` with PHP's default zone set to `$zone`, and returns what
     * it returns. Sessions take the zone when they are created, so each zone
     * gets Pools of its own.
     *
     * @template T
     *
     * @param Closure(): T $test
     *
     * @return T
     */
    public static function inZone(string $zone, Closure $test): mixed
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            return $test();
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
