<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use SensitiveParameter;

/**
 * The constructor of the IEEE 754 binds: an `int` becomes a `float`.
 *
 * @internal
 */
trait BindsFloat
{
    public function __construct(#[SensitiveParameter] float | int | null $value)
    {
        parent::__construct($value === null ? null : (float) $value);
    }
}
