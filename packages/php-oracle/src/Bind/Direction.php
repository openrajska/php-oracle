<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

/**
 * Which way a typed Bind's value travels.
 */
enum Direction: string
{
    /** The value goes to the server. */
    case In = 'in';

    /** The server sends a value back, read with `Execution::out()`. */
    case Out = 'out';

    /** The value goes to the server, which may send another one back. */
    case InOut = 'inOut';
}
