<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * No Session was obtained: wrong credentials, an unreachable listener, a
 * connect timeout.
 */
class ConnectionException extends \RuntimeException implements OracleException
{
    use CarriesStatement;
}
