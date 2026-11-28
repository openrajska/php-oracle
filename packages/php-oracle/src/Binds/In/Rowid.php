<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * ROWID, as its text (`'AAAR3sAAEAAAACXAAA'`).
 */
final readonly class Rowid implements SendsValue
{
    public function __construct(#[SensitiveParameter] public string | null $value)
    {
    }
}
