<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use Fiber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Column;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Cursor;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\SparseVector;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Type;
use Rajska\Oracle\ValueMapping;
use Rajska\Oracle\VectorFormat;

/**
 * A Cursor streams a query's rows lazily, in one pass, shaped by the
 * Execution's options.
 */
final class CursorTest extends TestCase
{
    private const string FIVE_ROWS = 'select level n from dual connect by level <= 5';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * Seam and rows 7.
     */
    #[Test]
    public function select_equals_run_rows(): void
    {
        $sql = 'select id, name from fx_items where id <= :last order by id';

        self::assertSame(
            $this->connection->run($sql, ['last' => 3])->rows()->all(),
            $this->connection->select($sql, ['last' => 3])->all(),
        );
        self::assertSame([['ID' => 1, 'NAME' => 'Item 0001']], $this->connection->select($sql, ['last' => 1])->all());
    }

    /**
     * Seam and rows 7: rows come batch by batch as they are read, not with
     * the execute.
     */
    #[Test]
    public function select_is_lazy(): void
    {
        $sid = $this->sid();
        $counter = RoundTripCounter::open();
        $options = new ExecuteOptions(fetchSize: 10);

        try {
            $cursor = null;
            self::assertSame(1, $counter->during($sid, function () use (&$cursor, $options): void {
                $cursor = $this->connection->select('select id from fx_items order by id', [], $options);
            }));
            self::assertInstanceOf(Cursor::class, $cursor);
            // The execute brought the first batch; the second and third
            // batches cost one Round trip each.
            self::assertSame(2, $counter->during($sid, function () use ($cursor): void {
                foreach ($cursor as $position => $row) {
                    if ($position === 29) {
                        break;
                    }
                }
            }));
        } finally {
            $counter->close();
        }
    }

    /**
     * Seam and rows 7: Core never classifies SQL.
     */
    #[Test]
    public function select_of_an_update_runs_it_then_fails_in_rows(): void
    {
        $table = 'tmp_select_' . bin2hex(random_bytes(4));
        $this->connection->run("create table $table (id number)");

        try {
            try {
                $this->connection->select("insert into $table values (1)");
                self::fail('select() of an INSERT returned a Cursor.');
            } catch (UsageException $e) {
                self::assertStringContainsString('results()', $e->getMessage());
                self::assertStringContainsString('out()', $e->getMessage());
                self::assertSame("insert into $table values (1)", $e->sql());
            }
            self::assertSame(1, $this->connection->select("select count(*) from $table")->value());

            $this->expectException(UsageException::class);
            $this->connection->run('begin null; end;')->rows();
        } finally {
            $this->connection->run("drop table $table purge");
        }
    }

    /**
     * Seam and rows 8: keys are 0-based positions; a second foreach
     * continues where the first stopped; past the end there is nothing.
     */
    #[Test]
    public function foreach_is_single_pass_and_continues_where_it_stopped(): void
    {
        $cursor = $this->connection->select(self::FIVE_ROWS, [], new ExecuteOptions(fetchSize: 2));

        $seen = [];
        foreach ($cursor as $position => $row) {
            $seen[$position] = $row['N'];
            if ($position === 2) {
                break;
            }
        }
        foreach ($cursor as $position => $row) {
            $seen[$position] = $row['N'];
        }

        self::assertSame([0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5], $seen);
        self::assertSame([], iterator_to_array($cursor));
        self::assertSame([], $cursor->all());
    }

