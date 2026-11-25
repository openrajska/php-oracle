<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A server error raised by a statement, not classified more precisely.
 */
class QueryException extends RuntimeException implements OracleException
{
    use CarriesStatement {
        CarriesStatement::__construct as private carry;
    }

    public function __construct(
        string $message,
        int $code = 0,
        string | null $sql = null,
        string | null $call = null,
        private int | null $offset = null,
        Throwable | null $previous = null,
    ) {
        $this->carry($message, $code, $sql, $call, $previous);
    }

    /**
     * Where in the SQL the server located the error.
     */
    public function offset(): int | null
    {
        return $this->offset;
    }
}
