<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT Collection bind of the type named.
 */
final readonly class Collection implements ReturnsValue
{
    public function __construct(
        public string $name,
    ) {
    }
}
