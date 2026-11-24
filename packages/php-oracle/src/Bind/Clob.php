<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * CLOB: UTF-8 text, from a string or a stream, read back as a `Lob` (the
 * whole text under `LobMode::Inline`).
 */
final readonly class Clob extends Bind
{
    use LobBind;

    private static function type(): Type
    {
        return Type::Clob;
    }
}
