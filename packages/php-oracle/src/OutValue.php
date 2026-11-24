<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Exceptions\UsageException;

/**
 * The value an OUT or IN OUT bind brought back. Its getters narrow and never
 * convert: the value already has its PHP type (see `ValueMapping`), and a
 * getter of another type fails. NULL gives `null`.
 *
 * With `RETURNING … INTO`, the bind holds one value per returned row:
 * `list()` gives them all, and a scalar getter needs exactly one row.
 */
final class OutValue
{
    /**
     * @internal use Execution::out()
     *
     * @param list<mixed> $values one per row the bind produced
     */
    public function __construct(
        private readonly string $name,
        private readonly array $values,
        private readonly ?string $sql = null,
        private readonly ?string $call = null,
    ) {
    }

    public function int(): ?int
    {
        $value = $this->one('int()');

        return $value === null || \is_int($value) ? $value : throw $this->wrongType($value, 'an int');
    }

    public function string(): ?string
    {
        $value = $this->one('string()');

        return $value === null || \is_string($value) ? $value : throw $this->wrongType($value, 'a string');
    }

    public function float(): ?float
    {
        $value = $this->one('float()');

        return $value === null || \is_float($value) ? $value : throw $this->wrongType($value, 'a float');
    }

    public function bool(): ?bool
    {
        $value = $this->one('bool()');

        return $value === null || \is_bool($value) ? $value : throw $this->wrongType($value, 'a bool');
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
            \is_int($value) => ' Read it with int().',
            \is_float($value) => ' Read it with float().',
            \is_string($value) => ' Read it with string().',
            \is_bool($value) => ' Read it with bool().',
            default => ' Read it with value().',
        };

        return new UsageException(
            "OUT bind '{$this->name}' holds ".get_debug_type($value).", not $expected.$hint",
            0,
            $this->sql,
            $this->call,
        );
    }
}
