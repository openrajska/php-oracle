<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bench;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * What an application over oci8 does to get Core's values out of oci8's
 * strings, plus a canonical form to prove both sides return the same thing.
 *
 * oci8 runs with the session formats of `Scenarios::OCI8_FORMATS`, so a DATE
 * arrives as 'YYYY-MM-DD HH24:MI:SS' and a TIMESTAMP with nine fractional
 * digits: Core's `TemporalMode::String` text.
 */
final class Oci8Values
{
    /**
     * A NUMBER as `NumberMode::Exact` gives it: an int when integral and
     * within 64 bits, else the exact decimal text with its leading zero.
     */
    public static function exact(string $number): int | string
    {
        $int = (int) $number;
        if ((string) $int === $number) {
            return $int;
        }
        if ($number[0] === '.') {
            return '0' . $number;
        }
        if (str_starts_with($number, '-.')) {
            return '-0' . substr($number, 1);
        }

        return $number;
    }

    /**
     * A NUMBER as `NumberMode::Float` gives it: an int when integral and
     * within 64 bits, else a float.
     */
    public static function float(string $number): float | int
    {
        $int = (int) $number;

        return (string) $int === $number ? $int : (float) $number;
    }

    /**
     * A DATE or TIMESTAMP as `TemporalMode::Object` gives it: the Wall clock
     * in PHP's default time zone.
     */
    public static function dateTime(string $text): DateTimeImmutable
    {
        return new DateTimeImmutable($text);
    }

    /**
     * Rows as one string where every value carries its type, so `===` on
     * two canonical forms means the same keys, types and values.
     *
     * @param array<mixed> $rows
     */
    public static function canonical(array $rows): string
    {
        return json_encode(self::tag($rows), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function tag(mixed $value): mixed
    {
        return match (true) {
            \is_array($value) => array_map(self::tag(...), $value),
            $value instanceof DateTimeInterface => 'date:' . $value->format('Y-m-d H:i:s.u e'),
            \is_float($value) => 'float:' . var_export($value, true),
            \is_object($value) => 'object:' . $value::class,
            // A digest keeps a 16 MiB LOB out of the JSON; hex keeps bytes in.
            \is_string($value) && \strlen($value) > 256 => 'string#' . \strlen($value) . ':' . md5($value),
            \is_string($value) && preg_match('//u', $value) !== 1 => 'bytes:' . bin2hex($value),
            default => get_debug_type($value) . ':' . var_export($value, true),
        };
    }
}
