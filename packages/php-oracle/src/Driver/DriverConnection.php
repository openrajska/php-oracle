<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver;

use Rajska\Oracle\Tracing;
use SensitiveParameter;

/**
 * One Session of a Backend. No statement handle, no `prepare`, no ordering
 * rules: SQL text and binds in, a complete result out.
 *
 * @internal
 */
interface DriverConnection
{
    /**
     * @param array<string, mixed> $binds keyed by placeholder name, without ':'
     */
    public function execute(string $sql, #[SensitiveParameter] array $binds, DriverOptions $options): DriverExecution;

    /**
     * @param list<array<string, mixed>> $rows one array of binds per row
     */
    public function executeMany(string $sql, #[SensitiveParameter] array $rows, DriverOptions $options): DriverArrayDml;

    public function commit(): void;

    public function rollback(): void;

    /**
     * Replaces the whole tracing set, client-side, with no Round trip.
     */
    public function trace(Tracing $tracing): void;

    /**
     * The Server version from the connect handshake, five parts.
     */
    public function serverVersion(): string;

    public function close(): void;
}
