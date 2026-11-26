<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT BINARY_DOUBLE bind: the server writes it, read back as `In\BinaryDouble` describes.
 */
final readonly class BinaryDouble extends Bind
{
    use BindsNull;
}
