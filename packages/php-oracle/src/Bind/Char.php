<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * CHAR: fixed-length text, compared blank-padded (`Char::in('AB')` matches a CHAR(5) `'AB   '`).
 */
final readonly class Char extends Bind
{
    use TextBind;

    private static function type(): Type
    {
        return Type::Char;
    }
}
