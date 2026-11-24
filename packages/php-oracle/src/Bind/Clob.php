<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * CLOB: text, read back as a `Lob` (the whole text under
 * `LobMode::Inline`).
 */
final readonly class Clob extends Bind
{
    /**
     * The server sends back a locator: for a value PL/SQL built, a
     * temporary LOB, valid until the Connection's Release.
     */
    public static function out(): self
    {
        return new self(Type::Clob, Direction::Out, null);
    }
}
