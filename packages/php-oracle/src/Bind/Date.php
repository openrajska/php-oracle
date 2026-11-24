<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * DATE: stores the object's Wall clock, its zone ignored, and drops the
 * fraction of a second. Read back, a DATE is that Wall clock in PHP's
 * default zone.
 */
final readonly class Date extends Bind
{
    use TemporalBind;

    private static function type(): Type
    {
        return Type::Date;
    }
}
