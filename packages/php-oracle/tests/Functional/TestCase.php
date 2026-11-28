<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional;

use Override;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\TestCase as BaseTestCase;

/**
 * The tests of Core's public API, as an application uses it:
 * `Oracle::connect($config)`.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        parent::tearDown();
    }

    /**
     * A standalone Connection to the process's schema, closed after the
     * test: no Session outlives the test, as Laravel purges its connections
     * between tests. Named arguments override config fields; a test of the
     * Pool passes `standalone: false`.
     */
    public function connect(mixed ...$fields): Connection
    {
        $connection = Oracle::connect(self::config(...['standalone' => true, ...$fields]));
        $this->connections[] = $connection;

        return $connection;
    }

    public static function sid(Connection $connection): int
    {
        $sid = $connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);

        return (int) $sid;
    }
}
