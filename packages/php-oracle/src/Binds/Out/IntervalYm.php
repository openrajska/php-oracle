<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT INTERVAL YEAR TO MONTH bind: the server writes it, read back as `In\IntervalYm` describes.
 */
final readonly class IntervalYm implements ReturnsValue
{
    //
}
