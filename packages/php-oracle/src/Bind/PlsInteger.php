<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * PLS_INTEGER / BINARY_INTEGER: a 32-bit signed integer of PL/SQL.
 */
final readonly class PlsInteger extends Bind
{
    public static function in(#[SensitiveParameter] int | null $value): self
    {
        return new self(Type::PlsInteger, Direction::In, $value);
    }

    public static function out(): self
    {
        return new self(Type::PlsInteger, Direction::Out, null);
    }

    public static function inOut(#[SensitiveParameter] int | null $value): self
    {
        return new self(Type::PlsInteger, Direction::InOut, $value);
    }
}
