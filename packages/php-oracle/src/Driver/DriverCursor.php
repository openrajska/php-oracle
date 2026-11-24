<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver;

/**
 * The rows of one query, read batch by batch.
 *
 * @internal
 */
interface DriverCursor
{
    /**
     * One array per column, with exactly `Column`'s keys and enums as their
     * backing values.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(): array;

    /**
     * The next rows, in their final PHP types: [] at the end and on every
     * later call. At most one Round trip.
     *
     * @return list<array<int|string, mixed>>
     */
    public function fetchBatch(): array;

    public function close(): void;
}
