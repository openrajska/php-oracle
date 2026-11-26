<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT BLOB bind. The server sends back a locator: for a value PL/SQL built, a temporary
 * LOB, valid until the Connection's Release.
 */
final readonly class Blob extends Bind
{
    use BindsNull;
}
