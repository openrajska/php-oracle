<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver;

use Rajska\Oracle\KeyCase;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\ValueMapping;

/**
 * The fully resolved options Core passes on every call: the user's
 * `ExecuteOptions` resolved against the `ConnectionConfig`, plus
 * `autocommit` from Core's Explicit-transaction flag.
 *
 * @internal
 */
final readonly class DriverOptions
{
    /**
     * @param int|float|null $timeout seconds per Round trip; null = no limit
     */
    public function __construct(
        public bool $autocommit,
        public int|float|null $timeout,
        public int $fetchSize,
        public ValueMapping $values,
        public RowShape $rowShape,
        public KeyCase $keyCase,
    ) {
    }
}
