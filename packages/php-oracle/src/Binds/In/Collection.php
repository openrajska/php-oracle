<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * A Collection (nested table or VARRAY) of a named type, as a `list`: the
 * shape a fetched Collection has, so one binds back unchanged.
 *
 * ```php
 * $connection->select('select * from orders where id in (select column_value from table(:ids))', [
 *     'ids' => new In\Collection('sys.odcinumberlist', $ids),
 * ]);
 * ```
 *
 * - The type is named as `In\Struct`'s is. Elements map as `In\Struct`'s
 *   attributes do; an element of an Object type is an array.
 * - The value must be a `list`; use `array_values()` on any other array.
 * - A VARRAY's limit is checked by the server (ORA-22165).
 * - A `null` value is a NULL of the type; `[]` is an empty Collection.
 */
final readonly class Collection extends Bind
{
    /**
     * @param list<mixed>|null $value
     */
    public function __construct(public string $typeName, #[SensitiveParameter] array | null $value)
    {
        parent::__construct($value);
    }
}
