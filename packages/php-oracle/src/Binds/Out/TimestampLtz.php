<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT TIMESTAMP WITH LOCAL TIME ZONE bind: the server writes it, read back as `In\TimestampLtz` describes.
 */
final readonly class TimestampLtz implements ReturnsValue
{
    //
}
