<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support\Binds;

/**
 * A string-backed enum, to bind as its backing value.
 */
enum Color: string
{
    case Red = 'red';
    case Green = 'green';
}
