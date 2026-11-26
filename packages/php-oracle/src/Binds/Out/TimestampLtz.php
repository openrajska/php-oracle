<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT TIMESTAMP WITH LOCAL TIME ZONE bind: the server writes it, read back as `In\TimestampLtz` describes.
 */
final readonly class TimestampLtz extends Bind
{
    use BindsNull;
}
