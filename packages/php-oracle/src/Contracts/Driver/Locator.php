<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Contracts\Driver;

use Rajska\Oracle\Lob\Type;

/**
 * A LOB locator of a Backend, read chunk by chunk.
 *
 * @internal
 */
interface Locator
{
    public function type(): Type;

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
