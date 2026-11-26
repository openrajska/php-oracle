<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsLob;

/**
 * An IN OUT CLOB bind. Sent as `In\Clob` sends it; the server sends back a locator, as `Out\Clob`.
 */
final readonly class Clob extends Bind
{
    use BindsLob;
}
