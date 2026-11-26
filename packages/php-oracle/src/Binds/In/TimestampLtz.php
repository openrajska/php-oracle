<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsTemporal;

/**
 * TIMESTAMP WITH LOCAL TIME ZONE: stores the Instant. Read back, a TSLTZ is
 * the Instant in PHP's default zone.
 */
final readonly class TimestampLtz extends Bind
{
    use BindsTemporal;
}
