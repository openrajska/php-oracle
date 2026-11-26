<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT Collection bind of the type named: sent as `In\Collection` sends it.
 */
final readonly class Collection extends Bind
{
    /**
     * @param list<mixed>|null $value
     */
    public function __construct(public string $typeName, #[SensitiveParameter] array | null $value)
    {
        parent::__construct($value);
    }
}
