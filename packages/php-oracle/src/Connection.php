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
    /**
     * @internal use Oracle::connect()
     */
    public function __construct(
        private readonly DriverConnection $driver,
        private readonly ConnectionConfig $config,
    ) {
    }

    /**
     * Runs any SQL, PL/SQL or DDL.
     *
     * @param array<string, mixed> $binds keyed by placeholder name, without ':'
     */
    public function run(string $sql, #[\SensitiveParameter] array $binds = [], ?ExecuteOptions $options = null): Execution
    {
        return new Execution($this->driver->execute($sql, $binds, $this->resolve($options)), $sql);
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
     * Ends the Session. Idempotent.
     */
    public function close(): void
    {
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
