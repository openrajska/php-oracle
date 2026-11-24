<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * A Round trip took longer than the Call timeout.
 */
final class TimeoutException extends \RuntimeException implements OracleException
{
    use CarriesStatement;
}
