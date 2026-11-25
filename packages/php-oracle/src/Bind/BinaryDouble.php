<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * BINARY_DOUBLE: a 64-bit IEEE 754 float, as PHP's `float`. NaN and ±INF bind
 * as themselves.
 */
final readonly class BinaryDouble extends Bind
{
    public static function in(#[SensitiveParameter] float | int | null $value): self
    {
        return new self(Type::BinaryDouble, Direction::In, $value === null ? null : (float) $value);
    }

    public static function out(): self
    {
        return new self(Type::BinaryDouble, Direction::Out, null);
    }

    public static function inOut(#[SensitiveParameter] float | int | null $value): self
    {
        return new self(Type::BinaryDouble, Direction::InOut, $value === null ? null : (float) $value);
    }
}
