<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsFloat;
use Rajska\Oracle\Binds\SendsValue;

/**
 * BINARY_FLOAT: a 32-bit IEEE 754 float. NaN and ±INF bind as themselves.
 */
final readonly class BinaryFloat implements SendsValue
{
    use BindsFloat;
}
