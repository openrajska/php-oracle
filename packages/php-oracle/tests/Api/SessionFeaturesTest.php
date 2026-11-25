<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tracing;

/**
 * Tracing and the Call timeout through Core's public API.
 */
final class SessionFeaturesTest extends TestCase
{
    /**
     * Burns CPU for seconds: the server stops it when the Call timeout
     * breaks in, so the Connection survives.
     */
    private const string CPU_BOUND = 'select count(*) from fx_bench_rows a, fx_bench_rows b';

    private const string ONE_SECOND = 'begin fx_pool.sleep(1); end;';

    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    #[Test]
    public function trace_replaces_the_tracing_the_session_reports(): void
    {
        $connection = $this->connect(tracing: new Tracing(module: 'billing', action: 'close month'));
        self::assertSame(['billing', 'close month', null], $this->tracing($connection));

        $connection->trace(new Tracing(module: 'reports', clientIdentifier: 'user-42'));

        self::assertSame(['reports', null, 'user-42'], $this->tracing($connection));
    }

    #[Test]
    public function trace_after_close_fails(): void
    {
        $connection = $this->connect();
        $connection->close();

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('The Connection is closed.');
        $connection->trace(new Tracing(module: 'late'));
    }

    #[Test]
    public function the_config_call_timeout_throws_timeout_exception(): void
    {
        $connection = $this->connect(callTimeout: 0.5);

        try {
            $connection->select(self::CPU_BOUND)->value();
            self::fail('The query finished within the Call timeout.');
        } catch (TimeoutException $e) {
            self::assertSame('Call timeout of 500 ms exceeded.', $e->getMessage());
            self::assertSame(self::CPU_BOUND, $e->sql());
        }

        self::assertSame(1, $connection->select('select 1 from dual')->value());
    }

    #[Test]
    public function the_execute_options_timeout_applies_to_one_execution_only(): void
    {
        $connection = $this->connect(callTimeout: 0.5);

        $connection->run(self::ONE_SECOND, [], new ExecuteOptions(timeout: 5));

        $this->expectException(TimeoutException::class);
        $connection->select(self::CPU_BOUND)->value();
    }

    #[Test]
    public function a_new_acquire_uses_its_own_configs_call_timeout(): void
    {
        $pool = ['maxConnections' => 1, 'connectTimeout' => 30.061];
        $first = $this->connect(...$pool, callTimeout: 5);
        $first->run(self::ONE_SECOND);
        $first->close();

        $second = $this->connect(...$pool, callTimeout: 0.5);
        $this->expectException(TimeoutException::class);
        $second->select(self::CPU_BOUND)->value();
    }

    private function connect(mixed ...$fields): Connection
    {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(...$fields));
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * Module, action and client identifier, as the Session reports them.
     *
     * @return list<mixed>
     */
    private function tracing(Connection $connection): array
    {
        return array_values($connection->select(<<<'SQL'
            select sys_context('userenv', 'module'),
                   sys_context('userenv', 'action'),
                   sys_context('userenv', 'client_identifier')
              from dual
            SQL)->first() ?? []);
    }
}
