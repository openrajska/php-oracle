<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT TIMESTAMP bind: the server writes it, read back as `In\Timestamp` describes.
 */
final readonly class Timestamp extends Bind
{
    use BindsNull;
}
