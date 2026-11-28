<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests;

use Override;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Oracle;
use RuntimeException;

/**
 * What a test asks of the database besides the code under test: the
 * Connection it creates its objects through, and another Session to watch
 * from, as Laravel's InteractsWithDatabase gives a test its assertions.
 */
trait InteractsWithDatabase
{
    /** The Session the tests create and seed their objects through. */
    private static Connection | null $schema = null;

    /** The Session the test watches from: another user of the database. */
    private Connection | null $otherSession = null;

    /**
     * The Connection the test creates and seeds its objects through: one
     * standalone Session per process. Its DDL waits for a Session a previous
     * test killed, while Oracle rolls that transaction back and holds the
     * table.
     */
    public function schema(): Connection
    {
        if (! self::$schema instanceof Connection) {
            self::$schema = Oracle::connect(self::config(standalone: true));
            self::$schema->run('alter session set ddl_lock_timeout = 10');
        }

        return self::$schema;
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->otherSession?->close();
        $this->otherSession = null;
        parent::tearDown();
    }

    /**
     * Another Session, as a second user sees the database: committed rows
     * only.
     */
    public function otherSession(): Connection
    {
        return $this->otherSession ??= Oracle::connect(self::config(standalone: true));
    }

    /**
     * Whether another transaction holds the row: `FOR UPDATE NOWAIT` fails
     * with ORA-00054. Under Autocommit the query keeps no lock itself.
     */
    public function locked(string $table, int $id): bool
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
    public function kill(int $sid, bool $immediate = true): void
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
    public function roundTrips(int $sid, callable $action): int
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
}
