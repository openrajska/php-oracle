<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

use Rajska\Oracle\Exceptions\Concerns\CarriesStatement;
use RuntimeException;

/**
 * A feature outside v1 was met at runtime. It fails instead of giving wrong
 * data.
 */
final class UnsupportedException extends RuntimeException implements OracleException
{
    use CarriesStatement;
}
