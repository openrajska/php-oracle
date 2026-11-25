<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Driver\Driver;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Driver\Extension\ExtensionDriver;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;
use RuntimeException;

/**
 * The conformance tests run at the Driver contract seam:
 * `new ExtensionDriver()->connect($config)`.
 */
abstract class ConformanceTestCase extends TestCase
{
    /** @var list<DriverConnection> */
    private array $connections = [];

    private static int $pools = 0;

    /**
     * The Session kill() and locked() look from, opened on first use and
     * shared by the test class: a logon per look adds to the listener's
     * load, which refuses logons with ORA-12516 when it runs high.
     */
    private static ?IndependentSession $observer = null;

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }

    public static function tearDownAfterClass(): void
    {
        self::$observer?->close();
        self::$observer = null;
    }

    protected function driver(): Driver
    {
        return new ExtensionDriver();
    }

    protected function connect(mixed ...$fields): DriverConnection
    {
        $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config(...$fields));
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * Config fields that give a test a Pool of its own: a Pool lives as long
     * as the process, so one test's Sessions would otherwise be another's.
     * Only `connectTimeout` changes, by a millisecond per call.
     *
     * @return array{connectTimeout: float}
     */
    protected static function ownPool(): array
    {
        return ['connectTimeout' => 30 + ++self::$pools / 1000];
    }

    protected static function options(
        int $fetchSize = 100,
        bool $autocommit = true,
        int|float|null $timeout = null,
        ValueMapping $values = new ValueMapping(),
        RowShape $rowShape = RowShape::Assoc,
        KeyCase $keyCase = KeyCase::Native,
    ): DriverOptions {
        return new DriverOptions(
            autocommit: $autocommit,
            timeout: $timeout,
            fetchSize: $fetchSize,
            values: $values,
            rowShape: $rowShape,
            keyCase: $keyCase,
        );
    }

    /**
     * @param array<string, mixed> $binds
     *
     * @return list<array<int|string, mixed>>
     *
     * @phpstan-impure
     */
    protected static function rows(DriverConnection $connection, string $sql, array $binds = [], ?DriverOptions $options = null): array
    {
        $cursor = $connection->execute($sql, $binds, $options ?? self::options())->cursor();
        self::assertNotNull($cursor, "Not a query: $sql");
        $rows = [];
        while (($batch = $cursor->fetchBatch()) !== []) {
            array_push($rows, ...$batch);
        }
        $cursor->close();

        return $rows;
    }

    /**
     * The first column of the first row of a query. It runs without
     * Autocommit, so reading never commits the test's open work.
     *
     * @param array<string, mixed> $binds
     */
    protected static function value(DriverConnection $connection, string $sql, array $binds = []): mixed
    {
        $cursor = $connection->execute($sql, $binds, self::options(autocommit: false))->cursor();
        self::assertNotNull($cursor, "Not a query: $sql");
        $rows = $cursor->fetchBatch();
        $cursor->close();
        self::assertNotSame([], $rows, "No row: $sql");

        return array_values($rows[0])[0];
    }

    protected static function sid(DriverConnection $connection): int
    {
        $sid = self::value($connection, "select sys_context('userenv', 'sid') from dual");
        self::assertIsString($sid);

        return (int) $sid;
    }

    /**
     * Which Session is behind a Connection: its AUDSID, unique per logon
     * (a SID is reused by the next Session in its slot).
     */
    protected static function session(DriverConnection $connection): string
    {
        $session = self::value($connection, "select sys_context('userenv', 'sessionid') from dual");
        self::assertIsString($session);

        return $session;
    }

    /**
     * Kills the Session behind a Connection from another Session, as a DBA
     * would: `immediate` ends it at once, otherwise its next call gets
     * ORA-00028 first.
     */
    protected static function kill(DriverConnection $connection, bool $immediate = true): void
    {
        $sid = self::sid($connection);
        $observer = self::observer();
        $serial = $observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $sid])[0]['SERIAL'];
        $observer->execute("alter system kill session '$sid,$serial'" . ($immediate ? ' immediate' : ''));
    }

    /**
     * Whether another Session finds the fx_items row locked
     * (`FOR UPDATE NOWAIT` fails with ORA-00054). It never keeps the lock.
     */
    protected static function locked(int $item): bool
    {
        $observer = self::observer();
        try {
            $observer->select('select id from fx_items where id = :id for update nowait', ['id' => $item]);

            return false;
        } catch (RuntimeException $e) {
            self::assertSame(54, $e->getCode(), $e->getMessage());

            return true;
        } finally {
            $observer->rollback();
        }
    }

    private static function observer(): IndependentSession
    {
        return self::$observer ??= IndependentSession::open();
    }
}
