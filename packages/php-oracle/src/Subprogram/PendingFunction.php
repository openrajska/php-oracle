<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Subprogram;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob\Lob;
use Rajska\Oracle\Statement\Cursor;
use Rajska\Oracle\Statement\OutValue;

/**
 * A pending function Call: nothing runs until a terminal names the return
 * type. Each terminal runs the Call and returns the value mapped as an OUT
 * value of that type (see `ValueMapping`); NULL gives `null`.
 *
 * A function that also has OUT parameters is called through `run()` with a
 * hand-written block.
 */
final readonly class PendingFunction
{
    /**
     * @internal use Connection::function()
     *
     * @param Closure(Bind): OutValue $run runs the Call with the return value bound as given
     */
    public function __construct(
        private Call $call,
        private Closure $run,
    ) {
    }

    public function varchar(): string | null
    {
        return ($this->run)(new Out\Varchar())->string();
    }

    public function nVarchar(): string | null
    {
        return ($this->run)(new Out\NVarchar())->string();
    }

    /**
     * Blank-padded as returned.
     */
    public function char(): string | null
    {
        return ($this->run)(new Out\Char())->string();
    }

    /**
     * Blank-padded as returned.
     */
    public function nChar(): string | null
    {
        return ($this->run)(new Out\NChar())->string();
    }

    /**
     * An `int` if integral and within 64 bits, else the exact decimal
     * string; a non-integral value is a `float` under `NumberMode::Float`.
     */
    public function number(): float | int | string | null
    {
        $out = ($this->run)(new Out\Number());
        $value = $out->value();

        return $value === null || \is_int($value) || \is_float($value) || \is_string($value)
            ? $value
            : throw new UsageException("The return value of {$this->call->name} is " . get_debug_type($value) . ', not a NUMBER.', 0, null, $this->call->name);
    }

    private function temporal(Bind $bind): DateTimeImmutable | string | null
    {
        $value = ($this->run)($bind)->value();

        return $value === null || $value instanceof DateTimeImmutable || \is_string($value)
            ? $value
            : throw new UsageException("The return value of {$this->call->name} is " . get_debug_type($value) . ', not a date.', 0, null, $this->call->name);
    }

    public function binaryFloat(): float | null
    {
        return ($this->run)(new Out\BinaryFloat())->float();
    }

    public function binaryDouble(): float | null
    {
        return ($this->run)(new Out\BinaryDouble())->float();
    }

    public function plsInteger(): int | null
    {
        return ($this->run)(new Out\PlsInteger())->int();
    }

    public function boolean(): bool | null
    {
        return ($this->run)(new Out\Boolean())->bool();
    }

    /**
     * The Wall clock, or ISO text under `TemporalMode::String`.
     */
    public function date(): DateTimeImmutable | string | null
    {
        return $this->temporal(new Out\Date());
    }

    /**
     * The Wall clock, or ISO text under `TemporalMode::String`.
     */
    public function timestamp(): DateTimeImmutable | string | null
    {
        return $this->temporal(new Out\Timestamp());
    }

    /**
     * With its own offset, or ISO text under `TemporalMode::String`.
     */
    public function timestampTz(): DateTimeImmutable | string | null
    {
        return $this->temporal(new Out\TimestampTz());
    }

    /**
     * The Instant in PHP's default zone, or ISO text under
     * `TemporalMode::String`.
     */
    public function timestampLtz(): DateTimeImmutable | string | null
    {
        return $this->temporal(new Out\TimestampLtz());
    }

    public function intervalDs(): DateInterval | null
    {
        return ($this->run)(new Out\IntervalDs())->interval();
    }

    public function intervalYm(): DateInterval | null
    {
        return ($this->run)(new Out\IntervalYm())->interval();
    }

    /**
     * Bytes.
     */
    public function raw(): string | null
    {
        return ($this->run)(new Out\Raw())->string();
    }

    public function rowid(): string | null
    {
        return ($this->run)(new Out\Rowid())->string();
    }

    /**
     * A `Lob`, or the whole text under `LobMode::Inline`.
     */
    public function clob(): Lob | string | null
    {
        return $this->lob(new Out\Clob());
    }

    /**
     * A `Lob`, or the whole text under `LobMode::Inline`.
     */
    public function nClob(): Lob | string | null
    {
        return $this->lob(new Out\NClob());
    }

    /**
     * A `Lob`, or the bytes under `LobMode::Inline`.
     */
    public function blob(): Lob | string | null
    {
        return $this->lob(new Out\Blob());
    }

    private function lob(Bind $bind): Lob | string | null
    {
        $value = ($this->run)($bind)->value();

        return $value === null || $value instanceof Lob || \is_string($value)
            ? $value
            : throw new UsageException("The return value of {$this->call->name} is " . get_debug_type($value) . ', not a LOB.', 0, null, $this->call->name);
    }

    /**
     * Like `json_decode($text, true)`; SQL NULL gives `null`.
     */
    public function json(): mixed
    {
        return ($this->run)(new Out\Json())->value();
    }

    /**
     * A dense VECTOR as a list, a sparse one as a `SparseVector`.
     *
     * @return SparseVector|list<float|int>|null
     */
    public function vector(): SparseVector | array | null
    {
        $value = ($this->run)(new Out\Vector())->value();

        if ($value !== null && !\is_array($value) && !$value instanceof SparseVector) {
            throw new UsageException("The return value of {$this->call->name} is " . get_debug_type($value) . ', not a VECTOR.', 0, null, $this->call->name);
        }

        /** @var SparseVector|list<float|int>|null $value */
        return $value;
    }

    /**
     * The returned REF CURSOR, a lazy Cursor with the Call's options; never
     * null.
     */
    public function cursor(): Cursor
    {
        return ($this->run)(new Out\Cursor())->cursor();
    }

    /**
     * An Object of `$type`, as an array keyed by attribute name (see
     * `Struct`).
     *
     * @return array<string, mixed>|null
     */
    public function struct(string $type): array | null
    {
        /** @var array<string, mixed>|null */
        return ($this->run)(new Out\Struct($type))->array();
    }

    /**
     * A Collection of `$type` (nested table or VARRAY), as a list (see
     * `Collection`).
     *
     * @return list<mixed>|null
     */
    public function collection(string $type): array | null
    {
        /** @var list<mixed>|null */
        return ($this->run)(new Out\Collection($type))->array();
    }

    /**
     * An Associative array of `$type`, keys kept in key order (see
     * `Indexed`).
     *
     * @return array<int, mixed>|null
     */
    public function indexed(string $type): array | null
    {
        /** @var array<int, mixed>|null */
        return ($this->run)(new Out\Indexed($type))->array();
    }
}
