<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsLob;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT CLOB bind. Sent as `In\Clob` sends it; the server sends back a locator, as `Out\Clob`.
 */
final readonly class Clob implements SendsValue, ReturnsValue
{
    use BindsLob;
}
