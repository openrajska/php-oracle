<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT PLS_INTEGER bind: sent as `In\PlsInteger` sends it, read back as the server writes it.
 */
final readonly class PlsInteger extends Bind
{
    public function __construct(#[SensitiveParameter] int | null $value)
    {
        parent::__construct($value);
    }
}
