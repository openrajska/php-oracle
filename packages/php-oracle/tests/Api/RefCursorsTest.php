<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Cursor as CursorBind;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Column;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Cursor;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Type;
use Rajska\Oracle\ValueMapping;

/**
 * REF CURSORs (OUT, IN OUT, function return) and nested `CURSOR()` columns
 * as lazy Cursors (fixtures/sql/02_cursor.sql).
 */
final class RefCursorsTest extends TestCase
{
    /** Each category with a nested Cursor of its first 4 items. */
    private const string NESTED = <<<'SQL'
        select c.id, c.name,
               cursor(select i.id, i.price from fx_items i
                       where i.category_id = c.id and i.id <= 20 order by i.id) as items
          from fx_categories c
         order by c.id
        SQL;

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
    public function a_procedure_gives_its_ref_cursors_as_cursors_read_interleaved(): void
    {
        $execution = $this->connection->procedure('fx_cursor.report', [
            'p_limit' => 250,
            'io_label' => Varchar::inOut('total'),
            'o_items' => CursorBind::out(),
            'o_categories' => CursorBind::out(),
            'o_total' => Number::out(),
            'io_names' => CursorBind::inOut(),
        ]);

        self::assertSame('total!', $execution->out('io_label')->string());
        self::assertSame(1000, $execution->out('o_total')->int());
        $items = $execution->out('o_items')->cursor();
        $categories = $execution->out('O_CATEGORIES')->cursor();
        self::assertSame($items, $execution->out('o_items')->cursor());

        $ids = [];
        $names = [];
        foreach ($items as $item) {
            $ids[] = $item['ID'];
            if (\count($ids) === 1) {
                foreach ($categories as $category) {
                    $names[] = $category['NAME'];
                }
            }
        }
        self::assertSame(range(1, 250), $ids);
        self::assertSame(['Plain', 'Ação', 'Café', 'Straße', '日本'], $names);
        self::assertSame([['NAME' => 'Plain'], ['NAME' => 'Ação']], $execution->out('io_names')->cursor()->all());
    }

    #[Test]
    public function the_cursor_terminal_gives_a_functions_ref_cursor(): void
    {
        $categories = $this->connection->function('fx_cursor.categories_with_items')->cursor();

        $items = [];
        foreach ($categories as $category) {
            $name = $category['NAME'];
            self::assertIsString($name);
            $items[$name] = array_column(self::nested($category['ITEMS'])->all(), 'ID');
        }
        self::assertSame(
            ['Plain' => [1, 6, 11, 16], 'Ação' => [2, 7, 12, 17], 'Café' => [3, 8, 13, 18], 'Straße' => [4, 9, 14, 19], '日本' => [5, 10, 15, 20]],
            $items,
        );
    }

    #[Test]
    public function every_nested_cursor_reads_after_the_parents_all(): void
    {
        $rows = $this->connection->select(self::NESTED, options: new ExecuteOptions(fetchSize: 2))->all();

        self::assertSame([1, 2, 3, 4, 5], array_column($rows, 'ID'));
        self::assertSame(
            [[1, 6, 11, 16], [2, 7, 12, 17], [3, 8, 13, 18], [4, 9, 14, 19], [5, 10, 15, 20]],
            array_map(static fn(array $row): array => array_column(self::nested($row['ITEMS'])->all(), 'ID'), $rows),
        );
    }

    /**
     * Also when it still holds rows of its last batch, or when `first()`
     * closed the parent.
     */
    #[Test]
    public function a_nested_cursor_fails_after_its_parents_close(): void
    {
        $parent = $this->connection->select(self::NESTED);
        $children = array_column($parent->all(), 'ITEMS');
        [$started, $unread] = array_map(self::nested(...), $children);
        self::assertSame(['ID' => 1, 'PRICE' => '1.25'], $started->getIterator()->current());

        $parent->close();

        foreach (['started' => $started, 'unread' => $unread] as $which => $child) {
            try {
                $child->all();
                self::fail("The $which child read after its parent's close().");
            } catch (UsageException $e) {
                self::assertSame('The Cursor this one is nested in is closed, which closed this one too.', $e->getMessage(), $which);
            }
        }

        $first = $this->connection->select(self::NESTED)->first();
        self::assertNotNull($first);
        $this->expectException(UsageException::class);
        self::nested($first['ITEMS'])->all();
    }

