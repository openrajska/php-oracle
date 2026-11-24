<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver;

/**
 * The result of one Array DML execute.
 *
 * @internal
 */
interface DriverBatch
{
    /**
     * The total DML count across every bind row.
     */
    public function rowCount(): int;

    /**
     * One value per bind row, in row order, keyed by bind name.
     *
     * @return array<string, list<mixed>>
     */
    public function outValues(): array;
}
