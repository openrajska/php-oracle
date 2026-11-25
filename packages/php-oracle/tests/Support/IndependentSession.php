<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

use RuntimeException;

/**
 * A Session of its own, opened outside the code under test, for assertions
 * about what another Session sees: committed rows, locks, V$ views and
 * Round-trip counts.
 *
 * It talks to Oracle through oci8, never through a Backend, so a bug in the
 * Backend cannot hide itself. Nothing is committed unless commit() is called.
 */
final class IndependentSession
{
    /** @var resource|null */
    private $connection;

    private readonly int $sid;

    /**
     * @param resource $connection
     */
    private function __construct($connection)
    {
        $this->connection = $connection;
        $this->sid = (int) $this->select("select sys_context('userenv', 'sid') as sid from dual")[0]['SID'];
    }

    public static function open(): self
    {
        $database = TestDatabase::fromEnvironment();
        // oci_new_connect, not oci_connect: oci_connect hands back the same
        // Session for the same credentials within a request.
        $connection = @oci_new_connect($database->user, $database->password, $database->connectString, 'AL32UTF8');
        if ($connection === false) {
            throw self::failure(oci_error());
        }

        return new self($connection);
    }

    public function sid(): int
    {
        return $this->sid;
    }

    /**
     * Rows keyed by upper-case column name, every value as oci8 returns it
     * (strings, or null).
     *
     * @param array<string, scalar|null> $binds
     * @return list<array<string, string|null>>
     */
    public function select(string $sql, array $binds = []): array
    {
        $statement = $this->run($sql, $binds);
        oci_fetch_all($statement, $rows, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW | \OCI_ASSOC | \OCI_RETURN_NULLS);
        oci_free_statement($statement);

        /** @var list<array<string, string|null>> $rows */
        return $rows;
    }

    /**
     * Runs a statement or an anonymous block without committing.
     *
     * @param array<string, scalar|null> $binds
     */
    public function execute(string $sql, array $binds = []): void
    {
        oci_free_statement($this->run($sql, $binds));
    }

    public function commit(): void
    {
        if (!@oci_commit($this->connection())) {
            throw self::failure(oci_error($this->connection()));
        }
    }

    public function rollback(): void
    {
        if ($this->connection !== null && !@oci_rollback($this->connection)) {
            throw self::failure(oci_error($this->connection));
        }
    }

    /**
     * Ends the Session. Idempotent.
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            oci_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * @param array<string, scalar|null> $binds
     * @return resource
     */
    private function run(string $sql, array $binds)
    {
        $statement = @oci_parse($this->connection(), $sql);
        if ($statement === false) {
            throw self::failure(oci_error($this->connection()));
        }

        // oci_bind_by_name binds by reference: keep each value alive until
        // the execute.
        $values = $binds;
        foreach (array_keys($values) as $name) {
            oci_bind_by_name($statement, ':' . $name, $values[$name]);
        }

        if (!@oci_execute($statement, \OCI_NO_AUTO_COMMIT)) {
            $error = oci_error($statement);
            oci_free_statement($statement);
            throw self::failure($error);
        }

        return $statement;
    }

    /**
     * @return resource
     */
    private function connection()
    {
        return $this->connection ?? throw new RuntimeException('The IndependentSession is closed.');
    }

    /**
     * @param array<mixed>|false $error what oci_error() returned
     */
    private static function failure(array|false $error): RuntimeException
    {
        $message = $error['message'] ?? null;
        $code = $error['code'] ?? null;

        return \is_string($message) && \is_int($code)
            ? new RuntimeException($message, $code)
            : new RuntimeException('oci8 failed without an Oracle error.');
    }
}
