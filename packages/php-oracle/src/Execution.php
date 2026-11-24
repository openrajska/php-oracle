<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * What one statement produced.
 */
final class Execution
{
    /**
     * @internal use Connection::run()
     */
    public function __construct(private readonly DriverExecution $driver, private readonly string $sql)
    {
    }

    /**
     * The INSERT / UPDATE / DELETE / MERGE count; 0 for anything else.
     */
    public function affected(): int
    {
        return $this->driver->rowCount();
    }

    /**
     * The rows of a query.
     *
     * @throws UsageException if the statement was not a query
     */
    public function rows(): Cursor
    {
        $cursor = $this->driver->cursor()
            ?? throw new UsageException('The statement is not a query, so it has no rows.', 0, $this->sql);

        return new Cursor($cursor);
    }
}
