<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Exceptions\UsageException;

/**
 * Options for one Execution and every Cursor and OUT value it produces.
 * Every null field uses the Connection's default from its ConnectionConfig.
 */
final readonly class ExecuteOptions
{
    /**
     * @param ?int $fetchSize rows per Round trip, >= 1
     * @param int|float|null $timeout seconds per Round trip, > 0
     *
     * @throws UsageException for a fetchSize below 1 or a timeout that is not a positive number of seconds
     */
    public function __construct(
        public ?int $fetchSize = null,
        public int|float|null $timeout = null,
        public ?ValueMapping $values = null,
        public ?RowShape $rowShape = null,
        public ?KeyCase $keyCase = null,
    ) {
        if ($fetchSize !== null && $fetchSize < 1) {
            throw new UsageException("ExecuteOptions: fetchSize must be at least 1, got $fetchSize.");
        }
        if ($timeout !== null && (!($timeout > 0) || !is_finite($timeout))) {
            throw new UsageException("ExecuteOptions: timeout must be a positive number of seconds or null, got $timeout.");
        }
    }
}
