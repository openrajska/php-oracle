<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver;

/**
 * The complete result of one execute, always the same shape: parts that do
 * not apply are 0, null or [].
 *
 * @internal
 */
interface DriverExecution
{
    /**
     * The DML count; 0 for anything else.
     */
    public function rowCount(): int;

    /**
     * The rows, if the statement was a query.
     */
    public function cursor(): ?DriverCursor;

    /**
     * Keyed by bind name as written; RETURNING values included. Each is the
     * list of values the bind produced: one for a PL/SQL OUT, one per
     * returned row for RETURNING. A REF CURSOR is a DriverCursor there.
     *
     * @return array<string, list<mixed>>
     */
    public function outValues(): array;

    /**
     * The Implicit results, in production order.
     *
     * @return list<DriverCursor>
     */
    public function implicitResults(): array;

    /**
     * The success-with-info text, such as compilation errors.
     */
    public function warning(): ?string;
}
