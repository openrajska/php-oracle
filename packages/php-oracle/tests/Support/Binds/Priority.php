<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support\Binds;

/**
 * An int-backed enum, to bind as its backing value.
 */
enum Priority: int
{
    case Low = 1;
    case High = 2;
}
