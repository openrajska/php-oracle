<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * NCHAR: fixed-length text in the national character set.
 */
final readonly class NChar extends Bind
{
    use TextBind;

    private static function type(): Type
    {
        return Type::NChar;
    }
}
