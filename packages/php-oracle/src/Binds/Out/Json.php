<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT JSON bind: the server writes it, read back as `In\Json` describes.
 */
final readonly class Json implements ReturnsValue
{
    //
}
