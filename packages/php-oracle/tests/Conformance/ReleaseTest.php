<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Driver\DriverArrayDml;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TransactionObserver;

/**
 * What a Release costs and what it still undoes (#78). The Release rolls back
 * only when the server reported a transaction open at the end of the
 * Session's last call (the TTC end-of-call status), or when a call of the
 * Connection failed. Nothing here goes through Core's transaction flag: at
 * this seam there is none, only `autocommit`.
 */
final class ReleaseTest extends ConformanceTestCase
{
    private static TransactionObserver $observer;

    private static RoundTripCounter $counter;

    /**
     * One Pool of one Session for the whole class: every test after the
     * first gets that Session on a later loan (the crate ends a Session's
     * first loan with a request-boundary rollback of its own), and the class
     * holds one Session, not one per test, for the rest of the run.
     *
     * @var array<string, mixed>
     */
    private static array $pool;

    public static function setUpBeforeClass(): void
    {
        self::$pool = self::ownPool() + ['maxConnections' => 1];
        self::$observer = TransactionObserver::open();
        self::$counter = RoundTripCounter::open();
    }

    public static function tearDownAfterClass(): void
    {
        self::$counter->close();
        self::$observer->close();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::$observer->clear();
        self::$observer->execute('insert into fx_tx_rows (id) values (1)');
    }

    /**
     * What the last calls before a Release left: never a transaction.
     *
     * @return iterable<string, array{Closure(DriverConnection): mixed}>
     */
    public static function nothingOpen(): iterable
    {
        yield 'no call' => [static fn(DriverConnection $c): null => null];
        yield 'a query' => [static fn(DriverConnection $c): array => self::rows($c, 'select id from fx_tx_rows')];
        yield 'a DML under Autocommit' => [static fn(DriverConnection $c): DriverExecution => $c->execute('insert into fx_tx_rows (id) values (2)', [], self::options())];
        yield 'a block under Autocommit' => [static fn(DriverConnection $c): DriverExecution => $c->execute("begin update fx_tx_rows set note = 'block' where id = 1; end;", [], self::options())];
        yield 'SELECT … FOR UPDATE under Autocommit' => [static fn(DriverConnection $c): array => self::rows($c, 'select id from fx_tx_rows for update')];
        yield 'commit()' => [static function (DriverConnection $c): void {
            $c->execute('insert into fx_tx_rows (id) values (2)', [], self::options(autocommit: false));
            $c->commit();
        }];
        yield 'rollback()' => [static function (DriverConnection $c): void {
            $c->execute('insert into fx_tx_rows (id) values (2)', [], self::options(autocommit: false));
            $c->rollback();
        }];
    }

    /**
     * With no transaction open, connect() + close() through the Pool makes
     * no Round trip, as `oci_pconnect()` + `oci_close()` makes none.
     *
     * @param Closure(DriverConnection): mixed $work
     */
    #[Test]
    #[DataProvider('nothingOpen')]
    public function a_release_with_no_transaction_open_makes_no_round_trip(Closure $work): void
    {
        $fields = self::$pool;
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $sid = self::sid($connection);
        $work($connection);

        self::assertSame(0, self::$counter->during($sid, $connection->close(...)), 'close()');
        self::assertSame(0, self::$counter->during($sid, function () use ($fields): void {
            $this->connect(...$fields)->close();
        }), 'connect() + close()');
        self::assertSame(0, self::$counter->during($sid, function () use ($fields): void {
            $connection = $this->connect(...$fields);
            unset($connection);
        }), 'connect() + destroying the Connection');
    }

    /**
     * Work the server keeps open after the call, with Autocommit off: each
     * locks fx_tx_rows row 1 or holds the transaction some other way.
     *
     * @return iterable<string, array{Closure(DriverConnection): mixed}>
     */
    public static function leftOpen(): iterable
    {
        $open = self::options(autocommit: false);
        $lock = static fn(DriverConnection $c): DriverExecution => $c->execute("update fx_tx_rows set note = 'open' where id = 1", [], $open);

        yield 'DML in a PL/SQL block' => [static fn(DriverConnection $c): DriverExecution => $c->execute(
            "begin update fx_tx_rows set note = 'open' where id = 1; insert into fx_tx_rows (id) values (2); end;",
            [],
            $open,
        )];
        yield 'SELECT … FOR UPDATE' => [static fn(DriverConnection $c): array => self::rows($c, 'select id from fx_tx_rows where id = 1 for update', [], $open)];
        yield 'LOCK TABLE' => [static fn(DriverConnection $c): DriverExecution => $c->execute('lock table fx_tx_rows in exclusive mode', [], $open)];
        yield 'SET TRANSACTION READ ONLY' => [static fn(DriverConnection $c): DriverExecution => $c->execute('set transaction read only', [], $open)];
        yield 'runMany()' => [static fn(DriverConnection $c): DriverArrayDml => $c->executeMany('insert into fx_tx_rows (id) values (:id)', [['id' => 2], ['id' => 3]], $open)];
        yield 'runMany() with a failing row, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
            $lock($c);
            self::fails(static fn(): DriverArrayDml => $c->executeMany('insert into fx_tx_rows (id) values (:id)', [['id' => 2], ['id' => 1]], $open));
        }];
        yield 'a failed execute, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
            $lock($c);
            self::fails(static fn(): DriverExecution => $c->execute('insert into fx_tx_rows (id) values (1)', [], $open));
        }];
        yield 'a query, after a DML' => [static function (DriverConnection $c) use ($lock): void {
            $lock($c);
            self::rows($c, 'select id from fx_tx_rows', [], self::options(autocommit: false));
        }];
        yield 'DBMS_OUTPUT calls, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
            $lock($c);
            $c->execute("begin dbms_output.enable(null); dbms_output.put_line('line'); end;", [], $open);
            $c->execute('begin dbms_output.disable; end;', [], $open);
        }];
        yield 'a DML with a LOB bind' => [static fn(DriverConnection $c): DriverExecution => $c->execute(
            'update fx_tx_rows set note = dbms_lob.substr(:note, 10) where id = 1',
            ['note' => Clob::in(str_repeat('x', 40_000))],
            $open,
        )];
        yield 'a LOB write, after a DML' => [static function (DriverConnection $c) use ($lock, $open): void {
            $lock($c);
            self::rows($c, 'select dbms_lob.getlength(:text) as n from dual', ['text' => Clob::in(str_repeat('x', 40_000))], $open);
        }];
    }

    /**
     * @param Closure(DriverConnection): mixed $work
     */
    #[Test]
    #[DataProvider('leftOpen')]
    public function a_release_rolls_back_whatever_the_server_reports_open(Closure $work): void
    {
        $fields = self::$pool;
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $work($connection);

        $connection->close();

        self::assertSame([['ID' => '1', 'NOTE' => null]], self::$observer->select('select id, note from fx_tx_rows order by id'));
        self::assertFalse(self::$observer->locked(1), 'The Release left row 1 locked.');
        $next = $this->connect(...$fields);
        $next->execute("update fx_tx_rows set note = 'next' where id = 1", [], self::options());
        self::assertSame([['ID' => '1', 'NOTE' => 'next']], self::$observer->select('select id, note from fx_tx_rows order by id'));
    }

    /**
     * @param Closure(): mixed $call
     */
    private static function fails(Closure $call): void
    {
        try {
            $call();
        } catch (OracleException) {
            return;
        }
        self::fail('The call did not fail.');
    }
}
