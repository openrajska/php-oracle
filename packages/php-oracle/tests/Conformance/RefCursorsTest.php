<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Cursor;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\ValueMapping;

/**
 * REF CURSORs (OUT, IN OUT, function return) and nested `CURSOR()` columns;
 * fixtures in fixtures/sql/02_cursor.sql.
 */
final class RefCursorsTest extends ConformanceTestCase
{
    /** Each category with a nested Cursor of its first 4 items. */
    private const string NESTED = <<<'SQL'
        select c.id, c.name,
               cursor(select i.id, i.price from fx_items i
                       where i.category_id = c.id and i.id <= 20 order by i.id) as items
          from fx_categories c
         order by c.id
        SQL;

    #[Test]
    public function a_ref_cursor_out_arrives_as_a_driver_cursor(): void
    {
        $execution = $this->connect()->execute(
            'begin open :o for select id, name, price from fx_items where id <= :n order by id; end;',
            ['o' => Cursor::out(), 'n' => 3],
            self::options(),
        );

        self::assertSame(
            [
                ['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'],
                ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5'],
                ['ID' => 3, 'NAME' => 'Item 0003', 'PRICE' => '3.75'],
            ],
            $this->drain($this->cursor($execution, 'o')),
        );
    }

    #[Test]
    public function ref_cursors_with_a_date_column_read_in_full(): void
    {
        Temporals::inZone('UTC', function (): void {
            $connection = $this->connect();
            $string = self::options(values: new ValueMapping(temporals: TemporalMode::String));

            $items = $connection->execute('begin :o := fx_cursor.items(2); end;', ['o' => Cursor::out()], self::options());
            $typed = $connection->execute('begin fx_cursor.typed_cursor(2, :o); end;', ['o' => Cursor::out()], $string);
            $two = $connection->execute(
                'begin fx_cursor.two_cursors(1, :items, :categories, :total); end;',
                ['items' => Cursor::out(), 'categories' => Cursor::out(), 'total' => Number::out()],
                self::options(),
            );

            self::assertSame(
                ['+2026-01-01 00:00:00.000000 UTC', '+2026-01-01 01:00:00.000000 UTC'],
                array_map(Temporals::shown(...), array_column($this->drain($this->cursor($items, 'o')), 'CREATED_AT')),
            );
            self::assertSame(
                ['2026-01-01 00:00:00', '2026-01-01 01:00:00'],
                array_column($this->drain($this->cursor($typed, 'o')), 'CREATED_AT'),
            );
            self::assertSame(
                [['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25', 'CREATED_AT' => '+2026-01-01 00:00:00.000000 UTC']],
                array_map(static fn(array $row): array => array_map(Temporals::shown(...), $row), $this->drain($this->cursor($two, 'items'))),
            );
        });
    }

    /** An IN OUT REF CURSOR goes as NULL. */
    #[Test]
    public function a_ref_cursor_in_out_is_opened_by_the_server(): void
    {
        $execution = $this->connect()->execute(
            'begin open :io for select name from fx_categories where id <= 2 order by id; end;',
            ['io' => Cursor::inOut()],
            self::options(),
        );

        self::assertSame([['NAME' => 'Plain'], ['NAME' => 'Ação']], $this->drain($this->cursor($execution, 'io')));
    }

    #[Test]
    public function a_ref_cursor_function_return_arrives_as_a_driver_cursor(): void
    {
        $execution = $this->connect()->execute(
            'begin :o := fx_cursor.categories_with_items; end;',
            ['o' => Cursor::out()],
            self::options(rowShape: RowShape::List),
        );

        $cursor = $this->cursor($execution, 'o');
        self::assertSame(['ID', 'NAME', 'ITEMS'], array_column($cursor->columns(), 'name'));
        self::assertSame(['number', 'varchar', 'cursor'], array_column($cursor->columns(), 'type'));
    }

