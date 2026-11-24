<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver;

use Rajska\Oracle\LobType;

/**
 * A LOB locator of a Backend, read chunk by chunk.
 *
 * @internal
 */
interface DriverLob
{
    public function type(): LobType;

    /**
     * In UCS-2 code units for CLOB / NCLOB, in bytes for BLOB.
     */
    public function size(): int;

    /**
     * The next chunk: '' at the end. At most one Round trip; UTF-8 for CLOB
     * and NCLOB.
     */
    public function readChunk(): string;

    public function close(): void;
}
