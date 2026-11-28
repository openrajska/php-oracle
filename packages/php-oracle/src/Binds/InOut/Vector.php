<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsVector;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT VECTOR bind: sent as `In\Vector` sends it, read back as the server writes it.
 */
final readonly class Vector implements SendsValue, ReturnsValue
{
    use BindsVector;
}
