<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT DATE bind: the server writes it, read back as `In\Date` describes.
 */
final readonly class Date extends Bind
{
    use BindsNull;
}