    #[Test]
    public function ref_cursors_and_nested_cursors_inherit_the_executions_options(): void
    {
        $options = new ExecuteOptions(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );
        $categories = $this->connection->function('fx_cursor.categories_with_items', options: $options)->cursor();

        self::assertSame(['id', 'name', 'items'], array_map(static fn(Column $c): string => $c->name, $categories->columns()));
        self::assertSame(Type::Cursor, $categories->columns()[2]->type);
        [$id, $name, $items] = $categories->getIterator()->current();
        self::assertSame([1, 'Plain'], [$id, $name]);
        self::assertInstanceOf(Cursor::class, $items);
        self::assertSame([[1, 'Item 0001', 1.25], [6, 'Item 0006', 7.5], [11, 'Item 0011', 13.75], [16, 'Item 0016', 20]], $items->all());
    }

    #[Test]
    public function a_nested_cursor_reads_with_the_executions_options(): void
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);
        $options = new ExecuteOptions(fetchSize: 3, values: new ValueMapping(numbers: NumberMode::Float), keyCase: KeyCase::Lower);
        $parent = $this->connection->select(
            'select cursor(select level / 2 as half from dual connect by level <= 7) as halves from dual',
            options: $options,
        );
        $halves = $parent->all()[0]['halves'];
        self::assertInstanceOf(Cursor::class, $halves);
        $counter = RoundTripCounter::open();

        try {
            $rows = [];
            $trips = $counter->during((int) $sid, static function () use ($halves, &$rows): void {
                $rows = $halves->all();
            });
        } finally {
            $counter->close();
        }

        self::assertSame([['half' => 0.5], ['half' => 1], ['half' => 1.5], ['half' => 2], ['half' => 2.5], ['half' => 3], ['half' => 3.5]], $rows);
        self::assertSame(3, $trips);
    }

    /**
     * Also with rows in hand.
     */
    #[Test]
    public function after_close_ref_cursors_and_nested_cursors_fail_as_use_after_close(): void
    {
        $categories = $this->connection->function('fx_cursor.categories_with_items')->cursor();
        $iterator = $categories->getIterator();
        $items = $iterator->current()['ITEMS'];
        self::assertInstanceOf(Cursor::class, $items);

        $this->connection->close();

        foreach (['REF CURSOR' => $categories, 'nested Cursor' => $items] as $kind => $cursor) {
            try {
                $cursor->all();
                self::fail("A $kind read after close().");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage(), $kind);
            }
        }
    }

    /**
     * A REF CURSOR the block never opened is a Cursor, never null.
     */
    #[Test]
    public function an_unopened_ref_cursor_fails_on_its_first_read(): void
    {
        $cursor = $this->connection->run('declare c sys_refcursor; begin :o := c; end;', ['o' => CursorBind::out()])->out('o')->cursor();

        try {
            $cursor->all();
            self::fail('An unopened REF CURSOR was read.');
        } catch (QueryException $e) {
            self::assertSame(1001, $e->getCode());
        }
    }

    #[Test]
    public function a_cursor_passed_back_as_in_fails_loudly(): void
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);
        $cursor = $this->connection->function('fx_cursor.categories_with_items')->cursor();
        $counter = RoundTripCounter::open();

        try {
            foreach (['a plain value' => $cursor, 'Cursor::inOut()' => CursorBind::inOut($cursor)] as $how => $bind) {
                $trips = $counter->during((int) $sid, function () use ($bind, $how): void {
                    try {
                        $this->connection->run('declare c sys_refcursor := :c; begin null; end;', ['c' => $bind]);
                        self::fail("A Cursor bound back as $how.");
                    } catch (UnsupportedException $e) {
                        self::assertStringContainsString("Bind 'c': passing a Cursor back to the server is not supported yet", $e->getMessage(), $how);
                    }
                });
                self::assertSame(0, $trips, $how);
            }
        } finally {
            $counter->close();
        }
    }

    #[Test]
    public function getters_narrow_between_cursors_and_scalars(): void
    {
        $execution = $this->connection->run('begin open :c for select 1 from dual; :n := 5; end;', [
            'c' => CursorBind::out(),
            'n' => Number::out(),
        ]);

        try {
            $execution->out('n')->cursor();
            self::fail('cursor() read a NUMBER.');
        } catch (UsageException $e) {
            self::assertSame("OUT bind 'n' holds int, not a Cursor. Read it with int().", $e->getMessage());
        }
        try {
            $execution->out('c')->int();
            self::fail('int() read a REF CURSOR.');
        } catch (UsageException $e) {
            self::assertSame("OUT bind 'c' holds Rajska\\Oracle\\Cursor, not an int. Read it with cursor().", $e->getMessage());
        }
        self::assertSame(1, self::nested($execution->out('c')->value())->value());
    }

    private static function nested(mixed $cell): Cursor
    {
        self::assertInstanceOf(Cursor::class, $cell);

        return $cell;
    }
}
