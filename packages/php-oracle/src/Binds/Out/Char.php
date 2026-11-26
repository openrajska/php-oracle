<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsNull;

/**
 * An OUT CHAR bind. Declares the largest buffer: 32767 bytes in PL/SQL.
 */
final readonly class Char extends Bind
{
    use BindsNull;
}
