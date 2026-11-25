<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * NUMBER, exact. A float binds as the decimal its shortest round-trip text
 * writes (`0.1` stores `0.1`); a string must be a decimal literal with `.`,
 * independent of NLS and with no exponent (`'12.30'`, `'-.5'`).
 */
final readonly class Number extends Bind
{
    /**
     * @throws UsageException if the string is not a decimal literal
     */
    public static function in(#[SensitiveParameter] int|float|string|null $value): self
    {
        return new self(Type::Number, Direction::In, self::checked($value));
    }

    public static function out(): self
    {
        return new self(Type::Number, Direction::Out, null);
    }

    /**
     * @throws UsageException if the string is not a decimal literal
     */
    public static function inOut(#[SensitiveParameter] int|float|string|null $value): self
    {
        return new self(Type::Number, Direction::InOut, self::checked($value));
    }

    private static function checked(#[SensitiveParameter] int|float|string|null $value): int|float|string|null
    {
        if (\is_string($value) && preg_match('/\A[+-]?(\d+(\.\d*)?|\.\d+)\z/', $value) !== 1) {
            throw new UsageException("Number::in() takes a decimal literal with '.' as the separator, such as '12.30'; the string given is not one.");
        }

        return $value;
    }
}
