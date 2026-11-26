<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT INTERVAL YEAR TO MONTH bind: the server writes it, read back as `In\IntervalYm` describes.
 */
final readonly class IntervalYm extends Bind
{
    use BindsNull;
}
