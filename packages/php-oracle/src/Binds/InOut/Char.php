<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Concerns\BindsText;
use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;

/**
 * An IN OUT CHAR bind. Declares the largest buffer: 32767 bytes in PL/SQL.
 */
final readonly class Char implements SendsValue, ReturnsValue
{
    use BindsText;
}
