<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;

/**
 * An OUT Collection bind of the type named.
 */
final readonly class Collection extends Bind
{
    public function __construct(public string $typeName)
    {
        parent::__construct(null);
    }
}
