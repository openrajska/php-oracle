<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * VARCHAR2: UTF-8 text, at most 32767 bytes. It also writes a LONG column;
 * more than 32767 bytes would take a LONG bind, which is not supported yet
 * (UnsupportedException). Bind a CLOB's longer text with `Clob::in()`.
 */
final readonly class Varchar extends Bind
{
    use TextBind;

    private static function type(): Type
    {
        return Type::Varchar;
    }
}
