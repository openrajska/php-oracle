<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT VECTOR bind: the server writes it, read back as `In\Vector` describes.
 */
final readonly class Vector implements ReturnsValue
{
    //
}
