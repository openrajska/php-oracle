<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

/**
 * The constructor of every OUT bind: it sends NULL, so it takes no value.
 *
 * @internal
 */
trait BindsNull
{
    public function __construct()
    {
        parent::__construct(null);
    }
}
