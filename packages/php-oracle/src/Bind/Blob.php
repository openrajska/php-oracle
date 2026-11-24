<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * BLOB: bytes, read back as a `Lob` (the bytes under `LobMode::Inline`).
 */
final readonly class Blob extends Bind
{
    /**
     * The server sends back a locator: for a value PL/SQL built, a
     * temporary LOB, valid until the Connection's Release.
     */
    public static function out(): self
    {
        return new self(Type::Blob, Direction::Out, null);
    }
}
