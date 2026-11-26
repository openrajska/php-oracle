<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\ReturnsValue;
use Rajska\Oracle\Binds\SendsValue;
use Rajska\Oracle\Statement\Cursor as Rows;
use SensitiveParameter;

/**
 * An IN OUT REF CURSOR, sent as NULL: the server opens it. Passing a
 * Cursor back to the server is later, so a value fails with
 * `UnsupportedException` when the statement runs.
 */
final readonly class Cursor implements SendsValue, ReturnsValue
{
    public function __construct(#[SensitiveParameter] public Rows | null $value = null)
    {
    }
}
