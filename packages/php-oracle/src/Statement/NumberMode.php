<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Statement;

/**
 * How a non-integral NUMBER reaches PHP: an exact string, or a `float`.
 */
enum NumberMode: string
{
    case Exact = 'exact';
    case Float = 'float';
}
