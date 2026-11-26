<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNumber;

/**
 * An IN OUT NUMBER bind: sent as `In\Number` sends it, read back as the server writes it.
 */
final readonly class Number extends Bind
{
    use BindsNumber;
}
