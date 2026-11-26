<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT NUMBER bind: the server writes it, read back as `In\Number` describes.
 */
final readonly class Number extends Bind
{
    use BindsNull;
}
