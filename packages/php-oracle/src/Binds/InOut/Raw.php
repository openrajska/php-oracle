<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT RAW bind. Declares the largest buffer: 32767 bytes.
 */
final readonly class Raw extends Bind
{
    public function __construct(#[SensitiveParameter] string | null $bytes)
    {
        parent::__construct($bytes);
    }
}
