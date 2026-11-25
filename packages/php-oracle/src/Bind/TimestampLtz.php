<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * TIMESTAMP WITH LOCAL TIME ZONE: stores the Instant. Read back, a TSLTZ is
 * the Instant in PHP's default zone.
 */
final readonly class TimestampLtz extends Bind
{
    use TemporalBind;

    private static function type(): Type
    {
        return Type::TimestampLtz;
    }
}
