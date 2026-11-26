<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT Object bind of the type named.
 */
final readonly class Struct implements ReturnsValue
{
    public function __construct(
        public string $name,
    ) {
    }
}
