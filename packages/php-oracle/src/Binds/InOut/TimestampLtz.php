<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsTemporal;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT TIMESTAMP WITH LOCAL TIME ZONE bind: sent as `In\TimestampLtz` sends it, read back as the server writes it.
 */
final readonly class TimestampLtz implements SendsValue, ReturnsValue
{
    use BindsTemporal;
}
