<?php

declare(strict_types = 1);

namespace Rajska\Oracle\DataType;

use Rajska\Oracle\Exceptions\UsageException;

/**
 * A sparse VECTOR: how many dimensions it has, and its non-zero values keyed
 * by their 0-based position, in position order. Every other dimension is 0.
 *
 * ```php
 * new SparseVector(10, [1 => 1.5, 5 => 0.1]);   // [0, 1.5, 0, 0, 0, 0.1, 0, 0, 0, 0]
 * new In\Vector(new SparseVector(10, [1 => 1.5]), VectorFormat::Float32);
 * ```
 *
 * A sparse VECTOR column, OUT value or VECTOR inside JSON arrives as one, so
 * it binds back unchanged. Values are floats (FLOAT32, FLOAT64) or ints
 * (INT8).
 */
final readonly class SparseVector
{
    /**
     * @var array<int, float|int> keyed by 0-based position, in position order
     */
    public array $values;

    /**
     * @param array<mixed> $values the non-zero values (ints or floats), keyed by 0-based position
     *
     * @throws UsageException if `$dimensions` is below 1, a position is outside 0 to `$dimensions` - 1, or a value is not an int or a float
     */
    public function __construct(
        public int $dimensions,
        array $values,
    ) {
        if ($dimensions < 1) {
            throw new UsageException("A SparseVector has at least 1 dimension; $dimensions given.");
        }
        $checked = [];
        foreach ($values as $position => $value) {
            if (!\is_int($position) || $position < 0 || $position >= $dimensions) {
                $last = $dimensions - 1;
                throw new UsageException("A SparseVector's values are keyed by position, from 0 to $last; '$position' is not one.");
            }
            if (!\is_int($value) && !\is_float($value)) {
                throw new UsageException("A SparseVector's values are ints and floats; position $position holds " . get_debug_type($value) . '.');
            }
            $checked[$position] = $value;
        }
        ksort($checked);
        $this->values = $checked;
    }

    /**
     * @internal the one Core call the Extension makes, for every sparse
     * VECTOR it reads; its name and signature are part of the Extension's
     * semver contract
     *
     * @param array<int, float|int> $values keyed by 0-based position
     */
    public static function fromDriver(int $dimensions, array $values): self
    {
        return new self($dimensions, $values);
    }
}
