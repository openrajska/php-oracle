<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsIntervalYm;
use Rajska\Oracle\Binds\SendsValue;

/**
 * INTERVAL YEAR TO MONTH, from a `DateInterval` of years and months
 * (`invert` for a negative one). Anything below a month is rejected: use
 * `In\IntervalDs`. Read back, the interval is a `DateInterval` of years and
 * months.
 */
final readonly class IntervalYm implements SendsValue
{
    use BindsIntervalYm;
}
