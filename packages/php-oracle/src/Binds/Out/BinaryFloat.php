<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT BINARY_FLOAT bind: the server writes it, read back as `In\BinaryFloat` describes.
 */
final readonly class BinaryFloat implements ReturnsValue
{
    //
}