    #[Test]
    public function an_unopened_ref_cursor_fails_on_its_first_read(): void
    {
        $connection = $this->connect();
        $execution = $connection->execute('declare c sys_refcursor; begin :o := c; end;', ['o' => Cursor::out()], self::options());
        $cursor = $this->cursor($execution, 'o');

        try {
            $cursor->fetchBatch();
            self::fail('An unopened REF CURSOR was read.');
        } catch (QueryException $queryException) {
            self::assertSame(1001, $queryException->getCode());
            self::assertStringStartsWith('ORA-01001', $queryException->getMessage());
        }
        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    #[Test]
    public function an_empty_ref_cursor_describes_its_columns(): void
    {
        $execution = $this->connect()->execute(
            'begin open :o for select id, name from fx_categories where 1 = 0; end;',
            ['o' => Cursor::out()],
            self::options(),
        );
        $cursor = $this->cursor($execution, 'o');

        self::assertSame(
            [
                ['name' => 'ID', 'type' => 'number', 'nullable' => false, 'precision' => 10, 'scale' => 0, 'maxSize' => null, 'objectType' => null, 'vectorDimensions' => null, 'vectorFormat' => null, 'sparse' => false],
                ['name' => 'NAME', 'type' => 'varchar', 'nullable' => false, 'precision' => null, 'scale' => null, 'maxSize' => 50, 'objectType' => null, 'vectorDimensions' => null, 'vectorFormat' => null, 'sparse' => false],
            ],
            $cursor->columns(),
        );
        self::assertSame([[], []], [$cursor->fetchBatch(), $cursor->fetchBatch()]);
    }

    #[Test]
    public function every_nested_cursor_reads_after_the_parent_is_read_to_its_end(): void
    {
        $parent = $this->connect()->execute(self::NESTED, [], self::options(fetchSize: 2))->cursor();
        self::assertNotNull($parent);
        $rows = $this->drain($parent);

        self::assertSame([1, 2, 3, 4, 5], array_column($rows, 'ID'));
        $items = [];
        foreach ($rows as $row) {
            $name = $row['NAME'];
            self::assertIsString($name);
            self::assertInstanceOf(DriverCursor::class, $row['ITEMS']);
            $items[$name] = array_column($this->drain($row['ITEMS']), 'ID');
        }
        self::assertSame(
            ['Plain' => [1, 6, 11, 16], 'Ação' => [2, 7, 12, 17], 'Café' => [3, 8, 13, 18], 'Straße' => [4, 9, 14, 19], '日本' => [5, 10, 15, 20]],
            $items,
        );
    }

    #[Test]
    public function a_nested_cursor_fails_after_its_parents_close(): void
    {
        $connection = $this->connect();
        $parent = $connection->execute(self::NESTED, [], self::options())->cursor();
        self::assertNotNull($parent);
        [$first, $second] = array_column($parent->fetchBatch(), 'ITEMS');
        self::assertInstanceOf(DriverCursor::class, $first);
        self::assertInstanceOf(DriverCursor::class, $second);
        self::assertSame([['ID' => 1, 'PRICE' => '1.25']], \array_slice($first->fetchBatch(), 0, 1));

        $parent->close();

        foreach (['read' => $first, 'unread' => $second] as $which => $child) {
            foreach (['fetchBatch()' => $child->fetchBatch(...), 'columns()' => $child->columns(...)] as $call => $read) {
                try {
                    $read();
                    self::fail("$call of the $which child after its parent's close() succeeded.");
                } catch (UsageException $e) {
                    self::assertSame('The Cursor this one is nested in is closed, which closed this one too.', $e->getMessage(), "$which $call");
                    self::assertSame(self::NESTED, $e->sql());
                }
            }
            $child->close();
        }
        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    /**
     * Without a Statement cache, the parent's server cursor would close with
     * the object, and its nested cursors with it.
     */
    #[Test]
    public function a_nested_cursor_keeps_its_parent_open_after_the_parent_object_is_dropped(): void
    {
        $connection = $this->connect(...self::ownPool() + ['statementCacheSize' => 0]);
        $parent = $connection->execute(self::NESTED, [], self::options())->cursor();
        self::assertNotNull($parent);
        $children = array_column($parent->fetchBatch(), 'ITEMS', 'ID');
        unset($parent);
        // A Round trip that would carry the parent's close.
        self::assertSame(1, self::value($connection, 'select 1 from dual'));

        $child = $children[3];
        self::assertInstanceOf(DriverCursor::class, $child);
        self::assertSame([3, 8, 13, 18], array_column($this->drain($child), 'ID'));
    }

    #[Test]
    public function ref_cursors_and_nested_cursors_inherit_the_executes_options(): void
    {
        $options = self::options(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );
        $execution = $this->connect()->execute('begin :o := fx_cursor.categories_with_items; end;', ['o' => Cursor::out()], $options);
        $categories = $this->cursor($execution, 'o');

        self::assertSame(['id', 'name', 'items'], array_column($categories->columns(), 'name'));
        $batch = $categories->fetchBatch();
        self::assertCount(3, $batch);
        self::assertSame([1, 'Plain'], \array_slice($batch[0], 0, 2));
        $items = $batch[0][2];
        self::assertInstanceOf(DriverCursor::class, $items);
        self::assertSame(['id', 'name', 'price'], array_column($items->columns(), 'name'));
        self::assertSame([[1, 'Item 0001', 1.25], [6, 'Item 0006', 7.5], [11, 'Item 0011', 13.75]], $items->fetchBatch());
        self::assertSame([[16, 'Item 0016', 20]], $items->fetchBatch());
        self::assertSame([], $items->fetchBatch());
        self::assertCount(2, $categories->fetchBatch());
    }

    #[Test]
    public function a_ref_cursor_reads_fetch_size_rows_per_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $execution = $connection->execute(
            'begin open :o for select level n from dual connect by level <= 25; end;',
            ['o' => Cursor::out()],
            self::options(fetchSize: 10),
        );
        $cursor = $this->cursor($execution, 'o');
        $counter = RoundTripCounter::open();

        try {
            $sizes = [];
            $trips = $counter->during($sid, static function () use ($cursor, &$sizes): void {
                while (($batch = $cursor->fetchBatch()) !== []) {
                    $sizes[] = \count($batch);
                }
            });
        } finally {
            $counter->close();
        }

        self::assertSame([10, 10, 5], $sizes);
        self::assertSame(3, $trips);
    }

    #[Test]
    public function after_close_ref_cursors_and_nested_cursors_fail_as_use_after_close(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$fields);
        $session = self::session($connection);
        $execution = $connection->execute('begin :o := fx_cursor.categories_with_items; end;', ['o' => Cursor::out()], self::options());
        $categories = $this->cursor($execution, 'o');
        $items = $categories->fetchBatch()[0]['ITEMS'];
        self::assertInstanceOf(DriverCursor::class, $items);

        $connection->close();

        foreach (['REF CURSOR' => $categories, 'nested Cursor' => $items] as $kind => $cursor) {
            foreach (['fetchBatch()' => $cursor->fetchBatch(...), 'columns()' => $cursor->columns(...)] as $call => $read) {
                try {
                    $read();
                    self::fail("$call of a $kind after close() succeeded.");
                } catch (UsageException $e) {
                    self::assertSame('The Connection is closed.', $e->getMessage(), "$kind $call");
                }
            }
            $cursor->close();
        }

        $next = $this->connect(...$fields);
        self::assertSame($session, self::session($next));
        $execution = $next->execute('begin :o := fx_cursor.categories_with_items; end;', ['o' => Cursor::out()], self::options());
        self::assertSame([1, 2, 3, 4, 5], array_column($this->drain($this->cursor($execution, 'o')), 'ID'));
    }

