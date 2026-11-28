<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsFloat;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT BINARY_DOUBLE bind: sent as `In\BinaryDouble` sends it, read back as the server writes it.
 */
final readonly class BinaryDouble implements SendsValue, ReturnsValue
{
    use BindsFloat;
}
