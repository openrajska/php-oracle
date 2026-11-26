<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Contracts\Driver;

/**
 * The result of one Array DML execute.
 *
 * @internal
 */
interface ArrayDml
{
    /**
     * The total DML count across every bind row.
     */
    public function rowCount(): int;

    /**
     * Per OUT or IN OUT bind, keyed by bind name as written: one entry per
     * bind row, in row order, each the list of values that row produced
     * (one for a PL/SQL OUT, one per returned row for `RETURNING`).
     *
     * @return array<string, list<list<mixed>>>
     */
    public function outValues(): array;
}
