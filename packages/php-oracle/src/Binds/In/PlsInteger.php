<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * PLS_INTEGER / BINARY_INTEGER: a 32-bit signed integer of PL/SQL.
 */
final readonly class PlsInteger implements SendsValue
{
    public function __construct(#[SensitiveParameter] public int | null $value)
    {
    }
}
