<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Exceptions\BatchException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * A Session to run statements on. Get one from `Oracle::connect()`.
 *
 * Outside an Explicit transaction every statement commits on its own, in
 * its own Round trip (Autocommit), and a failed one commits nothing. Use
 * `transaction()`, or `begin()` with `commit()` / `rollback()`, to group
 * statements.
 */
final class Connection
{
    private readonly ConnectionState $state;

    /**
     * Inside an Explicit transaction: statements run without Autocommit.
     */
    private bool $inTransaction = false;

    /**
     * The closure of `transaction()` is running: it owns the transaction's
     * end.
     */
    private bool $inCallback = false;

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
        return new Execution(
            $this->driver->execute($sql, $binds, $this->resolve($options)),
            $sql,
            $this->state,
            array_map(strval(...), array_keys($binds)),
        );
    }

    /**
     * Runs a query and returns its rows: the same as `run()->rows()`.
     *
     * Under Autocommit the query commits too, so `SELECT … FOR UPDATE`
     * loses its locks at once: run it inside a transaction to keep them.
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
        return Call::of($name, $binds)->procedure($this->driver, $this->resolve($options), $this->state);
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
            fn (Bind $return): OutValue => $call->function($this->driver, $this->resolve($options), $return, $this->state),
        );
    }

    /**
     * Array DML: runs one DML statement or PL/SQL block once per bind row,
     * every row in one execute and one Round trip. There is no batch size:
     * chunk large inputs yourself.
     *
     * Each row binds like `run()`'s binds, and every row has the same keys.
     * A column takes one type, set by its first non-null value (an int and
     * a float mix as NUMBER); a string over 32767 bytes makes its whole
     * column CLOB; a typed bind keeps its class down its column.
     *
     * The call is atomic: when rows fail, none of its rows is applied, and
     * one `BatchException` lists every failing DML row. Under Autocommit
     * the rows commit with the execute; inside a transaction a savepoint
     * (one Round trip more) undoes only this call. A PL/SQL block stops at
     * its first failing row with that server error.
     *
     * @param list<array<string, mixed>> $rows each keyed by placeholder name, without ':'
     *
     * @throws BatchException listing every failing row
     * @throws UsageException for rows that differ in keys or column types, a query, a REF CURSOR or Implicit results
     */
    public function runMany(string $sql, #[\SensitiveParameter] array $rows, ?ExecuteOptions $options = null): Batch
    {
        return new Batch(
            $this->driver->executeMany($sql, $rows, $this->resolve($options)),
            \count($rows),
            $sql,
            $this->state,
            array_map(strval(...), array_keys($rows[0] ?? [])),
        );
    }

    /**
     * Runs `$callback` in an Explicit transaction and returns its value.
     *
     * The transaction commits when the closure returns. When it throws
     * anything, the transaction rolls back and the same exception is
     * rethrown; if that rollback fails too, its failure is chained as the
     * exception's `previous` (at the end of the chain, if it already has
     * one). If the commit fails (a deferred constraint, ORA-02091), its
     * exception propagates. Either way, the Connection is back in
     * Autocommit.
     *
     * Inside the closure, `begin()`, `commit()`, `rollback()` and
     * `transaction()` throw. Savepoints go through `run('SAVEPOINT name')`.
     *
     * @template T
     *
     * @param \Closure(self): T $callback receives this Connection
     *
     * @return T
     *
     * @throws UsageException inside another transaction, before any Round trip
     * @throws \Throwable what the closure throws, or the commit's failure
     */
    public function transaction(\Closure $callback): mixed
    {
        $this->begin();
        $this->inCallback = true;
        try {
            $result = $callback($this);
        } catch (\Throwable $e) {
            $this->inCallback = false;
            $this->rollBackAfter($e);
        }
        $this->inCallback = false;
        $this->commit();

        return $result;
    }

    /**
     * Opens an Explicit transaction, with no Round trip: statements stop
     * committing on their own until `commit()` or `rollback()`.
     *
     * Transactions are flat: savepoints go through `run('SAVEPOINT name')`,
     * and `SET TRANSACTION` through `run()` right after `begin()`.
     *
     * @throws UsageException inside a transaction, before any Round trip
     */
    public function begin(): void
    {
        $this->ensureOpen();
        if ($this->inTransaction) {
            throw new UsageException(
                "Transactions do not nest, and one is already open. Use run('SAVEPOINT name') for a savepoint.",
            );
        }
        $this->inTransaction = true;
    }

    /**
     * Commits the Explicit transaction, in one Round trip, and goes back to
     * Autocommit, also when the commit fails.
     *
     * @throws UsageException outside a transaction or inside `transaction()`, before any Round trip
     * @throws OracleException from the server, such as ORA-02091 for a deferred constraint
     */
    public function commit(): void
    {
        $this->ensureEnding('commit()', 'commit');
        try {
            $this->driver->commit();
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * Rolls the Explicit transaction back, in one Round trip, and goes back
     * to Autocommit.
     *
     * @throws UsageException outside a transaction or inside `transaction()`, before any Round trip
     * @throws OracleException from the server, or for a Broken Connection
     */
    public function rollback(): void
    {
        $this->ensureEnding('rollback()', 'roll back');
        try {
            $this->driver->rollback();
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * Replaces the whole tracing set a DBA sees in V$SESSION (module,
     * action, client identifier, client info): what `$tracing` leaves unset
     * becomes empty. It makes no Round trip of its own; the next one carries
     * it. The next Connection on this Session starts from its own config's
     * tracing again.
     *
     * @throws UsageException after `close()`
     * @throws OracleException for a Broken Connection
     */
    public function trace(Tracing $tracing): void
    {
        $this->ensureOpen();
        $this->driver->trace($tracing);
    }

    /**
     * Releases the Session now: an open transaction rolls back (never
     * commits), the Session goes back to the Pool, and every later call on
     * this Connection or its Cursors fails. Idempotent.
     *
     * Without it, the Release happens when the Connection and all its
     * Cursors are gone.
     *
     * The Release resets nothing else: a pooled Session keeps its package
     * state and `ALTER SESSION` settings for its next user. A Standalone
     * Connection (`standalone: true`) logs off instead, so every one starts
     * clean.
     */
    public function close(): void
    {
        $this->state->released = true;
        $this->driver->close();
    }

    /**
     * Rolls back after the closure of `transaction()` threw `$e`, then
     * rethrows `$e`, with a failed rollback chained as its `previous`.
     */
    private function rollBackAfter(\Throwable $e): never
    {
        try {
            // A closure that closed the Connection has rolled back already.
            if (!$this->state->released) {
                $this->driver->rollback();
            }
        } catch (\Throwable $failure) {
            $last = $e;
            while ($last !== $failure && ($previous = $last->getPrevious()) !== null) {
                $last = $previous;
            }
            if ($last !== $failure) {
                // Throwable has no setter: `previous` is private to Exception and to Error.
                new \ReflectionProperty($last instanceof \Exception ? \Exception::class : \Error::class, 'previous')
                    ->setValue($last, $failure);
            }
        } finally {
            $this->inTransaction = false;
        }

        throw $e;
    }

    private function ensureOpen(): void
    {
        if ($this->state->released) {
            throw new UsageException('The Connection is closed.');
        }
    }

    /**
     * Fails unless `commit()` or `rollback()` can end a transaction now.
     */
    private function ensureEnding(string $method, string $verb): void
    {
        $this->ensureOpen();
        if ($this->inCallback) {
            throw new UsageException(
                "$method inside transaction(): the transaction commits when the closure returns and rolls back when it throws.",
            );
        }
        if (!$this->inTransaction) {
            throw new UsageException(
                "There is no transaction to $verb: call begin() first. Outside a transaction, every statement commits on its own.",
            );
        }
    }

    private function resolve(?ExecuteOptions $options): DriverOptions
    {
        $options ??= new ExecuteOptions();

        return new DriverOptions(
            autocommit: !$this->inTransaction,
            timeout: $options->timeout ?? $this->config->callTimeout,
            fetchSize: $options->fetchSize ?? $this->config->fetchSize,
            values: $options->values ?? $this->config->values,
            rowShape: $options->rowShape ?? $this->config->rowShape,
            keyCase: $options->keyCase ?? $this->config->keyCase,
        );
    }
}
