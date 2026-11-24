<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * VARCHAR2: UTF-8 text, at most 32767 bytes.
 */
final readonly class Varchar extends Bind
{
    use TextBind;

    private static function type(): Type
    {
        return Type::Varchar;
    }
}
