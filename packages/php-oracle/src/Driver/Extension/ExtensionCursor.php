<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Extension\Cursor as NativeCursor;
use Rajska\Oracle\Extension\Exception as NativeException;

/**
 * @internal
 */
final class ExtensionCursor implements DriverCursor
{
    /**
     * The positions of the nested `CURSOR()` columns, whose cells are
     * native Cursors to wrap; known from `columns()` on the first rows.
     *
     * @var list<int>|null
     */
    private ?array $handles = null;

    /**
     * @param string $sql the SQL of the Execution the Cursor was born of, for `sql()`
     */
    public function __construct(private readonly NativeCursor $native, private readonly string $sql)
    {
    }

    /**
     * OUT values with each native Cursor (a REF CURSOR) wrapped.
     *
     * @param array<string, list<mixed>> $outValues each bind's list of values
     *
     * @return array<string, list<mixed>>
     */
    public static function handles(array $outValues, string $sql): array
    {
        foreach ($outValues as $name => $values) {
            foreach ($values as $index => $value) {
                if ($value instanceof NativeCursor) {
                    $outValues[$name][$index] = new self($value, $sql);
                }
            }
        }

        return $outValues;
    }

    public function columns(): array
    {
        try {
            return $this->native->columns();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }

    public function fetchBatch(): array
    {
        try {
            $rows = $this->native->fetchBatch();
            if ($rows === []) {
                return $rows;
            }
            $this->handles ??= array_keys(array_filter(
                $this->native->columns(),
                static fn (array $column): bool => $column['type'] === 'cursor',
            ));
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
        if ($this->handles === []) {
            return $rows;
        }

        // Every row of a Cursor has the same keys, in column order.
        $keys = array_keys($rows[0]);
        foreach ($rows as $index => $row) {
            foreach ($this->handles as $position) {
                $cell = $row[$keys[$position]];
                if ($cell instanceof NativeCursor) {
                    $rows[$index][$keys[$position]] = new self($cell, $this->sql);
                }
            }
        }

        return $rows;
    }

    public function close(): void
    {
        try {
            $this->native->close();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }
}
