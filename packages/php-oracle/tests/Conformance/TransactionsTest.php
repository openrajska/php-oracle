<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TransactionObserver;

/**
 * Transactions at the Driver contract: the `autocommit` option commits with
 * the execute (C21). Core's flag (begin(), transaction()) is tested at the
 * public API (Api\TransactionsTest).
 */
final class TransactionsTest extends ConformanceTestCase
{
    private static TransactionObserver $observer;

    public static function setUpBeforeClass(): void
    {
        self::$observer = TransactionObserver::open();
    }

    public static function tearDownAfterClass(): void
    {
        self::$observer->close();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::$observer->clear();
    }

    /**
     * Statements that write fx_tx_rows row 1: a DML, the blocks Core
     * generates for procedure() and function(), and a hand-written block.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function writes(): iterable
    {
        yield 'DML' => ['insert into fx_tx_rows (id) values (:id)', ['id' => 1]];
        yield 'procedure block' => ['BEGIN fx_tx.add_row(p_id => :b1); END;', ['b1' => 1]];
        yield 'function block' => ['BEGIN :b0 := fx_tx.add_row_count(p_id => :b1); END;', ['b0' => Number::out(), 'b1' => 1]];
        yield 'writing block' => ['begin insert into fx_tx_rows (id) values (:id); end;', ['id' => 1]];
    }

    /**
     * @param array<string, mixed> $binds
     */
    #[Test]
    #[DataProvider('writes')]
    public function a_write_under_autocommit_is_visible_to_another_session_at_once(string $sql, array $binds): void
    {
        $this->connect()->execute($sql, $binds, self::options());

        self::assertSame([1], self::$observer->committed());
    }

    #[Test]
    public function a_failed_statement_under_autocommit_leaves_nothing_applied_or_locked(): void
    {
        $connection = $this->connect();
        $connection->execute('insert into fx_tx_rows (id) values (1)', [], self::options());

        try {
            $connection->execute(
                "begin update fx_tx_rows set note = 'failed' where id = 1; insert into fx_tx_rows (id) values (2); raise_application_error(-20043, 'fail'); end;",
                [],
                self::options(),
            );
            self::fail('The block did not fail.');
        } catch (QueryException $e) {
            self::assertSame(20043, $e->getCode());
        }

        self::assertSame([['ID' => '1', 'NOTE' => null]], self::$observer->select('select id, note from fx_tx_rows'));
        self::assertFalse(self::$observer->locked(1));
    }

    #[Test]
    public function without_autocommit_a_write_is_invisible_until_commit_and_gone_after_rollback(): void
    {
        $connection = $this->connect();

        $connection->execute('insert into fx_tx_rows (id) values (1)', [], self::options(autocommit: false));
        self::assertSame([], self::$observer->committed());
        $connection->commit();
        self::assertSame([1], self::$observer->committed());

        $connection->execute('insert into fx_tx_rows (id) values (2)', [], self::options(autocommit: false));
        $connection->rollback();
        self::assertSame([1], self::$observer->committed());
        self::assertSame(1, self::value($connection, 'select count(*) from fx_tx_rows'));
    }

    /**
     * A query under Autocommit commits too, so `SELECT … FOR UPDATE` loses
     * its locks at once.
     */
    #[Test]
    public function a_query_under_autocommit_keeps_no_lock(): void
    {
        $connection = $this->connect();
        $connection->execute('insert into fx_tx_rows (id) values (1)', [], self::options());

        self::rows($connection, 'select id from fx_tx_rows where id = 1 for update');

        self::assertFalse(self::$observer->locked(1));
    }

    /**
     * The commit closes the FOR UPDATE cursor on the server, so fetching past
     * the first batch gives ORA-01002.
     */
    #[Test]
    public function a_for_update_query_under_autocommit_fails_its_next_fetch_with_ora_01002(): void
    {
        $connection = $this->connect();
        $cursor = $connection->execute(
            'select id from fx_items where id <= 5 for update',
            [],
            self::options(fetchSize: 2),
        )->cursor();
        self::assertNotNull($cursor);
        self::assertCount(2, $cursor->fetchBatch());

        try {
            $cursor->fetchBatch();
            self::fail('The second fetch did not fail.');
        } catch (QueryException $e) {
            self::assertSame(1002, $e->getCode());
        } finally {
            $cursor->close();
        }
    }

    /** C21 adds no Round trip. */
    #[Test]
    public function autocommit_adds_no_round_trip_and_commit_and_rollback_take_one_each(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $insert = 'insert into fx_tx_rows (id) values (:id)';
        // Parsed once, so every count below is of a cached statement.
        $connection->execute($insert, ['id' => 1], self::options());
        $counter = RoundTripCounter::open();

        try {
            self::assertSame(1, $counter->during($sid, static fn(): DriverExecution => $connection->execute($insert, ['id' => 2], self::options())));
            self::assertSame(1, $counter->during($sid, static fn(): DriverExecution => $connection->execute($insert, ['id' => 3], self::options(autocommit: false))));
            self::assertSame(1, $counter->during($sid, $connection->commit(...)));
            $connection->execute($insert, ['id' => 4], self::options(autocommit: false));
            self::assertSame(1, $counter->during($sid, $connection->rollback(...)));
        } finally {
            $counter->close();
        }
        self::assertSame([1, 2, 3], self::$observer->committed());
    }

    /**
     * A query of up to fetchSize rows still takes one Round trip under
     * Autocommit, also exactly fetchSize rows from the Statement cache.
     */
    #[Test]
    public function a_query_of_up_to_fetch_size_rows_under_autocommit_takes_one_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $query = 'select id from fx_items where id <= :last';
        $counter = RoundTripCounter::open();

        try {
            foreach ([false, true, true] as $autocommit) {
                foreach ([2, 3] as $last) {
                    $rows = [];
                    self::assertSame(1, $counter->during($sid, static function () use ($connection, $query, $last, $autocommit, &$rows): void {
                        $rows = self::rows($connection, $query, ['last' => $last], self::options(fetchSize: 3, autocommit: $autocommit));
                    }), "$last rows, autocommit: " . var_export($autocommit, true));
                    self::assertCount($last, $rows);
                }
            }
        } finally {
            $counter->close();
        }
    }
}
