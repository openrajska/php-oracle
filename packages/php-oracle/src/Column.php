<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

/**
 * One column of a Cursor, as the server describes it. Available before the
 * first row.
 */
final readonly class Column
{
    /**
     * @param string $name in the same KeyCase as the row keys
     * @param ?int $precision NUMBER precision (null when unconstrained), FLOAT binary precision, or the leading field of an INTERVAL
     * @param ?int $scale NUMBER scale, or the fractional seconds of a TIMESTAMP or INTERVAL DAY TO SECOND
     * @param ?int $maxSize the declared size of a character or RAW column
     * @param ?string $objectType the Object or Collection type, unquoted and canonical: 'HR.ADDRESS'
     * @param ?int $vectorDimensions null for a flexible VECTOR
     * @param ?VectorFormat $vectorFormat null for a flexible VECTOR
     */
    public function __construct(
        public string $name,
        public Type $type,
        public bool $nullable,
        public int | null $precision,
        public int | null $scale,
        public int | null $maxSize,
        public string | null $objectType,
        public int | null $vectorDimensions,
        public VectorFormat | null $vectorFormat,
        public bool $sparse,
    ) {}

    /**
     * @internal from the Driver contract's description, enums as backing values
     *
     * @param array<string, mixed> $column
     */
    public static function fromDriver(array $column): self
    {
        /** @var array{name: string, type: string, nullable: bool, precision: ?int, scale: ?int, maxSize: ?int, objectType: ?string, vectorDimensions: ?int, vectorFormat: ?string, sparse: bool} $column */
        return new self(
            $column['name'],
            Type::from($column['type']),
            $column['nullable'],
            $column['precision'],
            $column['scale'],
            $column['maxSize'],
            $column['objectType'],
            $column['vectorDimensions'],
            $column['vectorFormat'] === null ? null : VectorFormat::from($column['vectorFormat']),
            $column['sparse'],
        );
    }
}
