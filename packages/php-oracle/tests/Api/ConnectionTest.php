<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;

/**
 * The first path through every layer: connect, select a value, run DML.
 */
final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function select_value_returns_an_integral_number_as_an_int(): void
    {
        self::assertSame(1, $this->connection->select('select id from fx_items where id = :id', ['id' => 1])->value());
    }

    #[Test]
    public function a_string_bind_matches_a_varchar2_that_arrives_as_a_string(): void
    {
        self::assertSame('Item 0002', $this->connection->select(
            'select name from fx_items where name = :name',
            ['name' => 'Item 0002'],
        )->value());
    }

    #[Test]
    public function a_non_integral_number_arrives_as_its_exact_string(): void
    {
        self::assertSame('2.5', $this->connection->select('select price from fx_items where id = 2')->value());
    }

    #[Test]
    public function null_arrives_as_null(): void
    {
        self::assertNull($this->connection->select('select cast(null as number) from dual')->value());
        self::assertNull($this->connection->select('select cast(null as varchar2(10)) from dual')->value());
    }

    #[Test]
    public function value_of_an_empty_result_is_null(): void
    {
        self::assertNull($this->connection->select('select id from fx_items where id = :id', ['id' => -1])->value());
    }

    #[Test]
    public function run_returns_the_dml_count(): void
    {
        self::assertSame(3, $this->connection->run('update fx_items set name = name where id <= :last', ['last' => 3])->affected());
    }

    #[Test]
    public function a_query_affects_nothing(): void
    {
        self::assertSame(0, $this->connection->run('select id from fx_items')->affected());
    }

    #[Test]
    public function rows_of_a_non_query_fail(): void
    {
        $this->expectException(UsageException::class);

        $this->connection->select('update fx_items set name = name where id = 1');
    }

    #[Test]
    public function a_wrong_password_throws_connection_exception_with_code_1017(): void
    {
        try {
            Oracle::connect(TestDatabase::fromEnvironment()->config(password: 'not-the-password'));
            self::fail('connect() succeeded with a wrong password.');
        } catch (ConnectionException $e) {
            self::assertSame(1017, $e->getCode());
            self::assertStringStartsWith('ORA-01017', $e->getMessage());
        }
    }

    #[Test]
    public function a_call_after_close_fails_and_a_second_close_is_fine(): void
    {
        $this->connection->close();

        try {
            $this->connection->run('select 1 from dual');
            self::fail('run() after close() succeeded.');
        } catch (UsageException $e) {
            self::assertSame('The Connection is closed.', $e->getMessage());
            self::assertSame('select 1 from dual', $e->sql());
        }
        $this->connection->close();
    }

    #[Test]
    public function server_version_is_the_five_part_version_full_with_no_round_trip(): void
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);
        $counter = RoundTripCounter::open();

        try {
            $version = null;
            $trips = $counter->during((int) $sid, function () use (&$version): void {
                $version = $this->connection->serverVersion();
            });
        } finally {
            $counter->close();
        }

        self::assertSame(0, $trips);
        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+\.\d+\.\d+$/', $version);
        self::assertSame(
            $this->connection->select("select version_full from product_component_version where product like 'Oracle%'")->value(),
            $version,
        );
    }

    #[Test]
    public function server_version_after_close_fails(): void
    {
        $this->connection->close();

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('The Connection is closed.');

        $this->connection->serverVersion();
    }
}
