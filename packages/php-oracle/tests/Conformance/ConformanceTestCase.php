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
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;

/**
 * The conformance catalog of spec #32 runs at the Driver contract seam:
 * `new ExtensionDriver()->connect($config)`. Every Connection a test opens
 * through connect() is closed after it.
 */
abstract class ConformanceTestCase extends TestCase
{
    /** @var list<DriverConnection> */
    private array $connections = [];

    private static int $pools = 0;

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
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

    protected static function options(int $fetchSize = 100, bool $autocommit = true): DriverOptions
    {
        return new DriverOptions(
            autocommit: $autocommit,
            timeout: null,
            fetchSize: $fetchSize,
            values: new ValueMapping(),
            rowShape: RowShape::Assoc,
            keyCase: KeyCase::Native,
        );
    }

    /**
     * The first column of the first row of a query.
     *
     * @param array<string, mixed> $binds
     */
    protected static function value(DriverConnection $connection, string $sql, array $binds = []): mixed
    {
        $cursor = $connection->execute($sql, $binds, self::options())->cursor();
        self::assertNotNull($cursor, "Not a query: $sql");
        $rows = $cursor->fetchBatch();
        $cursor->close();
        self::assertNotSame([], $rows, "No row: $sql");

        return array_values($rows[0])[0];
    }

    /**
     * The SID of the Session behind a Connection.
     */
    protected static function sid(DriverConnection $connection): int
    {
        $sid = self::value($connection, "select sys_context('userenv', 'sid') from dual");
        self::assertIsString($sid);

        return (int) $sid;
    }
}
