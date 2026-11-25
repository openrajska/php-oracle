<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * BOOLEAN, in SQL and PL/SQL.
 */
final readonly class Boolean extends Bind
{
    public static function in(#[SensitiveParameter] ?bool $value): self
    {
        return new self(Type::Boolean, Direction::In, $value);
    }

    public static function out(): self
    {
        return new self(Type::Boolean, Direction::Out, null);
    }

    public static function inOut(#[SensitiveParameter] ?bool $value): self
    {
        return new self(Type::Boolean, Direction::InOut, $value);
    }
}
