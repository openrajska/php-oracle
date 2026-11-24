<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverCursor;

/**
 * The rows of a query, read lazily.
 */
final class Cursor
{
    /**
     * @internal use Connection::select() or Execution::rows()
     */
    public function __construct(private readonly DriverCursor $driver)
    {
    }

    /**
     * The first column of the next row, or null if there is none. Then the
     * Cursor closes.
     */
    public function value(): mixed
    {
        $rows = $this->driver->fetchBatch();
        $this->close();

        $row = $rows[0] ?? [];

        return $row === [] ? null : $row[array_key_first($row)];
    }

    /**
     * Closes the server cursor. Idempotent.
     */
    public function close(): void
    {
        $this->driver->close();
    }
}
