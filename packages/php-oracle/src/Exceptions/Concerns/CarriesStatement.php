<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions\Concerns;

use Throwable;

/**
 * The constructor and the `sql()` / `call()` of every OracleException.
 *
 * @internal
 */
trait CarriesStatement
{
    private string | null $sql;

    private string | null $call;

    public function __construct(
        string $message,
        int $code = 0,
        string | null $sql = null,
        string | null $call = null,
        Throwable | null $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->call = $call;
    }

    public function sql(): string | null
    {
        return $this->sql;
    }

    public function call(): string | null
    {
        return $this->call;
    }
}
