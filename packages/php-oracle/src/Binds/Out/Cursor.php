<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * REF CURSOR: the server opens it and sends it back, read with
 * `OutValue::cursor()` as a lazy `Cursor` that inherits the Execution's
 * options. There is no `In\Cursor`: passing a Cursor back to the server is
 * later.
 */
final readonly class Cursor extends Bind
{
    use BindsNull;
}
