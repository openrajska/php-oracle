<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Lob;

/**
 * The kind of LOB.
 */
enum Type: string
{
    case Clob = 'clob';
    case NClob = 'nClob';
    case Blob = 'blob';
}
