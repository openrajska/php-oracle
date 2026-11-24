<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * Options for one Execution. Every null field uses the Connection's default
 * from its ConnectionConfig.
 */
final readonly class ExecuteOptions
{
    /**
     * @param int|float|null $timeout seconds per Round trip
     */
    public function __construct(
        public ?int $fetchSize = null,
        public int|float|null $timeout = null,
        public ?ValueMapping $values = null,
        public ?RowShape $rowShape = null,
        public ?KeyCase $keyCase = null,
    ) {
    }
}
