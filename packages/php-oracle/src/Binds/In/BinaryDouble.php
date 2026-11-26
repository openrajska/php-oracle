<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsFloat;

/**
 * BINARY_DOUBLE: a 64-bit IEEE 754 float, as PHP's `float`. NaN and ±INF bind
 * as themselves.
 */
final readonly class BinaryDouble extends Bind
{
    use BindsFloat;
}
