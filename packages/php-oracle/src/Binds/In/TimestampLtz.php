<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsTemporal;
use Rajska\Oracle\Binds\SendsValue;

/**
 * TIMESTAMP WITH LOCAL TIME ZONE: stores the Instant. Read back, a TSLTZ is
 * the Instant in PHP's default zone.
 */
final readonly class TimestampLtz implements SendsValue
{
    use BindsTemporal;
}
