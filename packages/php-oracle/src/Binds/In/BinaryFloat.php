<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsFloat;

/**
 * BINARY_FLOAT: a 32-bit IEEE 754 float. NaN and ±INF bind as themselves.
 */
final readonly class BinaryFloat extends Bind
{
    use BindsFloat;
}
