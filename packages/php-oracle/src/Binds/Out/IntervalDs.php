<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT INTERVAL DAY TO SECOND bind: the server writes it, read back as `In\IntervalDs` describes.
 */
final readonly class IntervalDs implements ReturnsValue
{
    //
}
