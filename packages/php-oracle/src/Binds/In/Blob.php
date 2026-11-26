<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsLob;
use Rajska\Oracle\Binds\SendsValue;

/**
 * BLOB: bytes, from a string or a stream, read back as a `Lob` (the bytes
 * under `LobMode::Inline`).
 */
final readonly class Blob implements SendsValue
{
    use BindsLob;
}
