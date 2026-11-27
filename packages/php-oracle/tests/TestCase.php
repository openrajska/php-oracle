<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests;

use Override;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Oracle;
use RuntimeException;

/**
 * Every test runs in a schema of its own process, `test` or
 * `test_<TEST_TOKEN>` under `--parallel`, as Laravel's parallel testing
 * gives each process its own database. The admin user creates it, empty, on
 * the first use; each test file creates the objects it reads.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** The Session the tests watch from: another user of the database. */
    private static Connection | null $observer = null;

    /**
     * A config for the process's schema; named arguments override fields.
     */
    public static function config(mixed ...$fields): ConnectionConfig
    {
        $token = getenv('TEST_TOKEN');
        $schema = \is_string($token) && $token !== '' ? "test_$token" : 'test';
        // A child process a test starts (another FPM worker) inherits the
        // marker, and finds the schema in place.
        if (getenv('TEST_SCHEMA_READY') !== $schema) {
            self::createSchema($schema);
            putenv("TEST_SCHEMA_READY=$schema");
        }

        // @phpstan-ignore argument.type (the named fields go through as given)
        return new ConnectionConfig(...['dsn' => self::env('ORACLE_CONNECT_STRING'), 'user' => $schema, 'password' => $schema, ...$fields]);
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        self::$observer?->close();
        self::$observer = null;
        parent::tearDownAfterClass();
    }

    /**
     * Another Session, as a second user sees the database: committed rows
     * only.
     */
    protected function otherSession(): Connection
    {
        return self::$observer ??= Oracle::connect(self::config(standalone: true));
    }

    /**
     * Whether another transaction holds the row: `FOR UPDATE NOWAIT` fails
     * with ORA-00054. Under Autocommit the query keeps no lock itself.
     */
    protected function locked(string $table, int $id): bool
    {
        try {
            $this->otherSession()->select("select id from $table where id = :id for update nowait", ['id' => $id])->all();

            return false;
        } catch (QueryException $queryException) {
            return $queryException->getCode() === 54 ? true : throw $queryException;
        }
    }

    /**
     * Kills a Session as a DBA would: `immediate` ends it at once, otherwise
     * its next call gets ORA-00028 first.
     */
    protected function kill(int $sid, bool $immediate = true): void
    {
        $serial = $this->otherSession()->select('select serial# from v$session where sid = :sid', ['sid' => $sid])->value();
        \assert(\is_int($serial));
        $this->otherSession()->run("alter system kill session '$sid,$serial'" . ($immediate ? ' immediate' : ''));
    }

    /**
     * The Round trips the Session with this SID made while $action ran,
     * read from V$SESSTAT by another Session: reading costs it nothing.
     *
     * @param callable(): mixed $action
     */
    protected function roundTrips(int $sid, callable $action): int
    {
        $before = $this->roundTripsSoFar($sid);
        $action();

        return $this->roundTripsSoFar($sid) - $before;
    }

    private function roundTripsSoFar(int $sid): int
    {
        $count = $this->otherSession()->select(<<<'SQL_WRAP'
        select s.value
          from v$sesstat s
          join v$statname n on n.statistic# = s.statistic#
         where n.name = 'SQL*Net roundtrips to/from client'
           and s.sid = :sid
        SQL_WRAP, ['sid' => $sid])->value();

        return \is_int($count) ? $count : throw new RuntimeException("No Session with SID $sid.");
    }

    /**
     * The user, whose password is its name, with read access to the V$ views
     * the tests assert on, ALTER SYSTEM to kill a Session of its own, and the
     * proxy client `<name>_CLIENT` it may connect through.
     */
    private static function createSchema(string $schema): void
    {
        $admin = Oracle::connect(new ConnectionConfig(
            dsn: self::env('ORACLE_CONNECT_STRING'),
            user: self::env('ORACLE_ADMIN_USER'),
            password: self::env('ORACLE_ADMIN_PASSWORD'),
            standalone: true,
        ));

        try {
            if ($admin->select('select count(*) from dba_users where username = upper(:name)', ['name' => $schema])->value() === 1) {
                return;
            }
            $admin->run("create user $schema identified by \"$schema\" default tablespace users quota unlimited on users");
            $admin->run("grant db_developer_role, select any dictionary, alter system to $schema");
            $admin->run("create user {$schema}_client no authentication");
            $admin->run("grant create session to {$schema}_client");
            $admin->run("alter user {$schema}_client grant connect through $schema");
        } finally {
            $admin->close();
        }
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            throw new RuntimeException("Set $name to reach the test database.");
        }

        return $value;
    }
}
