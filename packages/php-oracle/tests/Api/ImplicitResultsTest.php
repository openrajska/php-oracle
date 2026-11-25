<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Column;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Cursor;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Execution;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;

/**
 * The Implicit results of a block (`DBMS_SQL.RETURN_RESULT`) as
 * `Execution::results()`: a list of lazy Cursors in production order
 * (fixtures/sql/02_cursor.sql).
 */
final class ImplicitResultsTest extends TestCase
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
    public function a_blocks_implicit_results_come_in_order_next_to_an_out_value(): void
    {
        $execution = $this->connection->run(
            'begin fx_cursor.implicit_results(:n); select count(*) into :total from fx_items; end;',
            ['n' => 3, 'total' => Number::out()],
            new ExecuteOptions(fetchSize: 2),
        );

        self::assertSame(1000, $execution->out('total')->int());
        [$items, $categories] = $this->results($execution, 2);
        $itemRows = $items->getIterator();
        $read = [];
        foreach ($categories as $category) {
            $read[] = $category['NAME'];
            if ($itemRows->valid()) {
                $read[] = $itemRows->current()['NAME'];
                $itemRows->next();
            }
        }
        self::assertSame(['Plain', 'Item 0001', 'Ação', 'Item 0002', 'Café', 'Item 0003', 'Straße', '日本'], $read);
        self::assertSame([], $items->all());
    }

    #[Test]
    public function a_procedures_implicit_results_read_in_any_order(): void
    {
        $execution = $this->connection->procedure('fx_cursor.implicit_results', ['p_limit' => 2]);

        $results = $this->results($execution, 2);
        self::assertSame($results, $execution->results());
        self::assertSame([['ID' => 1, 'NAME' => 'Plain'], ['ID' => 2, 'NAME' => 'Ação']], \array_slice($results[1]->all(), 0, 2));
        self::assertSame([['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'], ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5']], $results[0]->all());
    }

    #[Test]
    public function a_statement_with_no_return_result_gives_no_results(): void
    {
        self::assertSame([], $this->connection->run('begin null; end;')->results());
        self::assertSame([], $this->connection->run('select 1 from dual')->results());
        self::assertSame([], $this->connection->run('update fx_categories set name = name where id = 0')->results());
    }

    /**
     * So there are no partial results.
     */
    #[Test]
    public function a_raise_after_return_result_throws(): void
    {
        try {
            $this->connection->run("begin fx_cursor.implicit_results(3); raise_application_error(-20056, 'after the results'); end;");
            self::fail('A RAISE after RETURN_RESULT did not throw.');
        } catch (QueryException $queryException) {
            self::assertSame(20056, $queryException->getCode());
        }
        self::assertSame(1, $this->connection->select('select 1 from dual')->value());
    }

    #[Test]
    public function implicit_results_inherit_the_executions_options(): void
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);
        $options = new ExecuteOptions(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );
        [$items] = $this->results($this->connection->procedure('fx_cursor.implicit_results', ['p_limit' => 7], $options), 2);
        self::assertSame(['id', 'name', 'price'], array_map(static fn(Column $column): string => $column->name, $items->columns()));
        $counter = RoundTripCounter::open();

        try {
            $rows = [];
            $trips = $counter->during((int) $sid, static function () use ($items, &$rows): void {
                $rows = $items->all();
            });
        } finally {
            $counter->close();
        }

        self::assertSame(
            [[1, 'Item 0001', 1.25], [2, 'Item 0002', 2.5], [3, 'Item 0003', 3.75], [4, 'Item 0004', 5], [5, 'Item 0005', 6.25], [6, 'Item 0006', 7.5], [7, 'Item 0007', 8.75]],
            $rows,
        );
        self::assertSame(3, $trips);
    }

    /**
     * Also with rows in hand.
     */
    #[Test]
    public function after_close_implicit_results_fail_as_use_after_close(): void
    {
        [$items, $categories] = $this->results($this->connection->procedure('fx_cursor.implicit_results', ['p_limit' => 5], new ExecuteOptions(fetchSize: 2)), 2);
        $iterator = $items->getIterator();
        self::assertSame(1, $iterator->current()['ID']);

        $this->connection->close();

        foreach (['read' => $items, 'unread' => $categories] as $which => $cursor) {
            try {
                $cursor->all();
                self::fail("The $which Implicit result read after close().");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage(), $which);
            }
        }
    }

    /**
     * @return list<Cursor>
     */
    private function results(Execution $execution, int $count): array
    {
        $results = $execution->results();
        self::assertCount($count, $results);

        return $results;
    }
}
