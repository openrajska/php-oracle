<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * The constructor and the `sql()` / `call()` of every OracleException.
 *
 * @internal
 */
trait CarriesStatement
{
    private ?string $sql;

    private ?string $call;

    public function __construct(
        string $message,
        int $code = 0,
        ?string $sql = null,
        ?string $call = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->call = $call;
    }

    public function sql(): ?string
    {
        return $this->sql;
    }

    public function call(): ?string
    {
        return $this->call;
    }
}
