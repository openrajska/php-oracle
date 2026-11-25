<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\SparseVector;
use Rajska\Oracle\Type;
use Rajska\Oracle\VectorFormat;
use SensitiveParameter;

/**
 * VECTOR: a dense one from a `list` of numbers, a sparse one from a
 * `SparseVector`, each sent in the `VectorFormat` given. The server
 * converts to the column's format (a `Float64` bind fills a FLOAT32
 * column).
 *
 * ```php
 * Vector::in([0.12, -0.5, 1.0]);                          // FLOAT64
 * Vector::in([1, -2, 3], VectorFormat::Int8);
 * Vector::in([0b10110100, 255], VectorFormat::Binary);   // 16 dimensions, a byte per 8
 * Vector::in(new SparseVector(10, [1 => 1.5]), VectorFormat::Float32);
 * ```
 *
 * Each value is checked before any Round trip: a finite number for
 * FLOAT32 / FLOAT64 (FLOAT32 within its range), an int from -128 to 127
 * for INT8, a byte (0 to 255) for BINARY. A sparse VECTOR cannot be BINARY.
 * The format travels as the bind's `typeName`.
 */
final readonly class Vector extends Bind
{
    /**
     * @param list<int|float>|SparseVector|null $values
     */
    public static function in(#[SensitiveParameter] array|SparseVector|null $values, VectorFormat $format = VectorFormat::Float64): self
    {
        return new self(Type::Vector, Direction::In, $values, $format->value);
    }

    public static function out(): self
    {
        return new self(Type::Vector, Direction::Out, null);
    }

    /**
     * @param list<int|float>|SparseVector|null $values
     */
    public static function inOut(#[SensitiveParameter] array|SparseVector|null $values, VectorFormat $format = VectorFormat::Float64): self
    {
        return new self(Type::Vector, Direction::InOut, $values, $format->value);
    }
}
