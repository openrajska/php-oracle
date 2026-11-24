<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * The kind of a LOB. Backed by the case name in lowerCamelCase.
 */
enum LobType: string
{
    case Clob = 'clob';
    case NClob = 'nClob';
    case Blob = 'blob';
}
