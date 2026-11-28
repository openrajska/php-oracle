<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT TIMESTAMP WITH TIME ZONE bind: the server writes it, read back as `In\TimestampTz` describes.
 */
final readonly class TimestampTz implements ReturnsValue
{
    //
}
