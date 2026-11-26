<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT Associative array bind of the type named: sent as `In\Indexed` sends it.
 */
final readonly class Indexed extends Bind
{
    /**
     * @param array<int, mixed>|null $value
     */
    public function __construct(public string $typeName, #[SensitiveParameter] array | null $value)
    {
        parent::__construct($value);
    }
}
