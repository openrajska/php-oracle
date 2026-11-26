<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT VECTOR bind: the server writes it, read back as `In\Vector` describes.
 */
final readonly class Vector extends Bind
{
    use BindsNull;
}
