<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT BINARY_FLOAT bind: the server writes it, read back as `In\BinaryFloat` describes.
 */
final readonly class BinaryFloat extends Bind
{
    use BindsNull;
}
