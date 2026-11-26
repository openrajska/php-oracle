<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsVector;

/**
 * An IN OUT VECTOR bind: sent as `In\Vector` sends it, read back as the server writes it.
 */
final readonly class Vector extends Bind
{
    use BindsVector;
}
