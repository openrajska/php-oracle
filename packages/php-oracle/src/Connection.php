<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Exceptions\UsageException;

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
        return new Execution(
            $this->driver->execute($sql, $binds, $this->resolve($options)),
            $sql,
            array_map(strval(...), array_keys($binds)),
        );
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
     * Calls a stored procedure by name, in named notation: an omitted
     * parameter takes its PL/SQL default. OUT and IN OUT values are read
     * with `out()` by parameter name.
     *
     * @param string $name 1 to 3 dot-separated parts (`schema.package.procedure`), each an identifier or a double-quoted name
     * @param array<string, mixed> $binds keyed by parameter name
     *
     * @throws UsageException if the name or a parameter name is not valid, before any Round trip
     */
    public function procedure(string $name, #[\SensitiveParameter] array $binds = [], ?ExecuteOptions $options = null): Execution
    {
        return Call::of($name, $binds)->procedure($this->driver, $this->resolve($options));
    }

    /**
     * A stored function, called by name like `procedure()`. Nothing runs
     * until a terminal of the FunctionCall names the return type:
     * `function('pkg.total', ['id' => 1])->number()`.
     *
     * @param string $name 1 to 3 dot-separated parts (`schema.package.function`), each an identifier or a double-quoted name
     * @param array<string, mixed> $binds keyed by parameter name
     *
     * @throws UsageException if the name or a parameter name is not valid, before any Round trip
     */
    public function function(string $name, #[\SensitiveParameter] array $binds = [], ?ExecuteOptions $options = null): FunctionCall
    {
        $call = Call::of($name, $binds);

        return new FunctionCall(
            $call,
            fn (Bind $return): OutValue => $call->function($this->driver, $this->resolve($options), $return),
        );
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
