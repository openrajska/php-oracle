<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsLob;
use Rajska\Oracle\Binds\SendsValue;

/**
 * NCLOB: text in the national character set, written from UTF-8 (a string
 * or a stream), read back as a `Lob` (the whole text, in UTF-8, under
 * `LobMode::Inline`).
 */
final readonly class NClob implements SendsValue
{
    use BindsLob;
}
