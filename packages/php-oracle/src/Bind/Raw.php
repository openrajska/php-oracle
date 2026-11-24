<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * RAW: bytes, at most 32767. It also writes a LONG RAW column; more than
 * 32767 bytes would take a LONG bind, which is not supported yet
 * (UnsupportedException). Bind a BLOB's longer bytes with `Blob::in()`.
 */
final readonly class Raw extends Bind
{
    public static function in(#[\SensitiveParameter] ?string $bytes): self
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
    public static function inOut(#[\SensitiveParameter] ?string $bytes): self
    {
        return new self(Type::Raw, Direction::InOut, $bytes);
    }
}
