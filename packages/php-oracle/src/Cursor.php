<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Generator;
use IteratorAggregate;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * The rows of a query, read lazily, batch by batch, in one pass.
 *
 * A second `foreach` continues where the first stopped; after the end it
 * yields nothing. The server cursor closes on `close()` or when the object
 * is destroyed, never at the end of the rows. Reading after `close()` fails.
 *
 * @implements IteratorAggregate<int, array<int|string, mixed>>
 */
final class Cursor implements IteratorAggregate
{
    /**
     * The rest of the last batch, from $next on.
     *
     * @var list<array<int|string, mixed>>
     */
    private array $batch = [];

    private int $next = 0;

    /** The 0-based position of the next row. */
    private int $position = 0;

    /**
     * @internal use Connection::select() or Execution::rows()
     */
    public function __construct(
        private readonly DriverCursor $driver,
        private readonly ConnectionState $connection,
    ) {
    }

    /**
     * The remaining rows, keyed by their 0-based position.
     *
     * @return Generator<int, array<int|string, mixed>>
     */
    public function getIterator(): Generator
    {
        while (($row = $this->next()) !== null) {
            yield $this->position - 1 => $row;
        }
    }

    /**
     * The remaining rows.
     *
     * @return list<array<int|string, mixed>>
     */
    public function all(): array
    {
        $this->ensureOpen();
        $rows = $this->next === 0 ? $this->batch : \array_slice($this->batch, $this->next);
        while (($batch = $this->driver->fetchBatch()) !== []) {
            array_push($rows, ...$batch);
        }
        $this->position += \count($rows);
        $this->batch = [];
        $this->next = 0;

        return $rows;
    }

    /**
     * The next row, or null if there is none. Then the Cursor closes.
     *
     * @return array<int|string, mixed>|null
     */
    public function first(): ?array
    {
        $row = $this->next();
        $this->close();

        return $row;
    }

    /**
     * The first column of the next row, or null if there is none. Then the
     * Cursor closes.
     */
    public function value(): mixed
    {
        $row = $this->first();

        return $row === null || $row === [] ? null : $row[array_key_first($row)];
    }

    /**
     * The columns, available before the first row.
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        return array_map(Column::fromDriver(...), $this->driver->columns());
    }

    /**
     * Closes the server cursor. Idempotent.
     */
    public function close(): void
    {
        $this->batch = [];
        $this->next = 0;
        $this->driver->close();
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function next(): ?array
    {
        $this->ensureOpen();
        if (!isset($this->batch[$this->next])) {
            $this->batch = $this->driver->fetchBatch();
            $this->next = 0;
            if ($this->batch === []) {
                return null;
            }
        }
        ++$this->position;

        return $this->batch[$this->next++];
    }

    /**
     * Rows of the last batch are still here after the Connection's Release:
     * they must not be served either.
     */
    private function ensureOpen(): void
    {
        if ($this->connection->released) {
            throw new UsageException('The Connection is closed.');
        }
    }
}
