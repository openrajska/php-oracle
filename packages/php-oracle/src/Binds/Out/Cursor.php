<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * REF CURSOR: the server opens it and sends it back, read with
 * `OutValue::cursor()` as a lazy `Cursor` that inherits the Execution's
 * options. There is no `In\Cursor`: passing a Cursor back to the server is
 * later.
 */
final readonly class Cursor implements ReturnsValue
{
    //
}
