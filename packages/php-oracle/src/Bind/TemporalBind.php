<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * The factories of the timestamp binds. The date is copied once, here, into
 * a `DateTimeImmutable`, so a `DateTime` changed afterwards does not change
 * the bind.
 *
 * @internal
 */
trait TemporalBind
{
    abstract private static function type(): Type;

    public static function in(#[\SensitiveParameter] ?\DateTimeInterface $value): self
    {
        return new self(self::type(), Direction::In, self::frozen($value));
    }

    public static function out(): self
    {
        return new self(self::type(), Direction::Out, null);
    }

    public static function inOut(#[\SensitiveParameter] ?\DateTimeInterface $value): self
    {
        return new self(self::type(), Direction::InOut, self::frozen($value));
    }

    private static function frozen(#[\SensitiveParameter] ?\DateTimeInterface $value): ?\DateTimeImmutable
    {
        return $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : null;
    }
}
