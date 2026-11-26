<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An IN OUT RAW bind. Declares the largest buffer: 32767 bytes.
 */
final readonly class Raw implements SendsValue, ReturnsValue
{
    public function __construct(#[SensitiveParameter] public string | null $value)
    {
    }
}
