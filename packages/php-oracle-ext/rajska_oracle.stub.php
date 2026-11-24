<?php

/**
 * Stub of the rajska_oracle Extension's native surface (spec #32 §14), for
 * static analysis and IDEs. Core keeps an identical copy in
 * packages/php-oracle/stubs/ for PHPStan only.
 *
 * Every class is final; none can be created with `new`, cloned or
 * serialized. Arrays of scalars cross the seam: enums as backing values,
 * durations in milliseconds, null for no limit; an unknown or missing key
 * fails with `kind: usage` before any Round trip.
 */

namespace Rajska\Oracle\Extension;

/**
 * One Session, on loan from the process's Pool for its `$pool` key (or a
 * Standalone one) until close() or the last of its Cursors goes.
 *
 * @internal Core reaches it only through its Extension adapter.
 */
final class Connection
{
    /**
     * The whole `$pool` array is the Pool key: one Pool per process per key,
     * created on first use. At `maxConnections` it waits up to
     * `acquireTimeout`, then throws with `kind: pool_exhausted`. With
     * `standalone: true`, a new Session logs on and off with the Connection.
     *
     * @param array{dsn: string, user: string, password: string, proxyUser: ?string, walletLocation: ?string, walletPassword: ?string, configDir: ?string, connectTimeout: int, drcpConnectionClass: ?string, maxConnections: int, acquireTimeout: int, statementCacheSize: int, standalone: bool} $pool
     * @param array{tracing: array{module: ?string, action: ?string, clientIdentifier: ?string, clientInfo: ?string}, callTimeout: ?int} $session
     *
     * @throws Exception
     */
    public static function connect(#[\SensitiveParameter] array $pool, array $session): Connection {}

    /**
     * Each OUT value list holds the values a bind produced; a REF CURSOR is
     * a Cursor there.
     *
     * @param array<string, mixed> $binds
     * @param array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string} $options
     * @return array{rowCount: int, cursor: ?Cursor, outValues: array<string, list<mixed>>, implicitResults: list<Cursor>, warning: ?string}
     *
     * @throws Exception
     */
    public function execute(string $sql, #[\SensitiveParameter] array $binds, array $options): array {}

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string} $options
     * @return array{rowCount: int, outValues: array<string, list<mixed>>}
     *
     * @throws Exception
     */
    public function executeMany(string $sql, #[\SensitiveParameter] array $rows, array $options): array {}

    /** @throws Exception */
    public function commit(): void {}

    /** @throws Exception */
    public function rollback(): void {}

    /**
     * @param array{module: ?string, action: ?string, clientIdentifier: ?string, clientInfo: ?string} $tracing
     *
     * @throws Exception
     */
    public function trace(array $tracing): void {}

    /** @throws Exception */
    public function serverVersion(): string {}

    /**
     * Release, now: this Connection's Cursors stop working (`kind: usage`),
     * the transaction rolls back, and the Session goes back to its Pool, or
     * is discarded if the Connection is Broken. Without it, the Release
     * happens when the Connection and all its Cursors are freed.
     * Idempotent; a failed rollback never throws.
     *
     * @throws Exception
     */
    public function close(): void {}
}

/**
 * The rows of one query, a REF CURSOR or a nested `CURSOR()`. A nested
 * `CURSOR()` cell of a row is a Cursor too: it holds the Cursor it was
 * read from, and closing that one closes it.
 *
 * @internal Core reaches it only through its Extension adapter.
 */
final class Cursor
{
    /**
     * Exactly Column's fields; `type` and `vectorFormat` as backing values.
     *
     * @return list<array{name: string, type: string, nullable: bool, precision: ?int, scale: ?int, maxSize: ?int, objectType: ?string, vectorDimensions: ?int, vectorFormat: ?string, sparse: bool}>
     *
     * @throws Exception
     */
    public function columns(): array {}

    /**
     * @return list<array<int|string, mixed>>
     *
     * @throws Exception
     */
    public function fetchBatch(): array {}

    /** @throws Exception */
    public function close(): void {}
}

/**
 * Thrown by every native method. Core's ExceptionTranslator maps `kind` to
 * a public class.
 *
 * @internal Core reaches it only through its Extension adapter.
 */
final class Exception extends \RuntimeException
{
    /**
     * database, connection, connection_lost, pool_exhausted, timeout,
     * unsupported, usage, configuration or batch.
     */
    public readonly string $kind;

    public readonly ?int $offset;

    /** @var list<array{index: int, code: int, message: string}> */
    public readonly array $rows;
}
