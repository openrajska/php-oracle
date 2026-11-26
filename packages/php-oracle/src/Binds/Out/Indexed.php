<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;

/**
 * An OUT Associative array bind of the type named.
 */
final readonly class Indexed extends Bind
{
    public function __construct(public string $typeName)
    {
        parent::__construct(null);
    }
}
