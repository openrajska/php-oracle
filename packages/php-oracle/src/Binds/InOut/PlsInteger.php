<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An IN OUT PLS_INTEGER bind: sent as `In\PlsInteger` sends it, read back as the server writes it.
 */
final readonly class PlsInteger implements SendsValue, ReturnsValue
{
    public function __construct(#[SensitiveParameter] public int | null $value)
    {
    }
}
