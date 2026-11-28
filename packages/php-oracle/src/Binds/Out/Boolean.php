<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT BOOLEAN bind: the server writes it, read back as `In\Boolean` describes.
 */
final readonly class Boolean implements ReturnsValue
{
    //
}