    /** `open_cursors` is 300. */
    #[Test]
    public function cursors_dropped_unread_raise_no_ora_01000(): void
    {
        $connection = $this->connect();
        for ($i = 0; $i < 1000; ++$i) {
            $parent = $connection->execute(self::NESTED, [], self::options())->cursor();
            self::assertNotNull($parent);
            self::assertCount(5, $parent->fetchBatch());
            $execution = $connection->execute('begin open :o for select id from fx_categories; end;', ['o' => Cursor::out()], self::options());
            self::assertInstanceOf(DriverCursor::class, $execution->outValues()['o'][0]);
        }
        unset($parent, $execution);

        $parent = $connection->execute(self::NESTED, [], self::options())->cursor();
        self::assertNotNull($parent);
        $items = $parent->fetchBatch()[4]['ITEMS'];
        self::assertInstanceOf(DriverCursor::class, $items);
        self::assertSame([5, 10, 15, 20], array_column($this->drain($items), 'ID'));
    }

    /**
     * The one REF CURSOR in an OUT value list.
     */
    private function cursor(DriverExecution $execution, string $name): DriverCursor
    {
        $values = $execution->outValues()[$name] ?? null;
        self::assertIsArray($values);
        self::assertCount(1, $values);
        self::assertInstanceOf(DriverCursor::class, $values[0]);

        return $values[0];
    }

    /**
     * @return list<array<int|string, mixed>>
     */
    private function drain(DriverCursor $cursor): array
    {
        $rows = [];
        while (($batch = $cursor->fetchBatch()) !== []) {
            array_push($rows, ...$batch);
        }

        return $rows;
    }
}
