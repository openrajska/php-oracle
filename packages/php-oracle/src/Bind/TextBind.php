<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;
use Stringable;

/**
 * The factories of the text binds. A `Stringable` is read once, here.
 *
 * @internal
 */
trait TextBind
{
    abstract private static function type(): Type;

    public static function in(#[SensitiveParameter] Stringable | string | null $value): self
    {
        return new self(self::type(), Direction::In, self::text($value));
    }

    /**
     * Declares the largest buffer: 32767 bytes in PL/SQL.
     */
    public static function out(): self
    {
        return new self(self::type(), Direction::Out, null);
    }

    /**
     * Declares the largest buffer: 32767 bytes in PL/SQL.
     */
    public static function inOut(#[SensitiveParameter] Stringable | string | null $value): self
    {
        return new self(self::type(), Direction::InOut, self::text($value));
    }

    private static function text(#[SensitiveParameter] Stringable | string | null $value): string | null
    {
        return $value instanceof Stringable ? (string) $value : $value;
    }
}
