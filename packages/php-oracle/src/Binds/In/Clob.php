<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsLob;

/**
 * CLOB: UTF-8 text, from a string or a stream, read back as a `Lob` (the
 * whole text under `LobMode::Inline`).
 */
final readonly class Clob extends Bind
{
    use BindsLob;
}
