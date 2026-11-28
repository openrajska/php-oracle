<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsNumber;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT NUMBER bind: sent as `In\Number` sends it, read back as the server writes it.
 */
final readonly class Number implements SendsValue, ReturnsValue
{
    use BindsNumber;
}
