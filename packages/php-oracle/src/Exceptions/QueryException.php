<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * A server error raised by a statement, not classified more precisely.
 */
class QueryException extends \RuntimeException implements OracleException
{
    use CarriesStatement {
        CarriesStatement::__construct as private carry;
    }

    private ?int $offset;

    public function __construct(
        string $message,
        int $code = 0,
        ?string $sql = null,
        ?string $call = null,
        ?int $offset = null,
        ?\Throwable $previous = null,
    ) {
        $this->carry($message, $code, $sql, $call, $previous);
        $this->offset = $offset;
    }

    /**
     * Where in the SQL the server located the error.
     */
    public function offset(): ?int
    {
        return $this->offset;
    }
}
