<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsJson;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT JSON bind: sent as `In\Json` sends it, read back as the server writes it.
 */
final readonly class Json implements SendsValue, ReturnsValue
{
    use BindsJson;
}
