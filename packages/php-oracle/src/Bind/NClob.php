<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * NCLOB: text in the national character set, written from UTF-8 (a string
 * or a stream), read back as a `Lob` (the whole text, in UTF-8, under
 * `LobMode::Inline`).
 */
final readonly class NClob extends Bind
{
    use LobBind;

    private static function type(): Type
    {
        return Type::NClob;
    }
}
