<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT BINARY_DOUBLE bind: the server writes it, read back as `In\BinaryDouble` describes.
 */
final readonly class BinaryDouble implements ReturnsValue
{
    //
}
