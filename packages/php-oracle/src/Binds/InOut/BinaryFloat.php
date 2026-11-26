<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsFloat;

/**
 * An IN OUT BINARY_FLOAT bind: sent as `In\BinaryFloat` sends it, read back as the server writes it.
 */
final readonly class BinaryFloat extends Bind
{
    use BindsFloat;
}
