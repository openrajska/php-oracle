<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Exceptions\ArrayDmlException;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\TestDatabase;
use RuntimeException;

final class ErrorsTest extends ConformanceTestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function hierarchy(): iterable
    {
        yield 'QueryException' => [QueryException::class, RuntimeException::class];
        yield 'UniqueViolationException' => [UniqueViolationException::class, QueryException::class];
        yield 'ArrayDmlException' => [ArrayDmlException::class, QueryException::class];
        yield 'ConnectionException' => [ConnectionException::class, RuntimeException::class];
        yield 'PoolExhaustedException' => [PoolExhaustedException::class, ConnectionException::class];
        yield 'ConnectionLostException' => [ConnectionLostException::class, ConnectionException::class];
        yield 'TimeoutException' => [TimeoutException::class, RuntimeException::class];
        yield 'UnsupportedException' => [UnsupportedException::class, RuntimeException::class];
        yield 'ConfigurationException' => [ConfigurationException::class, LogicException::class];
        yield 'UsageException' => [UsageException::class, LogicException::class];
    }

    /**
     * @param class-string $class
     * @param class-string $parent
     */
    #[Test]
    #[DataProvider('hierarchy')]
    public function every_exception_implements_oracle_exception_under_its_spl_parent(string $class, string $parent): void
    {
        self::assertTrue(is_subclass_of($class, OracleException::class), "$class implements OracleException");
        self::assertTrue(is_subclass_of($class, $parent), "$class extends $parent");
    }

    #[Test]
    public function a_duplicate_gives_unique_violation_exception(): void
    {
        $sql = "insert into fx_items (id, category_id, name, price, created_at) values (1, 1, 'duplicate', 1, sysdate)";

        try {
            $this->connect()->execute($sql, [], self::options());
            self::fail('The duplicate was inserted.');
        } catch (UniqueViolationException $uniqueViolationException) {
            self::assertSame(1, $uniqueViolationException->getCode());
            self::assertStringStartsWith('ORA-00001', $uniqueViolationException->getMessage());
            self::assertSame($sql, $uniqueViolationException->sql());
            self::assertNull($uniqueViolationException->call());
        }
    }

    /** A compilation error is success with info, not a throw. */
    #[Test]
    public function a_compilation_error_gives_a_warning_and_a_valid_unit_gives_null(): void
    {
        $connection = $this->connect();
        $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));
        $invalid = "create or replace procedure $procedure is begin no_such_thing; end;";
        $valid = "create or replace procedure $procedure is begin null; end;";

        try {
            $warning = $connection->execute($invalid, [], self::options())->warning();
            self::assertNotNull($warning);
            self::assertStringContainsString('compilation errors', $warning);

            self::assertNull($connection->execute('select 1 from dual', [], self::options())->warning(), 'a later query');
            self::assertNull($connection->execute($valid, [], self::options())->warning(), 'a valid unit');
        } finally {
            $connection->execute("drop procedure $procedure", [], self::options());
        }
    }

    /**
     * On 26ai, ORA-00001 adds an ORA-03301 line with the row's values unless
     * the Session turns `error_message_details` off, which the Backend does
     * when it creates the Session.
     */
    #[Test]
    public function a_unique_violation_on_a_fresh_connection_carries_no_ora_03301_line_and_no_bound_value(): void
    {
        $connection = $this->connect(...self::ownPool());
        $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)';
        $connection->execute($insert, ['id' => 39001, 'code' => 'zq39secret'], self::options(autocommit: false));

        try {
            $connection->execute($insert, ['id' => 39002, 'code' => 'zq39secret'], self::options(autocommit: false));
            self::fail('The duplicate was inserted.');
        } catch (UniqueViolationException $uniqueViolationException) {
            self::assertStringStartsWith('ORA-00001', $uniqueViolationException->getMessage());
            self::assertStringNotContainsString('ORA-03301', $uniqueViolationException->getMessage());
            self::assertStringNotContainsString('zq39secret', $uniqueViolationException->getMessage());
        } finally {
            $connection->rollback();
        }
    }

    #[Test]
    public function the_same_compilation_error_twice_in_a_row_gives_a_warning_each_time(): void
    {
        $connection = $this->connect();
        $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));
        $invalid = "create or replace procedure $procedure is begin no_such_thing; end;";

        try {
            $first = $connection->execute($invalid, [], self::options())->warning();
            $again = $connection->execute($invalid, [], self::options())->warning();
        } finally {
            $connection->execute("drop procedure $procedure", [], self::options());
        }

        self::assertNotNull($first);
        self::assertNotNull($again);
        self::assertStringContainsString('compilation errors', $again);
    }

    #[Test]
    public function a_warning_never_leaks_into_a_later_execution_or_the_next_connection_on_the_pooled_session(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));
        $invalid = "create or replace procedure $procedure is begin no_such_thing; end;";
        $first = $this->connect(...$fields);
        $session = self::session($first);

        try {
            self::assertNotNull($first->execute($invalid, [], self::options())->warning());
            self::assertNull($first->execute('begin null; end;', [], self::options())->warning(), 'a later block');
            self::assertNull($first->execute('select 1 from dual', [], self::options())->warning(), 'a later query');
            // The Session's last warning again, as the Connection is released.
            self::assertNotNull($first->execute($invalid, [], self::options())->warning());
            $first->close();

            $second = $this->connect(...$fields);
            self::assertSame($session, self::session($second));
            self::assertNull($second->execute('select 1 from dual', [], self::options())->warning(), 'a query');
            self::assertNull($second->execute('begin null; end;', [], self::options())->warning(), 'a block');
            self::assertNull(
                $second->execute("create or replace procedure $procedure is begin null; end;", [], self::options())->warning(),
                'a valid unit',
            );
        } finally {
            $this->connect()->execute("drop procedure $procedure", [], self::options());
        }
    }

    #[Test]
    public function the_pool_at_its_ceiling_gives_pool_exhausted_exception(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.1];
        $this->connect(...$fields);

        try {
            $this->connect(...$fields);
            self::fail('A second Session came out of a Pool of one.');
        } catch (PoolExhaustedException $poolExhaustedException) {
            self::assertSame(0, $poolExhaustedException->getCode());
            self::assertStringContainsString('acquireTimeout', $poolExhaustedException->getMessage());
            self::assertNull($poolExhaustedException->sql());
        }
    }

    #[Test]
    public function a_call_timeout_on_a_cpu_bound_query_throws_and_the_connection_survives(): void
    {
        $connection = $this->connect(...self::ownPool());
        $sql = 'select count(*) from fx_bench_rows a, fx_bench_rows b';
        $started = microtime(true);

        try {
            $connection->execute($sql, [], self::options(timeout: 0.5))->cursor()?->fetchBatch();
            self::fail('The query finished within its Call timeout.');
        } catch (TimeoutException $timeoutException) {
            self::assertSame(0, $timeoutException->getCode());
            self::assertSame('Call timeout of 500 ms exceeded.', $timeoutException->getMessage());
            self::assertSame($sql, $timeoutException->sql());
        }
        self::assertLessThan(5, microtime(true) - $started, 'The break stopped the query.');

        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    /**
     * The server answers no break during a sleep, so the Session is lost.
     */
    #[Test]
    public function a_call_timeout_on_a_sleep_makes_the_next_call_throw_connection_lost(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$fields);
        $session = self::session($connection);

        try {
            $connection->execute('begin fx_pool.sleep(3); end;', [], self::options(timeout: 0.5));
            self::fail('The sleep finished within its Call timeout.');
        } catch (TimeoutException $timeoutException) {
            self::assertStringStartsWith('Call timeout of 500 ms exceeded', $timeoutException->getMessage());
        }
        try {
            $connection->execute('select 1 from dual', [], self::options());
            self::fail('A Broken Connection ran a statement.');
        } catch (ConnectionLostException $connectionLostException) {
            self::assertSame('Connection is broken: ' . $timeoutException->getMessage(), $connectionLostException->getMessage());
        }
        $connection->close();

        self::assertNotSame($session, self::session($this->connect(...$fields)), 'The lost Session came back.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function releases(): iterable
    {
        yield 'close()' => [false];
        yield 'the destructor' => [true];
    }

    /**
     * The Release's rollback fails on the dead Session, which is discarded
     * silently.
     */
    #[Test]
    #[DataProvider('releases')]
    public function a_session_dead_after_the_last_call_is_released_without_a_throw_and_not_returned(bool $destroy): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config(...$fields));
        $session = self::session($connection);
        $connection->execute("update fx_items set name = 'uncommitted' where id = 43", [], self::options(autocommit: false));
        self::kill($connection);

        if ($destroy) {
            unset($connection);
        } else {
            $connection->close();
        }

        self::assertNotSame($session, self::session($this->connect(...$fields)), 'The dead Session came back.');
    }

    /**
     * A panic inside the Extension, injected through the debug-build hook
     * (SQL starting with the comment `oracledb: panic`).
     */
    #[Test]
    public function an_injected_panic_gives_connection_lost_and_makes_the_connection_broken(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $session = self::session($connection);

        try {
            $connection->execute('/* oracledb: panic */ select 1 from dual', [], self::options());
            self::markTestSkipped('The panic hook exists in debug builds of the Extension only.');
        } catch (ConnectionLostException $connectionLostException) {
            self::assertSame(0, $connectionLostException->getCode());
            self::assertStringStartsWith('internal error in oracledb: ', $connectionLostException->getMessage());
            $panic = $connectionLostException->getMessage();
        }
        try {
            $connection->execute('select 1 from dual', [], self::options());
            self::fail('A Broken Connection ran a statement.');
        } catch (ConnectionLostException $connectionLostException) {
            self::assertSame('Connection is broken: ' . $panic, $connectionLostException->getMessage());
        }
        $connection->close();

        for ($i = 0; $i < 3; ++$i) {
            $next = $this->connect(...$fields);
            self::assertNotSame($session, self::session($next), 'The Broken Session came back.');
            $next->close();
        }
    }

    /**
     * The expected offsets are the ones oci8 reports for the same statements.
     */
    #[Test]
    public function a_syntax_error_gives_query_exception_with_the_server_offset(): void
    {
        $connection = $this->connect();

        foreach (['select 1 fro dual' => [923, 13], 'select nosuchcolumn from dual' => [904, 7]] as $sql => [$code, $offset]) {
            try {
                $connection->execute($sql, [], self::options());
                self::fail("$sql ran.");
            } catch (QueryException $e) {
                self::assertSame(QueryException::class, $e::class);
                self::assertSame($code, $e->getCode());
                self::assertSame($offset, $e->offset());
                self::assertSame($sql, $e->sql());
            }
        }
    }
}
