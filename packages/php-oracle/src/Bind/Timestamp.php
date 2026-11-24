<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * TIMESTAMP: stores the object's Wall clock, its zone ignored, to the
 * microsecond. Read back, a TIMESTAMP is that Wall clock in PHP's default
 * zone.
 */
final readonly class Timestamp extends Bind
{
    use TemporalBind;

    private static function type(): Type
    {
        return Type::Timestamp;
    }
}
