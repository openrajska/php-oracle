<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Cursor as Rows;
use Rajska\Oracle\Type;

/**
 * REF CURSOR: the server opens it and sends it back, read with
 * `OutValue::cursor()` as a lazy `Cursor` that inherits the Execution's
 * options. There is no `in()`: passing a Cursor back to the server is later.
 */
final readonly class Cursor extends Bind
{
    public static function out(): self
    {
        return new self(Type::Cursor, Direction::Out, null);
    }

    /**
     * An IN OUT REF CURSOR, sent as NULL: the server opens it. Passing a
     * Cursor back to the server is later, so a `$cursor` fails with
     * `UnsupportedException` when the statement runs.
     */
    public static function inOut(#[\SensitiveParameter] ?Rows $cursor = null): self
    {
        return new self(Type::Cursor, Direction::InOut, $cursor);
    }
}
