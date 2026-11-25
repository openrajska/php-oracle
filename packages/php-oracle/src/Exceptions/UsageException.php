<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

use LogicException;

/**
 * A programmer mistake, detected before any Round trip: a bind error, a
 * transaction-state error, use after `close()`.
 */
final class UsageException extends LogicException implements OracleException
{
    use CarriesStatement;
}
