<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * BINARY_FLOAT: a 32-bit IEEE 754 float. NaN and ±INF bind as themselves.
 */
final readonly class BinaryFloat extends Bind
{
    public static function in(#[SensitiveParameter] int|float|null $value): self
    {
        return new self(Type::BinaryFloat, Direction::In, $value === null ? null : (float) $value);
    }

    public static function out(): self
    {
        return new self(Type::BinaryFloat, Direction::Out, null);
    }

    public static function inOut(#[SensitiveParameter] int|float|null $value): self
    {
        return new self(Type::BinaryFloat, Direction::InOut, $value === null ? null : (float) $value);
    }
}
