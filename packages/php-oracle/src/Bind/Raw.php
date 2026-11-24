<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * RAW: bytes, at most 32767.
 */
final readonly class Raw extends Bind
{
    public static function in(?string $bytes): self
    {
        return new self(Type::Raw, Direction::In, $bytes);
    }

    /**
     * Declares the largest buffer: 32767 bytes.
     */
    public static function out(): self
    {
        return new self(Type::Raw, Direction::Out, null);
    }

    /**
     * Declares the largest buffer: 32767 bytes.
     */
    public static function inOut(?string $bytes): self
    {
        return new self(Type::Raw, Direction::InOut, $bytes);
    }
}
