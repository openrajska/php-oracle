<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * The factories of the text binds. A `Stringable` is read once, here.
 *
 * @internal
 */
trait TextBind
{
    abstract private static function type(): Type;

    public static function in(#[\SensitiveParameter] string|\Stringable|null $value): self
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
    public static function inOut(#[\SensitiveParameter] string|\Stringable|null $value): self
    {
        return new self(self::type(), Direction::InOut, self::text($value));
    }

    private static function text(#[\SensitiveParameter] string|\Stringable|null $value): ?string
    {
        return $value instanceof \Stringable ? (string) $value : $value;
    }
}