    /**
     * Seam and rows 8.
     */
    #[Test]
    public function all_returns_the_remaining_rows(): void
    {
        $cursor = $this->connection->select(self::FIVE_ROWS, [], new ExecuteOptions(fetchSize: 2));

        foreach ($cursor as $row) {
            break;
        }

        self::assertSame([['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5]], $cursor->all());
        self::assertSame([], $cursor->all());
    }

    /**
     * Seam and rows 8 and 9: first() and value() read the next row, then
     * close.
     */
    #[Test]
    public function first_and_value_return_the_next_row_then_close(): void
    {
        $cursor = $this->connection->select(self::FIVE_ROWS);
        foreach ($cursor as $row) {
            break;
        }
        self::assertSame(['N' => 2], $cursor->first());
        $this->assertClosed($cursor);

        self::assertSame(1, $this->connection->select(self::FIVE_ROWS)->value());
        self::assertNull($this->connection->select('select 1 from dual where 1 = 0')->value());
        self::assertNull($this->connection->select('select 1 from dual where 1 = 0')->first());
        self::assertSame(1, $this->connection->select(self::FIVE_ROWS, [], new ExecuteOptions(rowShape: RowShape::List))->value());

        $cursor = $this->connection->select(self::FIVE_ROWS);
        $cursor->value();
        $this->assertClosed($cursor);
    }

    /**
     * Seam and rows 9.
     */
    #[Test]
    public function reading_after_close_fails_and_a_double_close_is_fine(): void
    {
        $cursor = $this->connection->select(self::FIVE_ROWS, [], new ExecuteOptions(fetchSize: 2));
        foreach ($cursor as $row) {
            break;
        }

        $cursor->close();
        $cursor->close();

        $this->assertClosed($cursor);
    }

    /**
     * Seam and rows 10: two Cursors read interleaved, also from two Fibers,
     * with DML and a commit between batches.
     */
    #[Test]
    public function two_cursors_read_interleaved_with_dml_and_commit_between_batches(): void
    {
        $options = new ExecuteOptions(fetchSize: 3);
        $sql = 'select id from fx_items where id <= :last order by id';
        $iterators = [
            $this->connection->select($sql, ['last' => 10], $options)->getIterator(),
            $this->connection->select($sql, ['last' => 10], $options)->getIterator(),
        ];

        $ids = [[], []];
        while ($iterators[0]->valid() || $iterators[1]->valid()) {
            foreach ($iterators as $which => $rows) {
                if ($rows->valid()) {
                    $ids[$which][] = $rows->current()['ID'];
                    $rows->next();
                }
            }
            $this->connection->run('update fx_items set name = name where id = 1');
            $this->connection->run('commit');
        }

        self::assertSame([range(1, 10), range(1, 10)], $ids);

        $fibers = [];
        $read = [];
        foreach (['a', 'b'] as $name) {
            $cursor = $this->connection->select($sql, ['last' => 10], $options);
            $fibers[$name] = new Fiber(static function () use ($cursor, $name, &$read): void {
                foreach ($cursor as $row) {
                    $read[$name][] = $row['ID'];
                    Fiber::suspend();
                }
            });
        }
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        while (array_filter($fibers, static fn(Fiber $f): bool => !$f->isTerminated()) !== []) {
            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                }
            }
        }

        self::assertSame(['a' => range(1, 10), 'b' => range(1, 10)], $read);
    }

    /**
     * Seam and rows 11: dropping a Cursor half-read closes its server cursor.
     */
    #[Test]
    public function two_thousand_cursors_dropped_half_read_raise_no_ora_01000(): void
    {
        $options = new ExecuteOptions(fetchSize: 2);
        for ($i = 0; $i < 2000; ++$i) {
            $cursor = $this->connection->select(self::FIVE_ROWS, [], $options);
            foreach ($cursor as $row) {
                break;
            }
            unset($cursor);
        }

        self::assertSame(5, $this->connection->select('select count(*) from (' . self::FIVE_ROWS . ')')->value());
    }

    /**
     * Seam and rows 21, through the public API: Column objects with enums,
     * before the first row.
     */
    #[Test]
    public function columns_are_column_objects_before_the_first_row(): void
    {
        $cursor = $this->connection->select(
            "select id, price, to_vector('[1,2,3]', 3, float32) embedding from fx_items where id = 1",
            [],
            new ExecuteOptions(keyCase: KeyCase::Lower),
        );

        $columns = $cursor->columns();

        self::assertEquals([
            new Column(
                name: 'id',
                type: Type::Number,
                nullable: false,
                precision: 10,
                scale: 0,
                maxSize: null,
                objectType: null,
                vectorDimensions: null,
                vectorFormat: null,
                sparse: false,
            ),
            new Column(
                name: 'price',
                type: Type::Number,
                nullable: false,
                precision: 10,
                scale: 2,
                maxSize: null,
                objectType: null,
                vectorDimensions: null,
                vectorFormat: null,
                sparse: false,
            ),
            new Column(
                name: 'embedding',
                type: Type::Vector,
                nullable: true,
                precision: null,
                scale: null,
                maxSize: null,
                objectType: null,
                vectorDimensions: 3,
                vectorFormat: VectorFormat::Float32,
                sparse: false,
            ),
        ], $columns);
        self::assertSame(Type::Number, $cursor->columns()[0]->type);
        $cursor->close();
    }

    /**
     * Scope 8 through the public API: an XMLTYPE column is a Column of
     * `Type::Xmltype`; reading its value throws UnsupportedException, and
     * the Connection reads on. The other scalar types arrive as stated.
     */
    #[Test]
    public function an_xmltype_column_is_described_and_reading_it_is_unsupported(): void
    {
        $cursor = $this->connection->select("select xmltype('<a>1</a>') doc from dual");
        self::assertSame(Type::Xmltype, $cursor->columns()[0]->type);

        try {
            $cursor->first();
            self::fail('An XMLTYPE value was read.');
        } catch (UnsupportedException $e) {
            self::assertSame('Column DOC: XMLTYPE values cannot be fetched yet.', $e->getMessage());
        }

        self::assertSame(
            ['BF' => 0.1, 'FLAG' => true, 'BYTES' => "\x00\xff", 'CH' => 'a  ', 'LONG_TEXT' => 'long text'],
            $this->connection->select(<<<'SQL'
                select to_binary_float(0.1) bf, true flag, hextoraw('00FF') bytes,
                       cast('a' as char(3)) ch, l.text long_text
                  from fx_long_rows l
                 where l.id = 1
                SQL)->first(),
        );
    }

    /**
     * Values 11 and 12 through the public API: a JSON column decodes to
     * arrays, a VECTOR column to a list or a `SparseVector`.
     */
    #[Test]
    public function json_and_vector_columns_arrive_decoded(): void
    {
        $row = $this->connection->select(
            "select json('{\"a\": [1, 0.5]}') doc, v8, s8 from fx_vectors where id = 1",
            [],
            new ExecuteOptions(keyCase: KeyCase::Lower),
        )->first();

        self::assertNotNull($row);
        self::assertSame(['a' => [1, '0.5']], $row['doc']);
        self::assertSame([1, -2, 127], $row['v8']);
        self::assertEquals(new SparseVector(10, [0 => -128, 9 => 127]), $row['s8']);
    }

    /**
     * Seam and rows 22: the config's rowShape and keyCase apply, and
     * ExecuteOptions overrides them for one Execution.
     */
    #[Test]
    public function row_shape_and_key_case_come_from_the_config_and_execute_options_override_them(): void
    {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(rowShape: RowShape::Assoc, keyCase: KeyCase::Lower));
        $sql = 'select id, name from fx_items where id = 1';

        try {
            self::assertSame(['id' => 1, 'name' => 'Item 0001'], $connection->select($sql)->first());
            self::assertSame([1, 'Item 0001'], $connection->select($sql, [], new ExecuteOptions(rowShape: RowShape::List))->first());
            self::assertSame(['ID' => 1, 'NAME' => 'Item 0001'], $connection->select($sql, [], new ExecuteOptions(keyCase: KeyCase::Native))->first());
            self::assertSame(['id' => 1, 'name' => 'Item 0001'], $connection->select($sql)->first());
        } finally {
            $connection->close();
        }
    }

