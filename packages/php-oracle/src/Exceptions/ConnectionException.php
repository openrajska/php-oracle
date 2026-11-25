<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

use RuntimeException;

/**
 * No Session was obtained: wrong credentials, an unreachable listener, a
 * connect timeout.
 */
class ConnectionException extends RuntimeException implements OracleException
{
    use CarriesStatement;
}
