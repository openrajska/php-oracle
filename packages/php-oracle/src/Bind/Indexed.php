<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * An Associative array (`TABLE OF … INDEX BY PLS_INTEGER`, declared in a
 * package) as `array<int, mixed>`: keys may be sparse or negative, and they
 * come back in key order.
 *
 * ```php
 * Indexed::in('hr.payroll.amounts', [10 => 1.5, -3 => 2]);
 * ```
 *
 * - The type is named as `Struct`'s is, usually `PACKAGE.TYPE`. Elements
 *   map as `Struct`'s attributes do.
 * - Keys must be `int`s in the 32-bit signed range. `INDEX BY VARCHAR2` is
 *   not supported yet and fails loudly.
 * - `in(null)` is a NULL of the type.
 */
final readonly class Indexed extends Bind
{
    /**
     * @param array<int, mixed>|null $value
     */
    public static function in(string $type, #[SensitiveParameter] ?array $value): self
    {
        return new self(Type::AssociativeArray, Direction::In, $value, $type);
    }

    public static function out(string $type): self
    {
        return new self(Type::AssociativeArray, Direction::Out, null, $type);
    }

    /**
     * @param array<int, mixed>|null $value
     */
    public static function inOut(string $type, #[SensitiveParameter] ?array $value): self
    {
        return new self(Type::AssociativeArray, Direction::InOut, $value, $type);
    }
}
