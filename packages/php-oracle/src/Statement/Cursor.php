<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Statement;

use Generator;
use IteratorAggregate;
use Override;
use Rajska\Oracle\ConnectionState;
use Rajska\Oracle\Contracts\Driver\Cursor as CursorContract;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\DataType\Type;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob\Lob;

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

    private bool $closed = false;

    /**
     * The row keys of the handle columns (nested `CURSOR()`, CLOB, NCLOB,
     * BLOB), once known.
     *
     * @var list<int|string>|null
     */
    private array | null $handles = null;

    /**
     * @internal use Connection::select(), Execution::rows() or OutValue::cursor()
     *
     * @param ?Cursor $parent the Cursor a nested `CURSOR()` was read from
     */
    public function __construct(
        private readonly CursorContract $driver,
        private readonly ConnectionState $connection,
        private readonly Cursor | null $parent = null,
    ) {
    }

    /**
     * The remaining rows, keyed by their 0-based position.
     *
     * @return Generator<int, array<int|string, mixed>>
     */
    #[Override]
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
        while (($batch = $this->fetch()) !== []) {
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
    public function first(): array | null
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
        $this->closed = true;
        $this->batch = [];
        $this->next = 0;
        $this->driver->close();
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function next(): array | null
    {
        $this->ensureOpen();
        if (!isset($this->batch[$this->next])) {
            $this->batch = $this->fetch();
            $this->next = 0;
            if ($this->batch === []) {
                return null;
            }
        }
        ++$this->position;

        return $this->batch[$this->next++];
    }

    /**
     * The next batch, each nested `CURSOR()` cell wrapped as a Cursor nested
     * in this one, and each LOB cell as a Lob (under `LobMode::Inline` it is
     * a string already).
     *
     * @return list<array<int|string, mixed>>
     */
    private function fetch(): array
    {
        $rows = $this->driver->fetchBatch();
        if ($rows === []) {
            return $rows;
        }
        if ($this->handles === null) {
            // Every row has the same keys, in column order.
            $keys = array_keys($rows[0]);
            $this->handles = [];
            foreach ($this->driver->columns() as $position => $column) {
                if (\in_array($column['type'], [Type::Cursor->value, Type::Clob->value, Type::NClob->value, Type::Blob->value], true)) {
                    $this->handles[] = $keys[$position];
                }
            }
        }
        foreach ($this->handles as $key) {
            foreach ($rows as $index => $row) {
                if ($row[$key] instanceof CursorContract) {
                    $rows[$index][$key] = new self($row[$key], $this->connection, $this);
                } elseif ($row[$key] instanceof Locator) {
                    $rows[$index][$key] = new Lob($row[$key], $this->connection);
                }
            }
        }

        return $rows;
    }

    /**
     * Rows of the last batch are still here after the Connection's Release
     * or a parent's `close()`: they must not be served either.
     */
    private function ensureOpen(): void
    {
        if ($this->connection->released) {
            throw new UsageException('The Connection is closed.');
        }
        for ($parent = $this->parent; $parent instanceof Cursor; $parent = $parent->parent) {
            if ($parent->closed) {
                throw new UsageException('The Cursor this one is nested in is closed, which closed this one too.');
            }
        }
    }
}
