<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

/**
 * Rows of an Array DML call failed; none of that call's rows was applied.
 * `getCode()` is the first failing row's code.
 */
final class BatchException extends QueryException
{
    /**
     * @param list<RowFailure> $rows
     */
    public function __construct(
        string $message,
        int $code,
        private readonly array $rows,
        ?string $sql = null,
        ?string $call = null,
        ?\Throwable $previous = null,
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
