<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsLob;

/**
 * An IN OUT NCLOB bind. Sent as `In\NClob` sends it; the server sends back a locator, as `Out\NClob`.
 */
final readonly class NClob extends Bind
{
    use BindsLob;
}
