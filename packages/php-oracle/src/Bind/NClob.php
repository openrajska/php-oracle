<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * NCLOB: text in the national character set, read back as a `Lob` (the
 * whole text, in UTF-8, under `LobMode::Inline`).
 */
final readonly class NClob extends Bind
{
    /**
     * The server sends back a locator: for a value PL/SQL built, a
     * temporary LOB, valid until the Connection's Release.
     */
    public static function out(): self
    {
        return new self(Type::NClob, Direction::Out, null);
    }
}
