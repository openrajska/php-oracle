<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

use Throwable;

/**
 * Rows of an Array DML call failed; none of that call's rows was applied.
 * `getCode()` is the first failing row's code.
 */
final class ArrayDmlFailedException extends QueryException
{
    /**
     * @param list<RowFailure> $rows
     */
    public function __construct(
        string $message,
        int $code,
        private readonly array $rows,
        string | null $sql = null,
        string | null $call = null,
        Throwable | null $previous = null,
    ) {
        parent::__construct($message, $code, $sql, $call, null, $previous);
    }

    /**
     * Every failing row, in row order.
     *
     * @return list<RowFailure>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