    /**
     * Values 2: numbers: Float per Connection and per Execution.
     */
    #[Test]
    public function numbers_float_works_per_connection_and_per_execution(): void
    {
        $float = new ValueMapping(numbers: NumberMode::Float);
        $sql = 'select 12.5 half, 5 five from dual';

        self::assertSame(['HALF' => 12.5, 'FIVE' => 5], $this->connection->select($sql, [], new ExecuteOptions(values: $float))->first());
        self::assertSame(['HALF' => '12.5', 'FIVE' => 5], $this->connection->select($sql)->first());

        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(values: $float));
        try {
            self::assertSame(['HALF' => 12.5, 'FIVE' => 5], $connection->select($sql)->first());
            self::assertSame(['HALF' => '12.5', 'FIVE' => 5], $connection->select($sql, [], new ExecuteOptions(values: new ValueMapping()))->first());
        } finally {
            $connection->close();
        }
    }

    /**
     * Errors 13 and Seam and rows 1, through the public API.
     */
    #[Test]
    public function ddl_runs_through_run_and_a_compilation_error_is_a_warning(): void
    {
        $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));

        $created = $this->connection->run("create or replace procedure $procedure is begin no_such_thing; end;");
        try {
            self::assertSame(0, $created->affected());
            self::assertNotNull($created->warning());
            self::assertNull($this->connection->run("create or replace procedure $procedure is begin null; end;")->warning());
        } finally {
            $this->connection->run("drop procedure $procedure");
        }
    }

    /**
     * Seam and rows 12, through the public API: closing the Connection
     * invalidates its Cursors, even one with rows of its last batch still
     * in hand.
     */
    #[Test]
    public function after_the_connection_closes_its_cursor_fails_even_with_rows_left_in_its_batch(): void
    {
        $cursor = $this->connection->select(self::FIVE_ROWS);
        foreach ($cursor as $row) {
            self::assertSame(['N' => 1], $row);
            break;
        }

        $this->connection->close();

        $served = [];
        try {
            foreach ($cursor as $row) {
                $served[] = $row;
            }
            self::fail('foreach after the Connection closed succeeded.');
        } catch (UsageException $e) {
            self::assertSame('The Connection is closed.', $e->getMessage());
        }
        self::assertSame([], $served, 'No row of the batch is served after the Connection closed.');
        $this->assertClosed($cursor);
        $cursor->close();
    }

    private function assertClosed(Cursor $cursor): void
    {
        foreach ([
            'foreach' => static fn(): array => iterator_to_array($cursor),
            'all()' => static fn(): array => $cursor->all(),
            'first()' => static fn(): ?array => $cursor->first(),
            'value()' => static fn(): mixed => $cursor->value(),
        ] as $read => $call) {
            try {
                $call();
                self::fail("$read after close() succeeded.");
            } catch (UsageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function sid(): int
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);

        return (int) $sid;
    }
}
