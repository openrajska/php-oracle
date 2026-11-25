<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Support\Binds;

/**
 * An enum with no backing value: nothing to bind it as.
 */
enum Suit
{
    case Hearts;
    case Spades;
}
