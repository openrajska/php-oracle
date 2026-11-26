<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT NUMBER bind: the server writes it, read back as `In\Number` describes.
 */
final readonly class Number implements ReturnsValue
{
    //
}
