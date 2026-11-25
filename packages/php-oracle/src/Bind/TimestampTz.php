<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * TIMESTAMP WITH TIME ZONE: stores the Instant with the object's offset at
 * that Instant. An offset in seconds (a zone's local mean time, before about
 * 1900) cannot be stored and fails before any Round trip. Read back, a TSTZ
 * keeps its own offset.
 */
final readonly class TimestampTz extends Bind
{
    use TemporalBind;

    private static function type(): Type
    {
        return Type::TimestampTz;
    }
}
