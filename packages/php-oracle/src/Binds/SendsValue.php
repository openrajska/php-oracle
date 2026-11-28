<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds;

/**
 * A bind that sends a value to the server: `In\` and `InOut\`.
 */
interface SendsValue extends Bindable
{
    public mixed $value { get; }
}
