<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsIntervalYm;

/**
 * An IN OUT INTERVAL YEAR TO MONTH bind: sent as `In\IntervalYm` sends it, read back as the server writes it.
 */
final readonly class IntervalYm extends Bind
{
    use BindsIntervalYm;
}
