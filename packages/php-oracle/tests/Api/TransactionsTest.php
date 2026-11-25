<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tests\Support\TransactionObserver;

/**
 * Transactions (catalog #26): Autocommit outside an Explicit transaction,
 * and `begin()` / `commit()` / `rollback()` / `transaction()` for manual
 * control (fixtures/sql/11_transaction.sql).
 */
final class TransactionsTest extends TestCase
{
    private Connection $connection;

    /**
     * One observer and one Round trip counter serve the whole file: a logon
     * per test adds to the listener's load, which refuses logons with
     * ORA-12516 when it runs high.
     */
    private static TransactionObserver $observer;

    private static RoundTripCounter $counter;

    public static function setUpBeforeClass(): void
    {
        self::$observer = TransactionObserver::open();
        self::$counter = RoundTripCounter::open();
    }

    public static function tearDownAfterClass(): void
    {
        self::$counter->close();
        self::$observer->close();
    }

    protected function setUp(): void
    {
        self::$observer->clear();
        $this->connection = self::connect();
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * Transactions 2: after begin(), work is invisible until commit().
     */
    #[Test]
    public function after_begin_work_is_invisible_until_commit(): void
    {
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        self::assertSame([], self::$observer->committed());

        $this->connection->commit();

        self::assertSame([1], self::$observer->committed());
    }

    /**
     * Transactions 2: after rollback(), work is gone, and the Connection is
     * back in Autocommit.
     */
    #[Test]
    public function after_rollback_work_is_gone_and_the_connection_is_back_in_autocommit(): void
    {
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        $this->connection->procedure('fx_tx.add_row', ['p_id' => 2]);
        $this->connection->rollback();
        self::assertSame(0, $this->connection->select('select count(*) from fx_tx_rows')->value());

        $this->connection->run('insert into fx_tx_rows (id) values (3)');

        self::assertSame([3], self::$observer->committed());
    }

    /**
     * Transactions 2: after commit(), the Connection is back in Autocommit.
     */
    #[Test]
    public function after_commit_the_connection_is_back_in_autocommit(): void
    {
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        $this->connection->commit();

        $this->connection->run('insert into fx_tx_rows (id) values (2)');

        self::assertSame([1, 2], self::$observer->committed());
    }

    /**
     * Transactions 1: under Autocommit, run(), procedure(), function() and
     * a writing block are each visible to another Connection at once.
     */
    #[Test]
    public function under_autocommit_every_verb_commits_at_once(): void
    {
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        self::assertSame([1], self::$observer->committed());

        $this->connection->procedure('fx_tx.add_row', ['p_id' => 2]);
        self::assertSame([1, 2], self::$observer->committed());

        self::assertSame(3, $this->connection->function('fx_tx.add_row_count', ['p_id' => 3])->number());
        self::assertSame([1, 2, 3], self::$observer->committed());

        $this->connection->run('begin fx_tx.add_row(4); end;');
        self::assertSame([1, 2, 3, 4], self::$observer->committed());
    }

    /**
     * Transactions 1: select() commits too, so `SELECT … FOR UPDATE` under
     * Autocommit keeps no lock.
     */
    #[Test]
    public function select_for_update_under_autocommit_keeps_no_lock(): void
    {
        $this->connection->run('insert into fx_tx_rows (id) values (1)');

        self::assertSame(1, $this->connection->select('select id from fx_tx_rows where id = 1 for update')->value());

        self::assertFalse(self::$observer->locked(1));
    }

    /**
     * Inside begin(), every verb stays in the transaction, a function()
     * included: its options resolve when its terminal runs.
     */
    #[Test]
    public function inside_begin_every_verb_stays_in_the_transaction(): void
    {
        $this->connection->run('insert into fx_tx_rows (id) values (5)');
        $pending = $this->connection->function('fx_tx.add_row_count', ['p_id' => 3]);
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        $this->connection->procedure('fx_tx.add_row', ['p_id' => 2]);
        self::assertSame(4, $pending->number());
        $this->connection->run('begin fx_tx.add_row(4); end;');
        self::assertSame(5, $this->connection->select('select id from fx_tx_rows where id = 5 for update')->value());

        self::assertSame([5], self::$observer->committed());
        self::assertTrue(self::$observer->locked(5));
        $this->connection->rollback();
        self::assertFalse(self::$observer->locked(5));
        self::assertSame([5], self::$observer->committed());
    }

    /**
     * Transactions 8 / §9: begin() costs no Round trip; commit() and
     * rollback() one each.
     */
    #[Test]
    public function begin_costs_no_round_trip_and_commit_and_rollback_one_each(): void
    {
        $sid = self::sid($this->connection);

        self::assertSame(0, self::$counter->during($sid, $this->connection->begin(...)));
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        self::assertSame(1, self::$counter->during($sid, $this->connection->commit(...)));
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (2)');
        self::assertSame(1, self::$counter->during($sid, $this->connection->rollback(...)));
        self::assertSame([1], self::$observer->committed());
    }

    /**
     * Transactions 3 and Errors 11: misuse of the transaction verbs throws
     * UsageException before any Round trip.
     *
     * @return iterable<string, array{0: \Closure(Connection): void, 1: string, 2?: int}>
     */
    public static function misuses(): iterable
    {
        yield 'commit() outside a transaction' => [
            static fn(Connection $c) => $c->commit(),
            'There is no transaction to commit',
        ];
        yield 'rollback() outside a transaction' => [
            static fn(Connection $c) => $c->rollback(),
            'There is no transaction to roll back',
        ];
        yield 'begin() inside begin()' => [
            static function (Connection $c): void {
                $c->begin();
                $c->begin();
            },
            'Transactions do not nest',
        ];
        yield 'transaction() inside begin()' => [
            static function (Connection $c): void {
                $c->begin();
                $c->transaction(static fn(): null => null);
            },
            'Transactions do not nest',
        ];
        yield 'begin() inside transaction()' => [
            static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->begin()),
            'Transactions do not nest',
            1,
        ];
        yield 'transaction() inside transaction()' => [
            static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->transaction(static fn(): null => null)),
            'Transactions do not nest',
            1,
        ];
        yield 'commit() inside transaction()' => [
            static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->commit()),
            'commit() inside transaction()',
            1,
        ];
        yield 'rollback() inside transaction()' => [
            static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->rollback()),
            'rollback() inside transaction()',
            1,
        ];
    }

    /**
     * @param \Closure(Connection): void $misuse
     * @param int $trips the Round trips of the surrounding transaction()
     */
    #[Test]
    #[DataProvider('misuses')]
    public function misusing_a_transaction_verb_throws_usage_exception_before_any_round_trip(\Closure $misuse, string $message, int $trips = 0): void
    {
        $sid = self::sid($this->connection);

        $thrown = null;
        $made = self::$counter->during($sid, function () use ($misuse, &$thrown): void {
            try {
                $misuse($this->connection);
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(UsageException::class, $thrown);
        self::assertStringContainsString($message, $thrown->getMessage());
        // Inside transaction(), the misuse reaches the caller through the
        // closure's rollback: that one Round trip is the transaction's.
        self::assertSame($trips, $made);
    }

    /**
     * A misuse leaves the transaction state as it was: a nested begin()
     * keeps the outer transaction open, and commit() outside one keeps
     * Autocommit.
     */
    #[Test]
    public function a_misuse_leaves_the_transaction_state_as_it_was(): void
    {
        try {
            $this->connection->commit();
        } catch (UsageException) {
        }
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        self::assertSame([1], self::$observer->committed());

        $this->connection->begin();
        try {
            $this->connection->begin();
        } catch (UsageException) {
        }
        $this->connection->run('insert into fx_tx_rows (id) values (2)');
        self::assertSame([1], self::$observer->committed());
        $this->connection->commit();
        self::assertSame([1, 2], self::$observer->committed());
    }

    /**
     * Transactions 4: transaction() passes the Connection, returns the
     * closure's value and commits, then the Connection is back in
     * Autocommit.
     */
    #[Test]
    public function transaction_returns_the_closures_value_and_commits(): void
    {
        $result = $this->connection->transaction(function (Connection $connection): string {
            self::assertSame($this->connection, $connection);
            $connection->run('insert into fx_tx_rows (id) values (1)');
            $connection->procedure('fx_tx.add_row', ['p_id' => 2]);
            self::assertSame([], self::$observer->committed());

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame([1, 2], self::$observer->committed());
        $this->connection->run('insert into fx_tx_rows (id) values (3)');
        self::assertSame([1, 2, 3], self::$observer->committed());
    }

    /**
     * Transactions 4: when the closure throws, transaction() rolls back and
     * rethrows the same instance; the Connection is back in Autocommit.
     */
    #[Test]
    public function transaction_rolls_back_and_rethrows_the_same_instance(): void
    {
        $thrown = new \DomainException('the closure failed');
        $caught = null;

        try {
            $this->connection->transaction(static function (Connection $connection) use ($thrown): never {
                $connection->run('insert into fx_tx_rows (id) values (1)');

                throw $thrown;
            });
        } catch (\DomainException $e) {
            $caught = $e;
        }

        self::assertSame($thrown, $caught);
        self::assertNull($thrown->getPrevious());

        self::assertSame(0, $this->connection->select('select count(*) from fx_tx_rows')->value());
        $this->connection->run('insert into fx_tx_rows (id) values (2)');
        self::assertSame([2], self::$observer->committed());
    }

    /**
     * Transactions 4: an Error rolls back too.
     */
    #[Test]
    public function transaction_rolls_back_on_an_error_too(): void
    {
        $caught = 'nothing';

        try {
            $this->connection->transaction(static function (Connection $connection): never {
                $connection->run('insert into fx_tx_rows (id) values (1)');

                throw new \TypeError('not an exception');
            });
        } catch (\TypeError $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('not an exception', $caught);

        self::assertSame(0, $this->connection->select('select count(*) from fx_tx_rows')->value());
    }

    /**
     * Transactions 4: on a Broken Connection the rollback fails, and its
     * failure becomes the rethrown exception's `previous`.
     */
    #[Test]
    public function a_failed_rollback_becomes_previous_of_the_rethrown_exception(): void
    {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(standalone: true));
        $thrown = new \DomainException('the closure failed');
        $caught = null;

        try {
            $connection->transaction(function (Connection $connection) use ($thrown): void {
                $connection->run('insert into fx_tx_rows (id) values (1)');
                $this->kill($connection);
                try {
                    $connection->run('select 1 from dual');
                } catch (ConnectionLostException) {
                }

                throw $thrown;
            });
        } catch (\DomainException $e) {
            $caught = $e;
        } finally {
            $connection->close();
        }

        self::assertSame($thrown, $caught);
        self::assertInstanceOf(ConnectionLostException::class, $thrown->getPrevious());

        self::assertSame([], self::$observer->committed());
    }

    /**
     * A closure that closes the Connection and throws: the Release rolled
     * back already, so the exception comes back as it was.
     */
    #[Test]
    public function a_closure_that_closes_the_connection_rethrows_with_nothing_chained(): void
    {
        $thrown = new \DomainException('the closure failed');
        $caught = null;

        try {
            $this->connection->transaction(static function (Connection $connection) use ($thrown): never {
                $connection->run('insert into fx_tx_rows (id) values (1)');
                $connection->close();

                throw $thrown;
            });
        } catch (\DomainException $e) {
            $caught = $e;
        }

        self::assertSame($thrown, $caught);
        self::assertNull($thrown->getPrevious());
        self::assertSame([], self::$observer->committed());
    }

    /**
     * Transactions 5: a deferred constraint fails the commit (ORA-02091),
     * nothing is applied, and the Connection is back in Autocommit.
     */
    #[Test]
    public function a_failed_commit_throws_and_the_connection_is_back_in_autocommit(): void
    {
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        $this->connection->run('insert into fx_tx_rows (id, qty) values (2, -1)');

        try {
            $this->connection->commit();
            self::fail('The commit did not fail.');
        } catch (QueryException $e) {
            self::assertSame(2091, $e->getCode());
        }

        self::assertSame([], self::$observer->committed());
        $this->connection->run('insert into fx_tx_rows (id) values (3)');
        self::assertSame([3], self::$observer->committed());
    }

    /**
     * Transactions 5: the same through transaction().
     */
    #[Test]
    public function a_failed_commit_in_transaction_propagates_and_the_connection_is_back_in_autocommit(): void
    {
        try {
            $this->connection->transaction(static function (Connection $connection): void {
                $connection->run('insert into fx_tx_rows (id) values (1)');
                $connection->run('insert into fx_tx_rows (id, qty) values (2, -1)');
            });
            self::fail('The commit did not fail.');
        } catch (QueryException $e) {
            self::assertSame(2091, $e->getCode());
        }

        self::assertSame([], self::$observer->committed());
        $this->connection->run('insert into fx_tx_rows (id) values (3)');
        self::assertSame([3], self::$observer->committed());
    }

    /**
     * Transactions 6: `SAVEPOINT` / `ROLLBACK TO` through run() undo only
     * the later work.
     */
    #[Test]
    public function rollback_to_a_savepoint_through_run_undoes_only_the_later_work(): void
    {
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        $this->connection->run('savepoint before_two');
        $this->connection->run('insert into fx_tx_rows (id) values (2)');
        $this->connection->run('rollback to savepoint before_two');
        $this->connection->run('insert into fx_tx_rows (id) values (3)');
        self::assertSame([], self::$observer->committed());

        $this->connection->commit();

        self::assertSame([1, 3], self::$observer->committed());
    }

    /**
     * Transactions 6: destroying the Connection inside begin() rolls back,
     * and the locks go with it; so does close().
     */
    #[Test]
    public function destroying_or_closing_the_connection_inside_begin_rolls_back(): void
    {
        $this->connection->run("insert into fx_tx_rows (id, note) values (1, 'committed')");

        $destroyed = self::connect();
        $destroyed->begin();
        $destroyed->run("update fx_tx_rows set note = 'destroyed' where id = 1");
        $destroyed->run('insert into fx_tx_rows (id) values (2)');
        self::assertTrue(self::$observer->locked(1));
        unset($destroyed);

        self::assertFalse(self::$observer->locked(1));
        self::assertSame([['ID' => '1', 'NOTE' => 'committed']], self::$observer->select('select id, note from fx_tx_rows'));

        $closed = self::connect();
        $closed->begin();
        $closed->run("update fx_tx_rows set note = 'closed' where id = 1");
        $closed->close();

        self::assertFalse(self::$observer->locked(1));
        self::assertSame([['ID' => '1', 'NOTE' => 'committed']], self::$observer->select('select id, note from fx_tx_rows'));
    }

    /**
     * After close(), the transaction verbs fail like every other call.
     */
    #[Test]
    public function the_transaction_verbs_fail_after_close(): void
    {
        $this->connection->close();

        foreach (['begin', 'commit', 'rollback'] as $verb) {
            try {
                $this->connection->$verb();
                self::fail("$verb() after close() did not fail.");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage(), $verb);
            }
        }
        $this->expectException(UsageException::class);
        $this->connection->transaction(static fn(): never => self::fail('The closure ran.'));
    }

    /**
     * Kills the Session behind a Connection from another Session, at once.
     */
    private function kill(Connection $connection): void
    {
        $sid = self::sid($connection);
        $serial = self::$observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $sid])[0]['SERIAL'];
        self::$observer->execute("alter system kill session '$sid,$serial' immediate");
    }

    private static function sid(Connection $connection): int
    {
        $sid = $connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);

        return (int) $sid;
    }

    private static function connect(): Connection
    {
        return Oracle::connect(TestDatabase::fromEnvironment()->config());
    }
}
