<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * One failing row of an Array DML call.
 */
final readonly class RowFailure
{
    /**
     * @param int $index the 0-based position of the row in the rows passed
     * @param int $code the ORA number
     */
    public function __construct(
        public int $index,
        public int $code,
        public string $message,
    ) {
    }
}
