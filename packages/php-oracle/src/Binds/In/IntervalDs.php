<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsIntervalDs;

/**
 * INTERVAL DAY TO SECOND, from a `DateInterval` of days, hours, minutes,
 * seconds and microseconds (`invert` for a negative one). Years and months
 * have no fixed length in days, so they are rejected: use `In\IntervalYm`.
 * Read back, the interval is a `DateInterval` normalised to days, hours,
 * minutes and seconds.
 */
final readonly class IntervalDs extends Bind
{
    use BindsIntervalDs;
}
