<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT PLS_INTEGER bind: the server writes it, read back as `In\PlsInteger` describes.
 */
final readonly class PlsInteger extends Bind
{
    use BindsNull;
}
