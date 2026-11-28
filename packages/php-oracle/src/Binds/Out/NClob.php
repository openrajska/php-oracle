<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT NCLOB bind. The server sends back a locator: for a value PL/SQL built, a temporary
 * LOB, valid until the Connection's Release.
 */
final readonly class NClob implements ReturnsValue
{
    //
}
