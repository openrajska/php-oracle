<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * The Session is gone: a Broken Connection. Thrown by the call that saw it
 * and by every later one.
 */
final class ConnectionLostException extends ConnectionException
{
}
