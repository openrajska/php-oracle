<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsText;

/**
 * An IN OUT CHAR bind. Declares the largest buffer: 32767 bytes in PL/SQL.
 */
final readonly class Char extends Bind
{
    use BindsText;
}
