<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * The tracing attributes a DBA sees in V$SESSION. Unset ones are empty.
 */
final readonly class Tracing
{
    public function __construct(
        public ?string $module = null,
        public ?string $action = null,
        public ?string $clientIdentifier = null,
        public ?string $clientInfo = null,
    ) {
    }
}
