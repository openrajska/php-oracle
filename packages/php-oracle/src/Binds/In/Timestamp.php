<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsTemporal;

/**
 * TIMESTAMP: stores the object's Wall clock, its zone ignored, to the
 * microsecond. Read back, a TIMESTAMP is that Wall clock in PHP's default
 * zone.
 */
final readonly class Timestamp extends Bind
{
    use BindsTemporal;
}
