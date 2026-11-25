<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Blob;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\Collection;
use Rajska\Oracle\Bind\Cursor;
use Rajska\Oracle\Bind\Indexed;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Struct;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Driver\DriverArrayDml;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Exceptions\ArrayDmlException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

/**
 * Array DML (catalog #26, Transactions and Array DML 9–14) at the Driver
 * contract: `executeMany()` sends every row in one execute, applies every
 * row or none, and reports every failing row
 * (fixtures/sql/06_batch.sql, 11_transaction.sql).
 *
 * One observer Session and one Round trip counter serve the whole file: the
 * shared listener refuses logons under load (ORA-12516).
 */
final class ArrayDmlTest extends ConformanceTestCase
{
    private const string INSERT = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    private static IndependentSession $observer;

    private static RoundTripCounter $counter;

    public static function setUpBeforeClass(): void
    {
        self::$observer = IndependentSession::open();
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
        self::clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::clear();
    }

    /**
     * Transactions 9: 1000 valid rows take one Round trip, and every one is
     * applied and committed.
     */
    #[Test]
    public function a_thousand_valid_rows_take_one_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $rows = self::valid(1000);

        $arrayDml = null;
        $trips = self::$counter->during($sid, static function () use ($connection, $rows, &$arrayDml): void {
            $arrayDml = $connection->executeMany(self::INSERT, $rows, self::options());
        });

