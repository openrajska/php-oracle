<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * How a VECTOR stores its dimensions. Backed by the case name in
 * lowerCamelCase.
 */
enum VectorFormat: string
{
    case Float32 = 'float32';
    case Float64 = 'float64';
    case Int8 = 'int8';
    case Binary = 'binary';
}
