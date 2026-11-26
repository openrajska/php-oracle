<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Statement\Cursor as Rows;
use SensitiveParameter;

/**
 * An IN OUT REF CURSOR, sent as NULL: the server opens it. Passing a
 * Cursor back to the server is later, so a `$cursor` fails with
 * `UnsupportedException` when the statement runs.
 */
final readonly class Cursor extends Bind
{
    public function __construct(#[SensitiveParameter] Rows | null $cursor = null)
    {
        parent::__construct($cursor);
    }
}
