<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Type;

/**
 * Native JSON, from any value `json_encode()` takes, read back like
 * `json_decode($text, true)`.
 *
 * ```php
 * Json::in(['name' => 'Ada', 'tags' => ['x', 'y'], 'score' => 0.1]);
 * ```
 *
 * - An associative array becomes an object, a `list` an array. `[]` is an
 *   empty array; use `new \stdClass()` for an empty object.
 * - A `float` becomes an exact NUMBER (`0.1` stores `0.1`); NaN and INF
 *   fail.
 * - A `JsonSerializable` is expanded, a `BackedEnum` becomes its value, and
 *   any other object its public properties, as `json_encode()` does.
 * - A `DateTimeInterface` becomes a TIMESTAMP: its Wall clock, its zone
 *   ignored.
 * - Strings must be UTF-8. `in(null)` is SQL NULL; `null` inside the value
 *   is JSON `null`.
 *
 * Objects are expanded when the bind is built.
 */
final readonly class Json extends Bind
{
    /** How deep a value may nest, as `json_encode()` allows by default. */
    private const int DEPTH = 512;

    /**
     * @throws UsageException if the value cannot be encoded as JSON
     */
    public static function in(mixed $value): self
    {
        return new self(Type::Json, Direction::In, self::encodable($value, 0));
    }

    public static function out(): self
    {
        return new self(Type::Json, Direction::Out, null);
    }

    /**
     * @throws UsageException if the value cannot be encoded as JSON
     */
    public static function inOut(mixed $value): self
    {
        return new self(Type::Json, Direction::InOut, self::encodable($value, 0));
    }

    /**
     * The value with every object other than `stdClass` and
     * `DateTimeInterface` expanded, as `json_encode()` would see it.
     */
    private static function encodable(mixed $value, int $depth): mixed
    {
        if ($depth > self::DEPTH) {
            throw new UsageException(\sprintf('Json::in() takes values nested at most %d levels deep.', self::DEPTH));
        }

        return match (true) {
            $value === null, \is_bool($value), \is_int($value), \is_string($value), $value instanceof \DateTimeInterface => $value,
            \is_float($value) => is_finite($value) ? $value : throw new UsageException('Json::in(): JSON cannot hold NAN or INF.'),
            \is_array($value) => array_map(static fn (mixed $element): mixed => self::encodable($element, $depth + 1), $value),
            $value instanceof \JsonSerializable => self::encodable($value->jsonSerialize(), $depth + 1),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => throw new UsageException(\sprintf('Json::in(): the enum %s has no backing value to encode.', $value::class)),
            \is_object($value) => (object) array_map(
                static fn (mixed $property): mixed => self::encodable($property, $depth + 1),
                get_object_vars($value),
            ),
            default => throw new UsageException(\sprintf('Json::in(): JSON cannot hold a %s.', get_debug_type($value))),
        };
    }
}
