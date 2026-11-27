<?php

declare(strict_types = 1);

use Rajska\Oracle\Connection;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\TestCase;

/**
 * The Connection a test file creates and seeds its objects through: one
 * standalone Session per process. Its DDL waits for a Session a previous test
 * killed, while Oracle rolls that transaction back and holds the table.
 */
function schema(): Connection
{
    static $connection = null;
    if (!$connection instanceof Connection) {
        $connection = Oracle::connect(TestCase::config(standalone: true));
        $connection->run('alter session set ddl_lock_timeout = 10');
    }

    return $connection;
}

/**
 * A temporal value as the tests compare it: a date's fields with its signed
 * year, µs and zone (`+2026-03-01 10:00:00.000000 UTC`); an interval's fields
 * with its sign (`-0Y0M3DT4H5M6S.789000`); anything else as it is.
 */
function temporalText(mixed $value): mixed
{
    return match (true) {
        $value instanceof DateTimeImmutable => $value->format('X-m-d H:i:s.u e'),
        $value instanceof DateInterval => sprintf(
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
 * Runs $test with PHP's default zone set to $zone. Sessions take the zone
 * when they are created, so each zone needs Pools of its own.
 *
 * @template T
 *
 * @param Closure(): T $test
 *
 * @return T
 */
function inTimeZone(string $zone, Closure $test): mixed
{
    $previous = date_default_timezone_get();
    date_default_timezone_set($zone);

    try {
        return $test();
    } finally {
        date_default_timezone_set($previous);
    }
}
