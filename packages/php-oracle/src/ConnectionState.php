<?php

declare(strict_types=1);

namespace Rajska\Oracle;

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
}