        self::assertSame(1, $trips);
        self::assertInstanceOf(DriverArrayDml::class, $arrayDml);
        self::assertSame(1000, $arrayDml->rowCount());
        self::assertSame([], $arrayDml->outValues());
        self::assertSame(1000, self::committed());
    }

    /**
     * Transactions 9: a row may write the first row's keys in another order
     * and case; each value still binds to its own placeholder.
     */
    #[Test]
    public function rows_bind_by_name_whatever_the_order_and_case_of_their_keys(): void
    {
        $connection = $this->connect();

        $arrayDml = self::many($connection, self::INSERT, [
            ['id' => 1, 'code' => 'one', 'qty' => 10],
            ['QTY' => 20, 'Id' => 2, 'code' => 'two'],
            ['id' => 3, 'code' => 'three', 'qty' => 30],
        ]);

        self::assertSame(3, $arrayDml->rowCount());
        self::assertSame(
            [['ID' => '1', 'CODE' => 'one', 'QTY' => '10'], ['ID' => '2', 'CODE' => 'two', 'QTY' => '20'], ['ID' => '3', 'CODE' => 'three', 'QTY' => '30']],
            self::$observer->select('select id, code, qty from fx_batch_rows order by id'),
        );
    }

    /**
     * Transactions 10 and Errors 12: rows 3, 5 and 7 fail (ORA-00001,
     * 01400, 02290); one ArrayDmlException lists exactly those, with row 3's
     * code, and nothing is applied: under Autocommit the valid rows are not
     * committed, nor left in the Session's transaction.
     */
    #[Test]
    public function failing_rows_under_autocommit_give_one_array_dml_exception_and_apply_nothing(): void
    {
        $connection = $this->connect();

        $e = self::failing(static fn(): DriverArrayDml => self::many($connection, self::INSERT, self::withBadRows()));

        self::assertSame([[3, 1], [5, 1400], [7, 2290]], self::failures($e));
        self::assertSame(1, $e->getCode());
        self::assertStringStartsWith('3 of 10 rows failed; first: row 3: ORA-00001: ', $e->getMessage());
        self::assertSame(self::INSERT, $e->sql());
        self::assertSame(0, self::committed());
        self::assertSame(0, self::value($connection, 'select count(*) from fx_batch_rows'));
    }

    /**
     * Transactions 10: inside an Explicit transaction, the failing call
     * undoes its own rows only; earlier work stays, uncommitted.
     */
    #[Test]
    public function failing_rows_inside_a_transaction_keep_the_earlier_work_uncommitted(): void
    {
        $connection = $this->connect();
        $connection->execute('insert into fx_tx_rows (id) values (1)', [], self::options(autocommit: false));

        $e = self::failing(static fn(): DriverArrayDml => self::many($connection, self::INSERT, self::withBadRows(), autocommit: false));

        self::assertSame([[3, 1], [5, 1400], [7, 2290]], self::failures($e));
        self::assertSame(0, self::value($connection, 'select count(*) from fx_batch_rows'));
        self::assertSame(1, self::value($connection, 'select count(*) from fx_tx_rows'));
        self::assertSame('0', self::$observer->select('select count(*) as n from fx_tx_rows')[0]['N']);
        $connection->commit();
        self::assertSame('1', self::$observer->select('select count(*) as n from fx_tx_rows')[0]['N']);
        self::assertSame(0, self::committed());
    }

    /**
     * Errors 12: it is still a ArrayDmlException when every failure is
     * ORA-00001, never a UniqueViolationException.
     */
    #[Test]
    public function only_duplicates_still_give_an_array_dml_exception(): void
    {
        $connection = $this->connect();
        $rows = self::valid(4);
        $rows[1]['id'] = 1;
        $rows[3]['code'] = 'c1';

        $e = self::failing(static fn(): DriverArrayDml => self::many($connection, self::INSERT, $rows));

        self::assertSame(ArrayDmlException::class, $e::class);
        self::assertSame([[1, 1], [3, 1]], self::failures($e));
        self::assertSame(0, self::committed());
    }

    /**
     * Transactions 11: `RETURNING` gives each row the list of what its
     * execution returned, in row order.
     */
    #[Test]
    public function returning_gives_one_list_per_row_in_order(): void
    {
        $connection = $this->connect();
        self::many($connection, self::INSERT, self::valid(4));

        $inserted = self::many($connection, 'insert into fx_bind_rows (id) values (:id) returning id * 10 into :tenfold', [
            ['id' => 1, 'tenfold' => Number::out()],
            ['id' => 2, 'tenfold' => Number::out()],
            ['id' => 3, 'tenfold' => Number::out()],
        ]);
        $updated = self::many($connection, 'update fx_batch_rows set qty = qty + 1 where id >= :low returning id into :ids', [
            ['low' => 3, 'ids' => Number::out()],
            ['low' => 9, 'ids' => Number::out()],
            ['low' => 1, 'ids' => Number::out()],
        ]);

        self::assertSame(3, $inserted->rowCount());
        self::assertSame(['tenfold' => [[10], [20], [30]]], $inserted->outValues());
        self::assertSame(6, $updated->rowCount());
        $ids = $updated->outValues()['ids'];
        self::assertCount(3, $ids);
        self::assertSame([[3, 4], [], [1, 2, 3, 4]], array_map(static function (array $ids): array {
            sort($ids);

            return $ids;
        }, $ids));
    }

    /**
     * Transactions 11 (#73): `RETURNING` a LOB, an Object or a Collection
     * gives each row the list of what its execution returned, NULL as
     * `null`, like any other type.
     */
    #[Test]
    public function returning_lobs_objects_and_collections_gives_one_list_per_row(): void
    {
        $connection = $this->connect();

        $lobs = self::many($connection, 'update fx_lobs set label = label where id = :id returning text_value, binary_value into :t, :b', [
            ['id' => 1, 't' => Clob::out(), 'b' => Blob::out()],
            ['id' => 3, 't' => Clob::out(), 'b' => Blob::out()],
            ['id' => 0, 't' => Clob::out(), 'b' => Blob::out()],
            ['id' => 2, 't' => Clob::out(), 'b' => Blob::out()],
        ], autocommit: false);
        self::assertSame(3, $lobs->rowCount());
        self::assertSame([["Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}"], [null], [], ['']], self::contents($lobs->outValues()['t']));
        self::assertSame([["\xDE\xAD\xBE\xEF"], [null], [], ['']], self::contents($lobs->outValues()['b']));
        $connection->rollback();

        $shapes = self::many($connection, 'update fx_shapes set id = id where id = :id returning center, tags into :center, :tags', [
            ['id' => 1, 'center' => Struct::out('fx_point'), 'tags' => Collection::out('fx_string_varray')],
            ['id' => 2, 'center' => Struct::out('fx_point'), 'tags' => Collection::out('fx_string_varray')],
            ['id' => 0, 'center' => Struct::out('fx_point'), 'tags' => Collection::out('fx_string_varray')],
            ['id' => 3, 'center' => Struct::out('fx_point'), 'tags' => Collection::out('fx_string_varray')],
        ], autocommit: false);
        self::assertSame(3, $shapes->rowCount());
        self::assertSame([
            'center' => [[['X' => '1.5', 'Y' => -2, 'LABEL' => 'center']], [null], [], [['X' => null, 'Y' => null, 'LABEL' => null]]],
            'tags' => [[['red', "caf\u{e9}"]], [null], [], [[]]],
        ], $shapes->outValues());
        $connection->rollback();
    }

    /**
     * Transactions 11: a PL/SQL OUT bind gives each row its value, in row
     * order; a block counts no rows.
     */
    #[Test]
    public function a_plsql_out_bind_gives_one_value_per_row_in_order(): void
    {
        $connection = $this->connect();

        $arrayDml = self::many($connection, 'begin :doubled := :n * 2; :name := :prefix || :n; end;', [
            ['n' => 1, 'doubled' => Number::out(), 'prefix' => 'a', 'name' => Varchar::out()],
            ['n' => 2, 'doubled' => Number::out(), 'prefix' => 'b', 'name' => Varchar::out()],
            ['n' => 3, 'doubled' => Number::out(), 'prefix' => 'c', 'name' => Varchar::out()],
        ]);

        self::assertSame(0, $arrayDml->rowCount());
        self::assertSame(['doubled' => [[2], [4], [6]], 'name' => [['a1'], ['b2'], ['c3']]], $arrayDml->outValues());
    }

    /**
     * Transactions 12: no rows make no Round trip and an empty result.
     */
    #[Test]
    public function no_rows_make_no_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        $arrayDml = null;
        $trips = self::$counter->during($sid, static function () use ($connection, &$arrayDml): void {
            $arrayDml = self::many($connection, self::INSERT, []);
        });

        self::assertSame(0, $trips);
        self::assertInstanceOf(DriverArrayDml::class, $arrayDml);
        self::assertSame(0, $arrayDml->rowCount());
        self::assertSame([], $arrayDml->outValues());
    }

    /**
     * Transactions 13: what runMany() refuses before any Round trip.
     *
     * @return iterable<string, array{string, list<array<string, mixed>>, string}>
     */
    public static function refusals(): iterable
    {
        $row = static fn(mixed $id, mixed $code = 'a'): array => ['id' => $id, 'code' => $code, 'qty' => 1];
        yield 'different keys' => [self::INSERT, [$row(1), ['id' => 2, 'code' => 'b']], "Row 1 has no bind 'qty', which row 0 has; every row needs the same keys."];
        yield 'an extra key' => [self::INSERT, [$row(1), [...$row(2), 'note' => 'x']], "Row 1 has a bind 'note', which row 0 has not; every row needs the same keys."];
        yield 'an int and a string' => [self::INSERT, [$row(1), $row('2')], "Row 1, bind 'id': a string, but row 0 made the column a NUMBER (int or float); a column takes one type (an int and a float mix as NUMBER)."];
        yield 'a string and a bool' => [self::INSERT, [$row(1, null), $row(2, 'b'), $row(3, true)], "Row 2, bind 'code': a bool, but row 1 made the column a string; a column takes one type (an int and a float mix as NUMBER)."];
        yield 'a typed class changing' => [self::INSERT, [$row(Number::in(1)), $row(Varchar::in('2'))], "Row 1, bind 'id': Varchar::in(), but row 0 made the column Number::in(); a column takes one type (an int and a float mix as NUMBER)."];
        yield 'a typed direction changing' => ['begin :x := :x; end;', [['x' => Number::inOut(1)], ['x' => Number::in(2)]], "Row 1, bind 'x': Number::in(), but row 0 made the column Number::inOut(); a column takes one type (an int and a float mix as NUMBER)."];
        yield 'a plain value in a typed column' => [self::INSERT, [$row(Number::in(1)), $row(2)], "Row 1, bind 'id': an int, but row 0 made the column Number::in(); a column takes one type (an int and a float mix as NUMBER)."];
        yield 'a plain null in a typed column' => [self::INSERT, [$row(null), $row(Number::in(2))], "Row 0, bind 'id': a plain null in a column of Number::in(); bind the NULL with that typed bind too."];
        yield 'Cursor::out()' => ['begin open :c for select 1 from dual; end;', [['c' => Cursor::out()]], "Row 0, bind 'c': runMany() cannot bring back a REF CURSOR; run the block with run() for each row."];
        yield 'a query' => ['select :id from dual', [['id' => 1]], 'runMany() runs DML or PL/SQL, and this statement is a query; read rows with select().'];
        yield 'a bind error' => [self::INSERT, [$row(1), $row([2])], "Row 1: Bind 'id': an array does not bind by itself; use Json::in() or a typed bind."];
        yield 'a missing placeholder' => [self::INSERT, [['id' => 1, 'code' => 'a']], "Placeholder :QTY has no bind; add it to the binds, keyed 'qty'."];
    }

    /**
     * Transactions 13 and Errors 11: each refusal is a UsageException
     * naming the row and bind, before any Round trip.
     *
     * @param list<array<string, mixed>> $rows
     */
    #[Test]
    #[DataProvider('refusals')]
    public function a_refused_array_dml_fails_before_any_round_trip(string $sql, array $rows, string $message): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        $trips = self::$counter->during($sid, static function () use ($connection, $sql, $rows, $message): void {
            try {
                self::many($connection, $sql, $rows);
                self::fail('The Array DML ran.');
            } catch (UsageException $e) {
                self::assertSame($message, $e->getMessage());
            }
        });

        self::assertSame(0, $trips);
    }

    /**
     * Transactions 13: a row that is not an array is refused before any
     * Round trip.
     */
    #[Test]
    public function a_row_that_is_not_an_array_is_refused(): void
    {
        $connection = $this->connect();

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('Row 1 is not an array; each row is an array of binds keyed by placeholder name.');
        // @phpstan-ignore argument.type (what a caller without static analysis may pass)
        $connection->executeMany(self::INSERT, [['id' => 1, 'code' => 'a', 'qty' => 1], 2], self::options());
    }

    /**
     * Transactions 13: Implicit results are refused loudly, once the block
     * ran, and its rows are undone: inside a transaction, and under
     * Autocommit too, where a PL/SQL block commits only after it succeeded.
     */
    #[Test]
    public function implicit_results_are_refused_once_the_block_ran(): void
    {
        $connection = $this->connect();
        $block = 'declare c sys_refcursor; begin insert into fx_tx_rows (id) values (:id); open c for select :id from dual; dbms_sql.return_result(c); end;';
        $refused = 'runMany() cannot return Implicit results (DBMS_SQL.RETURN_RESULT); run the block with run() for each row.';

        foreach ([false, true] as $autocommit) {
            try {
                self::many($connection, $block, [['id' => 1], ['id' => 2]], $autocommit);
                self::fail('The Implicit results came back.');
            } catch (UsageException $e) {
                self::assertSame($refused . " The block's rows are undone.", $e->getMessage());
            }
            self::assertSame(0, self::value($connection, 'select count(*) from fx_tx_rows'));
        }
        self::assertSame('0', self::$observer->select('select count(*) as n from fx_tx_rows')[0]['N'], 'nothing committed');
    }

    /**
     * §9: under Autocommit, a PL/SQL block runs without autocommit and the
     * commit follows once it succeeded: one Round trip more than DML, whose
     * commit rides on the execute (C21). Both commit every row. (The
     * block's first run in a Session costs one more: the crate runs its
     * first row alone.)
     */
    #[Test]
    public function under_autocommit_a_plsql_block_takes_one_more_round_trip_for_its_commit(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $block = 'begin insert into fx_tx_rows (id) values (:id); end;';
        $ids = static fn(int $from): array => array_map(static fn(int $id): array => ['id' => $id], range($from, $from + 99));
        self::many($connection, $block, $ids(1));

        $dml = self::$counter->during($sid, static fn(): DriverArrayDml => self::many($connection, self::INSERT, self::valid(100)));
        $plsql = self::$counter->during($sid, static fn(): DriverArrayDml => self::many($connection, $block, $ids(101)));

        self::assertSame(1, $dml);
        self::assertSame(2, $plsql);
        self::assertSame(100, self::committed());
        self::assertSame('200', self::$observer->select('select count(*) as n from fx_tx_rows')[0]['N']);
    }

    /**
     * Transactions 14: a string over 32767 bytes makes its whole column
     * CLOB, short strings included; '' stays NULL, as in VARCHAR2.
     */
    #[Test]
    public function a_long_string_makes_its_column_clob(): void
    {
        $connection = $this->connect();

        $arrayDml = self::many($connection, 'begin :length := fx_lob.text_length(:text); end;', [
            ['text' => 'short', 'length' => Number::out()],
            ['text' => str_repeat('é', 20000), 'length' => Number::out()],
            ['text' => '', 'length' => Number::out()],
            ['text' => null, 'length' => Number::out()],
        ]);

        self::assertSame(['length' => [[5], [20000], [null], [null]]], $arrayDml->outValues());
    }

    /**
     * Transactions 14: an all-null column inserts NULLs, a NULL takes its
     * column's type, and an int and a float bind as one exact NUMBER.
     */
    #[Test]
    public function nulls_take_their_columns_type_and_ints_and_floats_bind_as_an_exact_number(): void
    {
        $connection = $this->connect();

        self::many($connection, 'insert into fx_tx_rows (id, qty, note) values (:id, :qty, :note)', [
            ['id' => 1, 'qty' => null, 'note' => null],
            ['id' => 2.0, 'qty' => 5, 'note' => null],
            ['id' => 3, 'qty' => null, 'note' => null],
        ]);
        $exact = self::many($connection, 'begin :copy := :n; end;', [
            ['n' => 1, 'copy' => Number::out()],
            ['n' => 0.1, 'copy' => Number::out()],
            ['n' => null, 'copy' => Number::out()],
        ]);

        self::assertSame(
            [['ID' => '1', 'QTY' => null, 'NOTE' => null], ['ID' => '2', 'QTY' => '5', 'NOTE' => null], ['ID' => '3', 'QTY' => null, 'NOTE' => null]],
            self::$observer->select('select id, qty, note from fx_tx_rows order by id'),
        );
        self::assertSame(['copy' => [[1], ['0.1'], [null]]], $exact->outValues());
    }

    /**
     * §9: Struct, Collection and Indexed bind per row, IN and OUT (C2
     * covers the Array DML path), and an Object type changing down a column is
     * refused before any Round trip.
     */
    #[Test]
    public function objects_collections_and_associative_arrays_bind_per_row(): void
    {
        $connection = $this->connect();
        $block = 'begin fx_object.move_point(:point, 1, 2); :sum := fx_object.sum_numbers(:numbers) + fx_object.sum_map(:map); end;';
        $row = static fn(int $n): array => [
            'point' => Struct::inOut('fx_point', ['x' => $n, 'y' => $n, 'label' => "p$n"]),
            'numbers' => Collection::in('fx_number_list', range(1, $n)),
            'map' => Indexed::in('fx_object.t_number_map', [-$n => $n]),
            'sum' => Number::out(),
        ];

        $arrayDml = self::many($connection, $block, [$row(1), $row(2), $row(3)]);

        self::assertSame([
            'point' => [[['X' => 2, 'Y' => 3, 'LABEL' => 'p1']], [['X' => 3, 'Y' => 4, 'LABEL' => 'p2']], [['X' => 4, 'Y' => 5, 'LABEL' => 'p3']]],
            'sum' => [[2], [5], [9]],
        ], $arrayDml->outValues());

        $changed = $row(2);
        $changed['point'] = Struct::inOut('fx_polygon', null);
        try {
            self::many($connection, $block, [$row(1), $changed]);
            self::fail('The Object type changed down the column.');
        } catch (UsageException $e) {
            self::assertStringStartsWith("Row 1, bind 'point': Struct::inOut('fx_polygon'), but row 0 made the column Struct::inOut('fx_point');", $e->getMessage());
        }
    }

    /**
     * §9, atomic per call: per-row errors are for DML (C3); a PL/SQL block
     * stops at its first failing row with that row's server error, which
     * does not say which row it was, and the rows before it are undone too,
     * under Autocommit and inside a transaction.
     */
    #[Test]
    public function a_block_failing_at_a_row_leaves_nothing_applied(): void
    {
        $connection = $this->connect();
        $block = 'begin insert into fx_tx_rows (id) values (:id); end;';
        foreach ([true, false] as $autocommit) {
            if (!$autocommit) {
                $connection->execute('insert into fx_batch_rows (id, code, qty) values (1, :code, 1)', ['code' => 'a'], self::options(autocommit: false));
            }
            try {
                self::many($connection, $block, [['id' => 1], ['id' => 2], ['id' => 1], ['id' => 3]], $autocommit);
                self::fail('The duplicate was inserted.');
            } catch (UniqueViolationException $e) {
                self::assertStringStartsWith('ORA-00001: ', $e->getMessage());
            }
            self::assertSame(0, self::value($connection, 'select count(*) from fx_tx_rows'));
        }

        self::assertSame(1, self::value($connection, 'select count(*) from fx_batch_rows'), 'the earlier work');
        self::assertSame(0, self::committed());
        self::assertSame('0', self::$observer->select('select count(*) as n from fx_tx_rows')[0]['N']);
    }

    /**
     * C3: the server returns at most 65535 row errors. Past that the call
     * fails as a QueryException that says so, and nothing is applied.
     */
    #[Test]
    public function more_failing_rows_than_the_server_lists_fail_the_call_and_apply_nothing(): void
    {
        $connection = $this->connect();
        // One shared array for every failing row: little memory, which a
        // later test of memory_limit needs.
        $rows = [['id' => 1, 'code' => 'c1', 'qty' => 1], ...array_fill(0, 65536, ['id' => 2, 'code' => 'c2', 'qty' => -1])];

        try {
            self::many($connection, self::INSERT, $rows);
            self::fail('The rows were inserted.');
        } catch (QueryException $e) {
            self::assertNotInstanceOf(ArrayDmlException::class, $e);
            self::assertSame('65536 of 65537 rows failed, more than the 65535 the server lists; none was applied.', $e->getMessage());
        }

        self::assertSame(0, self::value($connection, 'select count(*) from fx_batch_rows'));
        self::assertSame(0, self::committed());
    }

    /**
     * §9: inside an Explicit transaction the savepoint costs one more Round
     * trip, and the rows stay uncommitted.
     */
    #[Test]
    public function inside_a_transaction_an_array_dml_takes_one_more_round_trip_for_its_savepoint(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        $trips = self::$counter->during($sid, static fn(): DriverArrayDml => self::many($connection, self::INSERT, self::valid(100), autocommit: false));

        self::assertSame(2, $trips);
        self::assertSame(100, self::value($connection, 'select count(*) from fx_batch_rows'));
        self::assertSame(0, self::committed());
    }

    /**
     * A placeholder written twice in SQL needs one value per occurrence,
     * which named rows cannot give yet: refused before any Round trip.
     */
    #[Test]
    public function a_placeholder_written_twice_is_refused_before_any_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        $trips = self::$counter->during($sid, static function () use ($connection): void {
            try {
                self::many($connection, 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :id)', [['id' => 1, 'code' => 'a']]);
                self::fail('The Array DML ran.');
            } catch (UnsupportedException $e) {
                self::assertSame('runMany() cannot bind a placeholder written more than once in the SQL yet; give each occurrence a name of its own.', $e->getMessage());
            }
        });

        self::assertSame(0, $trips);
    }

    /**
     * Ten valid rows but 3 (a duplicate id: ORA-00001), 5 (a NULL code:
     * ORA-01400) and 7 (a negative qty: ORA-02290).
     *
     * @return list<array<string, mixed>>
     */
    private static function withBadRows(): array
    {
        $rows = self::valid(10);
        $rows[3]['id'] = 1;
        $rows[5]['code'] = null;
        $rows[7]['qty'] = -1;

        return $rows;
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function failing(\Closure $call): ArrayDmlException
    {
        try {
            $call();
        } catch (ArrayDmlException $e) {
            return $e;
        }
        self::fail('No ArrayDmlException.');
    }

    /**
     * Each failing row as [index, code], checking its message is the
     * server's for that code.
     *
     * @return list<array{int, int}>
     */
    private static function failures(ArrayDmlException $e): array
    {
        return array_map(static function (RowFailure $row): array {
            self::assertStringStartsWith(\sprintf('ORA-%05d: ', $row->code), $row->message);

            return [$row->index, $row->code];
        }, $e->rows());
    }

    /**
     * Valid fx_batch_rows rows with ids 1 to $count.
     *
     * @return list<array{id: int, code: string, qty: int}>
     */
    private static function valid(int $count): array
    {
        return array_map(static fn(int $id): array => ['id' => $id, 'code' => "c$id", 'qty' => $id], range(1, $count));
    }

    /**
     * How many fx_batch_rows rows another Session sees: committed ones only.
     */
    private static function committed(): int
    {
        return (int) self::$observer->select('select count(*) as n from fx_batch_rows')[0]['N'];
    }

    private static function clear(): void
    {
        self::$observer->execute('delete from fx_batch_rows');
        self::$observer->execute('delete from fx_tx_rows');
        self::$observer->commit();
    }

    /**
     * Each returned LOB's contents, per row; NULL stays `null`.
     *
     * @param list<list<mixed>> $lists
     *
     * @return list<list<string|null>>
     */
    private static function contents(array $lists): array
    {
        return array_map(static fn(array $list): array => array_map(static function (mixed $lob): ?string {
            if ($lob === null) {
                return null;
            }
            self::assertInstanceOf(DriverLob::class, $lob);
            $contents = '';
            while (($chunk = $lob->readChunk()) !== '') {
                $contents .= $chunk;
            }

            return $contents;
        }, $list), $lists);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function many(DriverConnection $connection, string $sql, array $rows, bool $autocommit = true): DriverArrayDml
    {
        return $connection->executeMany($sql, $rows, self::options(autocommit: $autocommit));
    }
}
