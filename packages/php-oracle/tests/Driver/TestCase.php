<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver;

use Override;
use Rajska\Oracle\Contracts\Driver\Manager;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\ExtensionManager;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\TestCase as BaseTestCase;

/**
 * The tests of the Driver contract, the seam every backend implements:
 * `new ExtensionManager()->connect($config)`.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var list<Session> */
    private array $connections = [];

    private static int $pools = 0;

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        parent::tearDown();
    }

    protected function driver(): Manager
    {
        return new ExtensionManager();
    }

    protected function connect(mixed ...$fields): Session
    {
        $connection = $this->driver()->connect(self::config(...$fields));
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

    public static function options(
        int $fetchSize = 100,
        bool $autocommit = true,
        float | int | null $timeout = null,
        ValueMapping $values = new ValueMapping(),
        RowShape $rowShape = RowShape::Assoc,
        KeyCase $keyCase = KeyCase::Native,
    ): Options {
        return new Options(
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
    public static function rows(Session $connection, string $sql, array $binds = [], Options | null $options = null): array
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
    protected static function value(Session $connection, string $sql, array $binds = []): mixed
    {
        $cursor = $connection->execute($sql, $binds, self::options(autocommit: false))->cursor();
        self::assertNotNull($cursor, "Not a query: $sql");
        $rows = $cursor->fetchBatch();
        $cursor->close();
        self::assertNotSame([], $rows, "No row: $sql");

        return array_values($rows[0])[0];
    }

    protected static function sid(Session $connection): int
    {
        $sid = self::value($connection, "select sys_context('userenv', 'sid') from dual");
        self::assertIsString($sid);

        return (int) $sid;
    }

    /**
     * Which Session is behind a Connection: its AUDSID, unique per logon
     * (a SID is reused by the next Session in its slot).
     */
    protected static function session(Session $connection): string
    {
        $session = self::value($connection, "select sys_context('userenv', 'sessionid') from dual");
        self::assertIsString($session);

        return $session;
    }
}
