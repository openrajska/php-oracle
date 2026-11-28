<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An IN OUT BOOLEAN bind: sent as `In\Boolean` sends it, read back as the server writes it.
 */
final readonly class Boolean implements SendsValue, ReturnsValue
{
    public function __construct(#[SensitiveParameter] public bool | null $value)
    {
    }
}
