<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Rajska\Oracle\Bind\BinaryDouble;
use Rajska\Oracle\Bind\BinaryFloat;
use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Blob;
use Rajska\Oracle\Bind\Boolean;
use Rajska\Oracle\Bind\Char;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\Collection;
use Rajska\Oracle\Bind\Cursor as CursorBind;
use Rajska\Oracle\Bind\Date;
use Rajska\Oracle\Bind\Indexed;
use Rajska\Oracle\Bind\IntervalDs;
use Rajska\Oracle\Bind\IntervalYm;
use Rajska\Oracle\Bind\Json;
use Rajska\Oracle\Bind\NChar;
use Rajska\Oracle\Bind\NClob;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\NVarchar;
use Rajska\Oracle\Bind\PlsInteger;
use Rajska\Oracle\Bind\Raw;
use Rajska\Oracle\Bind\Rowid;
use Rajska\Oracle\Bind\Struct;
use Rajska\Oracle\Bind\Timestamp;
use Rajska\Oracle\Bind\TimestampLtz;
use Rajska\Oracle\Bind\TimestampTz;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Bind\Vector;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * A pending function Call: nothing runs until a terminal names the return
 * type. Each terminal runs the Call and returns the value mapped as an OUT
 * value of that type (see `ValueMapping`); NULL gives `null`.
 *
 * A function that also has OUT parameters is called through `run()` with a
 * hand-written block.
 */
final readonly class FunctionCall
{
    /**
     * @internal use Connection::function()
     *
     * @param Closure(Bind): OutValue $run runs the Call with the return value bound as given
     */
    public function __construct(
        private Call $call,
        private Closure $run,
    ) {}

    public function varchar(): string | null
    {
        return ($this->run)(Varchar::out())->string();
    }

    public function nvarchar(): string | null
    {
        return ($this->run)(NVarchar::out())->string();
    }

    /**
     * Blank-padded as returned.
     */
    public function char(): string | null
    {
        return ($this->run)(Char::out())->string();
    }

    /**
     * Blank-padded as returned.
     */
    public function nchar(): string | null
    {
        return ($this->run)(NChar::out())->string();
    }

    /**
     * An `int` if integral and within 64 bits, else the exact decimal
     * string; a non-integral value is a `float` under `NumberMode::Float`.
     */
    public function number(): float | int | string | null
    {
        $out = ($this->run)(Number::out());
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
        return ($this->run)(BinaryFloat::out())->float();
    }

    public function binaryDouble(): float | null
    {
        return ($this->run)(BinaryDouble::out())->float();
    }

    public function plsInteger(): int | null
    {
        return ($this->run)(PlsInteger::out())->int();
    }

    public function boolean(): bool | null
    {
        return ($this->run)(Boolean::out())->bool();
    }

    /**
     * The Wall clock, or ISO text under `TemporalMode::String`.
     */
    public function date(): DateTimeImmutable | string | null
    {
        return $this->temporal(Date::out());
    }

    /**
     * The Wall clock, or ISO text under `TemporalMode::String`.
     */
    public function timestamp(): DateTimeImmutable | string | null
    {
        return $this->temporal(Timestamp::out());
    }

    /**
     * With its own offset, or ISO text under `TemporalMode::String`.
     */
    public function timestampTz(): DateTimeImmutable | string | null
    {
        return $this->temporal(TimestampTz::out());
    }

    /**
     * The Instant in PHP's default zone, or ISO text under
     * `TemporalMode::String`.
     */
    public function timestampLtz(): DateTimeImmutable | string | null
    {
        return $this->temporal(TimestampLtz::out());
    }

    public function intervalDs(): DateInterval | null
    {
        return ($this->run)(IntervalDs::out())->interval();
    }

    public function intervalYm(): DateInterval | null
    {
        return ($this->run)(IntervalYm::out())->interval();
    }

    /**
     * Bytes.
     */
    public function raw(): string | null
    {
        return ($this->run)(Raw::out())->string();
    }

    public function rowid(): string | null
    {
        return ($this->run)(Rowid::out())->string();
    }

    /**
     * A `Lob`, or the whole text under `LobMode::Inline`.
     */
    public function clob(): Lob | string | null
    {
        return $this->lob(Clob::out());
    }

    /**
     * A `Lob`, or the whole text under `LobMode::Inline`.
     */
    public function nclob(): Lob | string | null
    {
        return $this->lob(NClob::out());
    }

    /**
     * A `Lob`, or the bytes under `LobMode::Inline`.
     */
    public function blob(): Lob | string | null
    {
        return $this->lob(Blob::out());
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
        return ($this->run)(Json::out())->value();
    }

    /**
     * A dense VECTOR as a list, a sparse one as a `SparseVector`.
     *
     * @return SparseVector|list<float|int>|null
     */
    public function vector(): SparseVector | array | null
    {
        $value = ($this->run)(Vector::out())->value();

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
        return ($this->run)(CursorBind::out())->cursor();
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
        return ($this->run)(Struct::out($type))->array();
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
        return ($this->run)(Collection::out($type))->array();
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
        return ($this->run)(Indexed::out($type))->array();
    }
}
