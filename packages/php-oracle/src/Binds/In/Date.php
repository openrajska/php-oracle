<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsTemporal;

/**
 * DATE: stores the object's Wall clock, its zone ignored, and drops the
 * fraction of a second. Read back, a DATE is that Wall clock in PHP's
 * default zone.
 */
final readonly class Date extends Bind
{
    use BindsTemporal;
}
