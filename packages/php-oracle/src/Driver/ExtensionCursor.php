<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Override;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Extension;
use Rajska\Oracle\Extension\Exception;
use Rajska\Oracle\Extension\Lob;

/**
 * @internal
 */
final class ExtensionCursor implements Cursor
{
    /** The column types whose cells are handles (a LOB is a string under `LobMode::Inline`). */
    private const array HANDLES = ['cursor', 'clob', 'nClob', 'blob'];

    /**
     * The positions of the handle columns (nested `CURSOR()`, CLOB, NCLOB,
     * BLOB), whose cells are native Cursors or Lobs to wrap; known from
     * `columns()` on the first rows.
     *
     * @var list<int>|null
     */
    private array | null $handles = null;

    /**
     * @param string $sql the SQL of the Execution the Cursor was born of, for `sql()`
     */
    public function __construct(
        private readonly Extension\Cursor $native,
        private readonly string $sql,
    ) {
    }

    /**
     * OUT values with each native handle (a REF CURSOR, a LOB) wrapped.
     *
     * @param array<string, list<mixed>> $outValues each bind's list of values
     *
     * @return array<string, list<mixed>>
     */
    public static function handles(array $outValues, string $sql): array
    {
        foreach ($outValues as $name => $values) {
            foreach ($values as $index => $value) {
                if ($value instanceof Extension\Cursor) {
                    $outValues[$name][$index] = new self($value, $sql);
                } elseif ($value instanceof Lob) {
                    $outValues[$name][$index] = new ExtensionLobLocator($value, $sql);
                }
            }
        }

        return $outValues;
    }

    /**
     * `executeMany()` OUT values with each native handle (a LOB) wrapped.
     *
     * @param array<string, list<list<mixed>>> $outValues each bind's list of values per row
     *
     * @return array<string, list<list<mixed>>>
     */
    public static function handlesPerRow(array $outValues, string $sql): array
    {
        foreach ($outValues as $name => $rows) {
            $outValues[$name] = array_map(static fn (array $values): array => self::handles(['row' => $values], $sql)['row'], $rows);
        }

        return $outValues;
    }

    #[Override]
    public function columns(): array
    {
        try {
            return $this->native->columns();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
    }

    #[Override]
    public function fetchBatch(): array
    {
        try {
            $rows = $this->native->fetchBatch();
            if ($rows === []) {
                return $rows;
            }
            $this->handles ??= array_keys(array_filter(
                $this->native->columns(),
                static fn (array $column): bool => \in_array($column['type'], self::HANDLES, true),
            ));
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
        if ($this->handles === []) {
            return $rows;
        }

        // Every row of a Cursor has the same keys, in column order.
        $keys = array_keys($rows[0]);
        foreach ($rows as $index => $row) {
            foreach ($this->handles as $position) {
                $cell = $row[$keys[$position]];
                if ($cell instanceof Extension\Cursor) {
                    $rows[$index][$keys[$position]] = new self($cell, $this->sql);
                } elseif ($cell instanceof Lob) {
                    $rows[$index][$keys[$position]] = new ExtensionLobLocator($cell, $this->sql);
                }
            }
        }

        return $rows;
    }

    #[Override]
    public function close(): void
    {
        try {
            $this->native->close();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
    }
}
