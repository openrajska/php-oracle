<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsTemporal;

/**
 * An IN OUT DATE bind: sent as `In\Date` sends it, read back as the server writes it.
 */
final readonly class Date extends Bind
{
    use BindsTemporal;
}
