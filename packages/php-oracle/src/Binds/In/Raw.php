<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * RAW: bytes, at most 32767. It also writes a LONG RAW column; more than
 * 32767 bytes would take a LONG bind, which is not supported yet
 * (UnsupportedException). Bind a BLOB's longer bytes with `In\Blob`.
 */
final readonly class Raw implements SendsValue
{
    public function __construct(#[SensitiveParameter] public string | null $value)
    {
    }
}
