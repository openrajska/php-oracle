<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use Rajska\Oracle\Contracts\Driver\Cursor as CursorContract;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Lob\Lob;
use Rajska\Oracle\Statement\Cursor;

/**
 * What a Connection shares with the Cursors born of it: whether `close()`
 * has released it. A Cursor checks it before serving rows it already holds,
 * so none is served after the Release.
 *
 * @internal
 */
final class ConnectionState
{
    public bool $released = false;

    /**
     * Values with each Backend handle wrapped as a Lob or a Cursor of the
     * Connection.
     *
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    public function wrapHandles(array $values): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            $value instanceof Locator => new Lob($value, $this),
            $value instanceof CursorContract => new Cursor($value, $this),
            default => $value,
        }, $values);
    }
}
