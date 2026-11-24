<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * BLOB: bytes, from a string or a stream, read back as a `Lob` (the bytes
 * under `LobMode::Inline`).
 */
final readonly class Blob extends Bind
{
    use LobBind;

    private static function type(): Type
    {
        return Type::Blob;
    }
}
