<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT RAW bind. Declares the largest buffer: 32767 bytes.
 */
final readonly class Raw extends Bind
{
    use BindsNull;
}
