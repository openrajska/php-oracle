<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * BOOLEAN, in SQL and PL/SQL.
 */
final readonly class Boolean extends Bind
{
    public function __construct(#[SensitiveParameter] bool | null $value)
    {
        parent::__construct($value);
    }
}
