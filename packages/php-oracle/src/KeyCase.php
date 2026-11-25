<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * The case of row keys and column names: exactly as Oracle reports them, or
 * lowercase.
 */
enum KeyCase: string
{
    case Native = 'native';
    case Lower = 'lower';
}
