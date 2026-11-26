<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNumber;

/**
 * NUMBER, exact. A float binds as the decimal its shortest round-trip text
 * writes (`0.1` stores `0.1`); a string must be a decimal literal with `.`,
 * independent of NLS and with no exponent (`'12.30'`, `'-.5'`).
 */
final readonly class Number extends Bind
{
    use BindsNumber;
}
