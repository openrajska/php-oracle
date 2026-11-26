<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsVector;
use Rajska\Oracle\Binds\SendsValue;

/**
 * VECTOR: a dense one from a `list` of numbers, a sparse one from a
 * `SparseVector`, each sent in the `VectorFormat` given. The server
 * converts to the column's format (a `Float64` bind fills a FLOAT32
 * column).
 *
 * ```php
 * new In\Vector([0.12, -0.5, 1.0]);                          // FLOAT64
 * new In\Vector([1, -2, 3], VectorFormat::Int8);
 * new In\Vector([0b10110100, 255], VectorFormat::Binary);   // 16 dimensions, a byte per 8
 * new In\Vector(new SparseVector(10, [1 => 1.5]), VectorFormat::Float32);
 * ```
 *
 * Each value is checked before any Round trip: a finite number for
 * FLOAT32 / FLOAT64 (FLOAT32 within its range), an int from -128 to 127
 * for INT8, a byte (0 to 255) for BINARY. A sparse VECTOR cannot be BINARY.
 */
final readonly class Vector implements SendsValue
{
    use BindsVector;
}
