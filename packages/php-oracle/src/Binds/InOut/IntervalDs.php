<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsIntervalDs;

/**
 * An IN OUT INTERVAL DAY TO SECOND bind: sent as `In\IntervalDs` sends it, read back as the server writes it.
 */
final readonly class IntervalDs extends Bind
{
    use BindsIntervalDs;
}
