<?php

declare(strict_types=1);

namespace Rajska\Oracle\Exceptions;

use Throwable;

/**
 * Every exception the library throws. `getCode()` is the ORA number (or 0),
 * `getMessage()` the server's text verbatim; bound values never appear.
 */
interface OracleException extends Throwable
{
    /**
     * The SQL that ran: the generated block for a Call.
     */
    public function sql(): ?string;

    /**
     * The Call name, for `procedure()` and `function()`.
     */
    public function call(): ?string;
}
