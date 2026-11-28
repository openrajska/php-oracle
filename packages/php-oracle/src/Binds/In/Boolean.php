<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * BOOLEAN, in SQL and PL/SQL.
 */
final readonly class Boolean implements SendsValue
{
    public function __construct(#[SensitiveParameter] public bool | null $value)
    {
    }
}
