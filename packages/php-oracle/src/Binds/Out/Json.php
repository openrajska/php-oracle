<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT JSON bind: the server writes it, read back as `In\Json` describes.
 */
final readonly class Json extends Bind
{
    use BindsNull;
}
