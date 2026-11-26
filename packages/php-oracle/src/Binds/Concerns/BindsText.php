<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use SensitiveParameter;
use Stringable;

/**
 * The constructor of the text binds. A `Stringable` is read once, here.
 *
 * @internal
 */
trait BindsText
{
    public function __construct(#[SensitiveParameter] Stringable | string | null $value)
    {
        parent::__construct($value instanceof Stringable ? (string) $value : $value);
    }
}
