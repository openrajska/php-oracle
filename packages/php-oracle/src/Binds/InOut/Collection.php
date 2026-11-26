<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An IN OUT Collection bind of the type named: sent as `In\Collection` sends it.
 */
final readonly class Collection implements SendsValue, ReturnsValue
{
    /**
     * @param list<mixed>|null $value
     */
    public function __construct(public string $name, #[SensitiveParameter] public array | null $value)
    {
    }
}
