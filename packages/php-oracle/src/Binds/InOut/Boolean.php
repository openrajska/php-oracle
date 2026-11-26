<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT BOOLEAN bind: sent as `In\Boolean` sends it, read back as the server writes it.
 */
final readonly class Boolean extends Bind
{
    public function __construct(#[SensitiveParameter] bool | null $value)
    {
        parent::__construct($value);
    }
}
