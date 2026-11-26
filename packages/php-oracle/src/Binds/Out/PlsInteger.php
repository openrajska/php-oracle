<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT PLS_INTEGER bind: the server writes it, read back as `In\PlsInteger` describes.
 */
final readonly class PlsInteger implements ReturnsValue
{
    //
}
