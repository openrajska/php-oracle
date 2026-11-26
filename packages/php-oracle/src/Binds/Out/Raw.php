<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT RAW bind. Declares the largest buffer: 32767 bytes.
 */
final readonly class Raw implements ReturnsValue
{
    //
}
