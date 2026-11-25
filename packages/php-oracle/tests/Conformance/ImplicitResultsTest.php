<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\ValueMapping;

/**
 * The Implicit results of a block (`DBMS_SQL.RETURN_RESULT`); fixtures in
 * fixtures/sql/02_cursor.sql.
 */
final class ImplicitResultsTest extends ConformanceTestCase
{
    /** fx_cursor.implicit_results: items (id, name, price), then categories (id, name); plus an OUT. */
    private const string REPORT = 'begin fx_cursor.implicit_results(:n); select count(*) into :total from fx_items; end;';

    private const string LEVELS = <<<'SQL'
        declare
          c sys_refcursor;
        begin
          open c for select level n from dual connect by level <= 25;
          dbms_sql.return_result(c);
        end;
        SQL;

    #[Test]
    public function two_implicit_results_come_in_order_next_to_an_out_value_and_read_interleaved(): void
    {
        $execution = $this->connect()->execute(self::REPORT, ['n' => 4, 'total' => Number::out()], self::options(fetchSize: 2));

        self::assertSame(['total' => [1000]], $execution->outValues());
        [$items, $categories] = $this->results($execution, 2);
        self::assertSame(['ID', 'NAME', 'PRICE'], array_column($items->columns(), 'name'));
        self::assertSame(['ID', 'NAME'], array_column($categories->columns(), 'name'));

        self::assertSame([['ID' => 1, 'NAME' => 'Plain'], ['ID' => 2, 'NAME' => 'Ação']], $categories->fetchBatch());
        self::assertSame([['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'], ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5']], $items->fetchBatch());
        self::assertSame([['ID' => 3, 'NAME' => 'Café'], ['ID' => 4, 'NAME' => 'Straße']], $categories->fetchBatch());
        self::assertSame([['ID' => 3, 'NAME' => 'Item 0003', 'PRICE' => '3.75'], ['ID' => 4, 'NAME' => 'Item 0004', 'PRICE' => 5]], $items->fetchBatch());
        self::assertSame([], $items->fetchBatch());
        self::assertSame([['ID' => 5, 'NAME' => '日本']], $categories->fetchBatch());
        self::assertSame([[], []], [$categories->fetchBatch(), $items->fetchBatch()]);
    }

    /**
     * The last one read alone, the first one dropped unread.
     */
    #[Test]
    public function each_implicit_result_reads_on_its_own(): void
    {
        $connection = $this->connect();
        $execution = $connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options());
        $categories = $this->results($execution, 2)[1];
        unset($execution);

        self::assertSame([1, 2, 3, 4, 5], array_column($this->drain($categories), 'ID'));
        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    #[Test]
    public function a_statement_with_no_return_result_gives_no_implicit_results(): void
    {
        $connection = $this->connect();

        foreach ([
            'block' => 'begin :x := 1; end;',
            'query' => 'select id from fx_categories',
            'DML' => 'update fx_categories set name = name where id = 0',
        ] as $kind => $sql) {
            $binds = $kind === 'block' ? ['x' => Number::out()] : [];
            self::assertSame([], $connection->execute($sql, $binds, self::options())->implicitResults(), $kind);
        }
    }

    /**
     * A `RAISE` after `RETURN_RESULT` throws, with no partial results: 500
     * such runs leave no server cursor open (no ORA-01000 with
     * `open_cursors` = 300).
     */
    #[Test]
    public function a_raise_after_return_result_throws(): void
    {
        $connection = $this->connect();

        for ($i = 0; $i < 500; ++$i) {
            try {
                $connection->execute(
                    "begin fx_cursor.implicit_results(3); raise_application_error(-20056, 'after the results'); end;",
                    [],
                    self::options(),
                );
                self::fail('A RAISE after RETURN_RESULT did not throw.');
            } catch (QueryException $e) {
                self::assertSame(20056, $e->getCode());
                self::assertStringStartsWith('ORA-20056: after the results', $e->getMessage());
            }
        }
        self::assertSame([1, 2, 3, 4, 5], array_column(self::rows($connection, 'select id from fx_categories order by id'), 'ID'));
    }

    #[Test]
    public function implicit_results_inherit_the_executes_options(): void
    {
        $options = self::options(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );
        $execution = $this->connect()->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 5], $options);
        [$items, $categories] = $this->results($execution, 2);

        self::assertSame(['id', 'name', 'price'], array_column($items->columns(), 'name'));
        self::assertSame([[1, 'Item 0001', 1.25], [2, 'Item 0002', 2.5], [3, 'Item 0003', 3.75]], $items->fetchBatch());
        self::assertSame([[4, 'Item 0004', 5], [5, 'Item 0005', 6.25]], $items->fetchBatch());
        self::assertSame(['id', 'name'], array_column($categories->columns(), 'name'));
        self::assertCount(3, $categories->fetchBatch());
    }

    #[Test]
    public function an_implicit_result_reads_fetch_size_rows_per_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $cursor = $this->results($connection->execute(self::LEVELS, [], self::options(fetchSize: 10)), 1)[0];
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
    public function after_close_implicit_results_fail_as_use_after_close(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$fields);
        $session = self::session($connection);
        [$items, $categories] = $this->results($connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options(fetchSize: 2)), 2);
        self::assertCount(2, $items->fetchBatch());

        $connection->close();

        foreach (['read' => $items, 'unread' => $categories] as $which => $cursor) {
            foreach (['fetchBatch()' => $cursor->fetchBatch(...), 'columns()' => $cursor->columns(...)] as $call => $read) {
                try {
                    $read();
                    self::fail("$call of the $which Implicit result after close() succeeded.");
                } catch (UsageException $e) {
                    self::assertSame('The Connection is closed.', $e->getMessage(), "$which $call");
                }
            }
            $cursor->close();
        }

        $next = $this->connect(...$fields);
        self::assertSame($session, self::session($next));
        $results = $this->results($next->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options()), 2);
        self::assertSame([1, 2, 3], array_column($this->drain($results[0]), 'ID'));
    }

    /**
     * 1000 runs with unread Implicit results raise no ORA-01000
     * (`open_cursors` is 300).
     */
    #[Test]
    public function implicit_results_dropped_unread_raise_no_ora_01000(): void
    {
        $connection = $this->connect();
        for ($i = 0; $i < 1000; ++$i) {
            $execution = $connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options());
            self::assertCount(2, $execution->implicitResults());
        }
        unset($execution);

        $results = $this->results($connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options()), 2);
        self::assertSame([1, 2, 3, 4, 5], array_column($this->drain($results[1]), 'ID'));
    }

    /**
     * @return list<DriverCursor>
     */
    private function results(DriverExecution $execution, int $count): array
    {
        $results = $execution->implicitResults();
        self::assertCount($count, $results);

        return $results;
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
