<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds;

/**
 * A bind the server writes, read back with `Execution::out()`: `Out\` and
 * `InOut\`. An `Out\` bind carries no value, only its type.
 */
interface ReturnsValue extends Bindable
{
    //
}
