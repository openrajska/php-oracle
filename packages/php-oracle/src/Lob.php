<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * A CLOB, NCLOB or BLOB value, read-only. CLOB and NCLOB text arrives as
 * UTF-8; BLOB as bytes.
 *
 * It reads in one pass, like a Cursor: `contents()` and `stream()` go on
 * from where the last read stopped, and give `''` (an empty stream) once
 * everything was read. It is valid only while its Connection keeps its
 * Session: after the Release (`close()`, or the Connection and its Cursors
 * gone), every call fails with `UsageException`. It does not keep the
 * Session itself.
 */
final readonly class Lob
{
    /**
     * @internal use a column, OutValue::lob() or a FunctionCall terminal
     */
    public function __construct(
        private DriverLob $driver,
        private ConnectionState $connection,
    ) {
    }

    /**
     * Values with each Backend LOB handle wrapped as a Lob of the
     * Connection.
     *
     * @internal
     *
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    public static function wrap(array $values, ConnectionState $connection): array
    {
        foreach ($values as $index => $value) {
            if ($value instanceof DriverLob) {
                $values[$index] = new self($value, $connection);
            }
        }

        return $values;
    }

    public function type(): LobType
    {
        $this->ensureOpen();

        return $this->driver->type();
    }

    /**
     * In UCS-2 code units for CLOB and NCLOB (a character outside the
     * Basic Multilingual Plane counts two), in bytes for BLOB. No Round
     * trip.
     */
    public function size(): int
    {
        $this->ensureOpen();

        return $this->driver->size();
    }

    /**
     * The whole value (or what is left of it), in memory. Use `stream()` for
     * a value too large for that.
     */
    public function contents(): string
    {
        // Joined once at the end: appending each chunk would copy what came
        // before it again, and a value of one chunk is returned as it came.
        $chunks = [];
        while (($chunk = $this->chunk()) !== '') {
            $chunks[] = $chunk;
        }

        return implode('', $chunks);
    }

    /**
     * A read-only stream of the value (or what is left of it), read chunk by
     * chunk as it is consumed, so memory stays flat whatever the size.
     *
     * @return resource
     */
    public function stream()
    {
        $this->ensureOpen();

        return LobStream::open($this->chunk(...));
    }

    private function chunk(): string
    {
        $this->ensureOpen();

        return $this->driver->readChunk();
    }

    private function ensureOpen(): void
    {
        if ($this->connection->released) {
            throw new UsageException('The Connection is closed.');
        }
    }
}
