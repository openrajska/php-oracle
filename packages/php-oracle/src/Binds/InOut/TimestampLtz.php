<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsTemporal;

/**
 * An IN OUT TIMESTAMP WITH LOCAL TIME ZONE bind: sent as `In\TimestampLtz` sends it, read back as the server writes it.
 */
final readonly class TimestampLtz extends Bind
{
    use BindsTemporal;
}
