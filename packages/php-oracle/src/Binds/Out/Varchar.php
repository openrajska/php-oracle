<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Out;

use Rajska\Oracle\Binds\ReturnsValue;

/**
 * An OUT VARCHAR2 bind. Declares the largest buffer: 32767 bytes in PL/SQL.
 */
final readonly class Varchar implements ReturnsValue
{
    //
}
