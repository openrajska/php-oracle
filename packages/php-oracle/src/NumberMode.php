<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * How a non-integral NUMBER reaches PHP: an exact string, or a `float`.
 * Backed by the case name in lowerCamelCase.
 */
enum NumberMode: string
{
    case Exact = 'exact';
    case Float = 'float';
}
