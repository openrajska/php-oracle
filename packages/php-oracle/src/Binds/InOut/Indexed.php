<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An IN OUT Associative array bind of the type named: sent as `In\Indexed` sends it.
 */
final readonly class Indexed implements SendsValue, ReturnsValue
{
    /**
     * @param array<int, mixed>|null $value
     */
    public function __construct(public string $name, #[SensitiveParameter] public array | null $value)
    {
    }
}
