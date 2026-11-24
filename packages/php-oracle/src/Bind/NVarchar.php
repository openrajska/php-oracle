<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * NVARCHAR2: text in the national character set.
 */
final readonly class NVarchar extends Bind
{
    use TextBind;

    private static function type(): Type
    {
        return Type::NVarchar;
    }
}
