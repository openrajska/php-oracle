<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * What one statement produced. Every statement kind (query, DML, PL/SQL,
 * DDL) gives a complete Execution.
 */
final class Execution
{
    private ?Cursor $rows = null;

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
     * The rows of a query: always the same Cursor.
     *
     * @throws UsageException if the statement was not a query
     */
    public function rows(): Cursor
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $cursor = $this->driver->cursor() ?? throw new UsageException(
            'The statement is not a query, so it has no rows: read OUT values with out() and Implicit results with results().',
            0,
            $this->sql,
        );

        return $this->rows = new Cursor($cursor);
    }

    /**
     * The success-with-info text, such as "creation succeeded with
     * compilation errors"; null otherwise.
     */
    public function warning(): ?string
    {
        return $this->driver->warning();
    }
}
