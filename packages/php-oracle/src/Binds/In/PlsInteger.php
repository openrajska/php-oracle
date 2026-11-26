<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * PLS_INTEGER / BINARY_INTEGER: a 32-bit signed integer of PL/SQL.
 */
final readonly class PlsInteger extends Bind
{
    public function __construct(#[SensitiveParameter] int | null $value)
    {
        parent::__construct($value);
    }
}
