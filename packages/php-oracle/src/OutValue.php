<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use DateInterval;
use DateTimeImmutable;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * The value an OUT or IN OUT bind brought back. Its getters narrow and never
 * convert: the value already has its PHP type (see `ValueMapping`), and a
 * getter of another type fails. NULL gives `null`.
 *
 * With `RETURNING … INTO`, the bind holds one value per returned row:
 * `list()` gives them all, and a scalar getter needs exactly one row.
 */
final readonly class OutValue
{
    /**
     * @internal use Execution::out()
     *
     * @param list<mixed> $values one per row the bind produced
     */
    public function __construct(
        private string $name,
        private array $values,
        private string | null $sql = null,
        private string | null $call = null,
    ) {
    }

    public function int(): int | null
    {
        $value = $this->one('int()');

        return $value === null || \is_int($value) ? $value : throw $this->wrongType($value, 'an int');
    }

    public function string(): string | null
    {
        $value = $this->one('string()');

        return $value === null || \is_string($value) ? $value : throw $this->wrongType($value, 'a string');
    }

    public function float(): float | null
    {
        $value = $this->one('float()');

        return $value === null || \is_float($value) ? $value : throw $this->wrongType($value, 'a float');
    }

    public function bool(): bool | null
    {
        $value = $this->one('bool()');

        return $value === null || \is_bool($value) ? $value : throw $this->wrongType($value, 'a bool');
    }

    /**
     * A DATE, TIMESTAMP, TSTZ or TSLTZ value. Under `TemporalMode::String`
     * it arrives as ISO text: read it with `string()`.
     */
    public function dateTime(): DateTimeImmutable | null
    {
        $value = $this->one('dateTime()');

        return $value === null || $value instanceof DateTimeImmutable ? $value : throw $this->wrongType($value, 'a DateTimeImmutable');
    }

    /**
     * An INTERVAL DAY TO SECOND or YEAR TO MONTH value.
     */
    public function interval(): DateInterval | null
    {
        $value = $this->one('interval()');

        return $value === null || $value instanceof DateInterval ? $value : throw $this->wrongType($value, 'a DateInterval');
    }

    /**
     * A CLOB, NCLOB or BLOB value. Under `LobMode::Inline` it arrives as a
     * string: read it with `string()`.
     */
    public function lob(): Lob | null
    {
        $value = $this->one('lob()');

        return $value === null || $value instanceof Lob ? $value : throw $this->wrongType($value, 'a Lob');
    }

    /**
     * An Object (keyed by attribute name), a Collection (a `list`) or an
     * Associative array (`int` keys, in key order). Atomically NULL gives
     * `null`.
     *
     * @return array<mixed>|null
     */
    public function array(): array | null
    {
        $value = $this->one('array()');

        return $value === null || \is_array($value) ? $value : throw $this->wrongType($value, 'an array');
    }

    /**
     * A REF CURSOR, as a lazy Cursor with the Execution's options; never
     * null. One the block never opened fails on its first read
     * (ORA-01001). The same Cursor on every call.
     */
    public function cursor(): Cursor
    {
        $value = $this->one('cursor()');

        return $value instanceof Cursor ? $value : throw $this->wrongType($value, 'a Cursor');
    }

    /**
     * Every value the bind produced: one per returned row for `RETURNING …
     * INTO` (`[]` when no row matched), one for a PL/SQL OUT.
     *
     * @return list<mixed>
     */
    public function list(): array
    {
        return $this->values;
    }

    /**
     * The value as it is.
     */
    public function value(): mixed
    {
        return $this->one('value()');
    }

    private function one(string $getter): mixed
    {
        if (\count($this->values) !== 1) {
            $rows = \count($this->values);
            throw new UsageException(
                "OUT bind '{$this->name}' returned $rows rows, and $getter needs exactly one; read every returned row with list().",
                0,
                $this->sql,
                $this->call,
            );
        }

        return $this->values[0];
    }

    private function wrongType(mixed $value, string $expected): UsageException
    {
        $hint = match (true) {
            \is_string($value) && is_numeric($value) && $expected !== 'a string' => ' A NUMBER that is not an integer, or beyond 64 bits, arrives as its exact string: read it with string(), or map it to a float with ValueMapping(numbers: NumberMode::Float).',
            \is_string($value) && $expected === 'a DateTimeImmutable' => ' Under ValueMapping(temporals: TemporalMode::String), a temporal value arrives as ISO text: read it with string().',
            \is_string($value) && $expected === 'a Lob' => ' Under ValueMapping(lobs: LobMode::Inline), a LOB arrives as a string: read it with string().',
            $value instanceof DateTimeImmutable => ' Read it with dateTime().',
            $value instanceof DateInterval => ' Read it with interval().',
            \is_int($value) => ' Read it with int().',
            \is_float($value) => ' Read it with float().',
            \is_string($value) => ' Read it with string().',
            \is_bool($value) => ' Read it with bool().',
            $value instanceof Cursor => ' Read it with cursor().',
            $value instanceof Lob => ' Read it with lob().',
            \is_array($value) => ' Read it with array().',
            default => ' Read it with value().',
        };

        return new UsageException(
            "OUT bind '{$this->name}' holds " . get_debug_type($value) . ", not $expected.$hint",
            0,
            $this->sql,
            $this->call,
        );
    }
}
