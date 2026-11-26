<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT Associative array bind of the type named.
 */
final readonly class Indexed implements ReturnsValue
{
    public function __construct(
        public string $name,
    ) {
    }
}
