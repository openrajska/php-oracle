<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;

/**
 * A Session to run statements on. Get one from `Oracle::connect()`.
 */
final class Connection
{
    private readonly ConnectionState $state;

    /**
     * @internal use Oracle::connect()
     */
    public function __construct(
        private readonly DriverConnection $driver,
        private readonly ConnectionConfig $config,
    ) {
        $this->state = new ConnectionState();
    }

    /**
     * Runs any SQL, PL/SQL or DDL.
     *
     * @param array<string, mixed> $binds keyed by placeholder name, without ':'
     */
    public function run(string $sql, #[\SensitiveParameter] array $binds = [], ?ExecuteOptions $options = null): Execution
    {
        return new Execution($this->driver->execute($sql, $binds, $this->resolve($options)), $sql, $this->state);
    }

    /**
     * Runs a query and returns its rows: the same as `run()->rows()`.
     *
     * @param array<string, mixed> $binds keyed by placeholder name, without ':'
     */
    public function select(string $sql, #[\SensitiveParameter] array $binds = [], ?ExecuteOptions $options = null): Cursor
    {
        return $this->run($sql, $binds, $options)->rows();
    }

    /**
     * Releases the Session now: an open transaction rolls back (never
     * commits), the Session goes back to the Pool, and every later call on
     * this Connection or its Cursors fails. Idempotent.
     *
     * Without it, the Release happens when the Connection and all its
     * Cursors are gone.
     */
    public function close(): void
    {
        $this->state->released = true;
        $this->driver->close();
    }

    private function resolve(?ExecuteOptions $options): DriverOptions
    {
        $options ??= new ExecuteOptions();

        return new DriverOptions(
            // No Explicit transaction yet: always Autocommit.
            autocommit: true,
            timeout: $options->timeout ?? $this->config->callTimeout,
            fetchSize: $options->fetchSize ?? $this->config->fetchSize,
            values: $options->values ?? $this->config->values,
            rowShape: $options->rowShape ?? $this->config->rowShape,
            keyCase: $options->keyCase ?? $this->config->keyCase,
        );
    }
}
