<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT ROWID bind: the server writes it, read back as `In\Rowid` describes.
 */
final readonly class Rowid implements ReturnsValue
{
    //
}
