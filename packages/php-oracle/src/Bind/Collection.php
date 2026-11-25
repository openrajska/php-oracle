<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * A Collection (nested table or VARRAY) of a named type, as a `list`: the
 * shape a fetched Collection has, so one binds back unchanged.
 *
 * ```php
 * $connection->select('select * from orders where id in (select column_value from table(:ids))', [
 *     'ids' => Collection::in('sys.odcinumberlist', $ids),
 * ]);
 * ```
 *
 * - The type is named as `Struct`'s is. Elements map as `Struct`'s
 *   attributes do; an element of an Object type is an array.
 * - The value must be a `list`; use `array_values()` on any other array.
 * - A VARRAY's limit is checked by the server (ORA-22165).
 * - `in(null)` is a NULL of the type; `[]` is an empty Collection.
 */
final readonly class Collection extends Bind
{
    /**
     * @param list<mixed>|null $value
     */
    public static function in(string $type, #[SensitiveParameter] ?array $value): self
    {
        return new self(Type::Collection, Direction::In, $value, $type);
    }

    public static function out(string $type): self
    {
        return new self(Type::Collection, Direction::Out, null, $type);
    }

    /**
     * @param list<mixed>|null $value
     */
    public static function inOut(string $type, #[SensitiveParameter] ?array $value): self
    {
        return new self(Type::Collection, Direction::InOut, $value, $type);
    }
}
