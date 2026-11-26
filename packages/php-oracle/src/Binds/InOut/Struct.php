<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An IN OUT Object bind of the type named: sent as `In\Struct` sends it.
 */
final readonly class Struct implements SendsValue, ReturnsValue
{
    /**
     * @param array<string, mixed>|null $value
     */
    public function __construct(public string $name, #[SensitiveParameter] public array | null $value)
    {
    }
}
