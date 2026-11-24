<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * How CLOB, NCLOB and BLOB values reach PHP: as `Lob` objects, or inline as
 * a string. Backed by the case name in lowerCamelCase.
 */
enum LobMode: string
{
    case Locator = 'locator';
    case Inline = 'inline';
}
