<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT Object bind of the type named: sent as `In\Struct` sends it.
 */
final readonly class Struct extends Bind
{
    /**
     * @param array<string, mixed>|null $value
     */
    public function __construct(public string $typeName, #[SensitiveParameter] array | null $value)
    {
        parent::__construct($value);
    }
}
