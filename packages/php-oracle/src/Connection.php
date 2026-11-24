<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Collection;
use Rajska\Oracle\Bind\PlsInteger;
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
     * DBMS_OUTPUT was turned on through `enableOutput()`: it is turned off
     * before the Release.
     */
    private bool $outputEnabled = false;

    /**
     * Lines per `DBMS_OUTPUT.GET_LINES` Round trip.
     */
    private const int OUTPUT_BATCH = 1000;

    /**
     * The options of a call that passes none, resolved once per
     * Autocommit state: index 1 under Autocommit, 0 inside a transaction.
     *
     * @var array<int, DriverOptions>
     */
    private array $defaults = [];

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
            array_keys($binds),
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
            array_keys($rows[0] ?? []),
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
     * The Server version, five-part as Oracle writes it (`23.26.3.0.0` on
     * 26ai), for `version_compare()`. It comes from the connect handshake,
     * with no Round trip.
     *
     * @throws UsageException after `close()`
     * @throws OracleException for a Broken Connection
     */
    public function serverVersion(): string
    {
        $this->ensureOpen();

        return $this->driver->serverVersion();
    }

    /**
     * Turns DBMS_OUTPUT on for this Session, with an unlimited buffer, and
     * empties it first: `output()` then gives only what PL/SQL writes from
     * now on. One Round trip; it never commits.
     *
     * Output enabled here is turned off again (and its unread lines
     * dropped) before the Session goes back to the Pool, so its next user
     * never sees it: on `close()`, or as the Connection is dropped. A PHP
     * fatal error (memory or time limit) runs no destructor and skips it;
     * the next user's own `enableOutput()` still starts empty.
     *
     * @throws UsageException after `close()`
     * @throws OracleException from the server, or for a Broken Connection
     */
    public function enableOutput(): void
    {
        $this->dbmsOutput('BEGIN DBMS_OUTPUT.DISABLE; DBMS_OUTPUT.ENABLE(NULL); END;');
        $this->outputEnabled = true;
    }

    /**
     * Drains every line DBMS_OUTPUT holds for this Session, in order: one
     * Round trip per 1000 lines, and `[]` when there is none. It reads
     * output enabled through raw SQL too. An empty line is `''`. It never
     * commits.
     *
     * @return list<string>
     *
     * @throws UsageException after `close()`
     * @throws OracleException from the server, or for a Broken Connection
     */
    public function output(): array
    {
        $lines = [];
        do {
            $execution = $this->dbmsOutput('BEGIN DBMS_OUTPUT.GET_LINES(:lines, :count); END;', [
                'lines' => Collection::out('SYS.DBMSOUTPUT_LINESARRAY'),
                'count' => PlsInteger::inOut(self::OUTPUT_BATCH),
            ]);
            $count = $execution->out('count')->int() ?? 0;
            foreach (\array_slice($execution->out('lines')->array() ?? [], 0, $count) as $line) {
                $lines[] = \is_string($line) ? $line : '';
            }
        } while ($count === self::OUTPUT_BATCH);

        return $lines;
    }

    /**
     * Turns DBMS_OUTPUT off for this Session, however it was turned on, and
     * drops its unread lines. One Round trip; it never commits.
     *
     * @throws UsageException after `close()`
     * @throws OracleException from the server, or for a Broken Connection
     */
    public function disableOutput(): void
    {
        $this->dbmsOutput('BEGIN DBMS_OUTPUT.DISABLE; END;');
        $this->outputEnabled = false;
    }

    /**
     * Releases the Session now: an open transaction rolls back (never
     * commits), the Session goes back to the Pool, and every later call on
     * this Connection or its Cursors fails. Idempotent.
     *
     * Without it, the Release happens when the Connection and all its
     * Cursors are gone.
     *
     * Output turned on with `enableOutput()` is turned off first, in one
     * Round trip whose failure is ignored. The Release resets nothing
     * else: a pooled Session keeps its package state, `ALTER SESSION`
     * settings and DBMS_OUTPUT enabled through raw SQL for its next user. A
     * Standalone Connection (`standalone: true`) logs off instead, so every
     * one starts clean.
     */
    public function close(): void
    {
        $this->disableOutputBeforeRelease();
        $this->state->released = true;
        $this->driver->close();
    }

    /**
     * Turns output enabled through `enableOutput()` off when the Connection
     * is dropped without `close()`, before the Release that follows once its
     * Cursors are gone too. Nothing else happens here: the Release itself
     * runs in the Backend.
     */
    public function __destruct()
    {
        $this->disableOutputBeforeRelease();
    }

    /**
     * Runs `DBMS_OUTPUT.DISABLE` if output was enabled through
     * `enableOutput()`, so its unread lines never reach the Session's next
     * user. Its failure (a Broken Connection, say) is ignored: the Release
     * goes on.
     */
    private function disableOutputBeforeRelease(): void
    {
        if (!$this->outputEnabled || $this->state->released) {
            return;
        }
        $this->outputEnabled = false;
        try {
            $this->dbmsOutput('BEGIN DBMS_OUTPUT.DISABLE; END;');
        } catch (\Throwable) {
            // Swallowed by design (spec #32 §10): the Release must happen.
        }
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

    /**
     * Runs a DBMS_OUTPUT block without Autocommit: it has no work of its
     * own to commit, and must never commit an open transaction's.
     *
     * @param array<string, mixed> $binds
     */
    private function dbmsOutput(string $sql, array $binds = []): Execution
    {
        $this->ensureOpen();
        $options = $this->resolve(null);

        return new Execution(
            $this->driver->execute($sql, $binds, new DriverOptions(
                autocommit: false,
                timeout: $options->timeout,
                fetchSize: $options->fetchSize,
                values: $options->values,
                rowShape: $options->rowShape,
                keyCase: $options->keyCase,
            )),
            $sql,
            $this->state,
            array_keys($binds),
        );
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
        if ($options === null) {
            // The ConnectionConfig is immutable: these never change.
            return $this->defaults[(int) !$this->inTransaction] ??= $this->resolve(new ExecuteOptions());
        }

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
