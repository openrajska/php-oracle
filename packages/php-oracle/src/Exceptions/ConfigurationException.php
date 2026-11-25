<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

use LogicException;

/**
 * The configuration cannot work: a rejected `dsn`, a non-PEM wallet, an
 * invalid `ConnectionConfig` value, a missing or incompatible Extension.
 */
final class ConfigurationException extends LogicException implements OracleException
{
    use CarriesStatement;
}
