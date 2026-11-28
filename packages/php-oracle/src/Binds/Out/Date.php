<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT DATE bind: the server writes it, read back as `In\Date` describes.
 */
final readonly class Date implements ReturnsValue
{
    //
}
