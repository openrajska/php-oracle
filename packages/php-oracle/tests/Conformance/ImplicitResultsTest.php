<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ImplicitResultsTest;

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

test('two implicit results come in order next to an out value and read interleaved', function (): void {
    $report = 'begin fx_cursor.implicit_results(:n); select count(*) into :total from fx_items; end;';

    $execution = $this->connect()->execute($report, ['n' => 4, 'total' => Number::out()], self::options(fetchSize: 2));

    expect($execution->outValues())->toBe(['total' => [1000]]);
    [$items, $categories] = results($execution, 2);
    expect(array_column($items->columns(), 'name'))->toBe(['ID', 'NAME', 'PRICE'])
        ->and(array_column($categories->columns(), 'name'))->toBe(['ID', 'NAME'])
        ->and($categories->fetchBatch())->toBe([['ID' => 1, 'NAME' => 'Plain'], ['ID' => 2, 'NAME' => 'Ação']])
        ->and($items->fetchBatch())->toBe([['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'], ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5']])
        ->and($categories->fetchBatch())->toBe([['ID' => 3, 'NAME' => 'Café'], ['ID' => 4, 'NAME' => 'Straße']])
        ->and($items->fetchBatch())->toBe([['ID' => 3, 'NAME' => 'Item 0003', 'PRICE' => '3.75'], ['ID' => 4, 'NAME' => 'Item 0004', 'PRICE' => 5]])
        ->and($items->fetchBatch())->toBeEmpty()
        ->and($categories->fetchBatch())->toBe([['ID' => 5, 'NAME' => '日本']])
        ->and([$categories->fetchBatch(), $items->fetchBatch()])->toBe([[], []]);
});
test('each implicit result reads on its own', function (): void {
    $connection = $this->connect();
    $execution = $connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options());
    $categories = results($execution, 2)[1];
    unset($execution);

    expect(array_column(drain($categories), 'ID'))->toBe([1, 2, 3, 4, 5])
        ->and(self::value($connection, 'select 1 from dual'))->toBe(1);
});
test('a statement with no return result gives no implicit results', function (): void {
    $connection = $this->connect();

    foreach ([
        'block' => 'begin :x := 1; end;',
        'query' => 'select id from fx_categories',
        'DML' => 'update fx_categories set name = name where id = 0',
    ] as $kind => $sql) {
        $binds = $kind === 'block' ? ['x' => Number::out()] : [];
        expect($connection->execute($sql, $binds, self::options())->implicitResults())->toBe([], $kind);
    }
});
test('a raise after return result throws', function (): void {
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
            expect($e->getCode())->toBe(20056)
                ->and($e->getMessage())->toStartWith('ORA-20056: after the results');
        }
    }
    expect(array_column(self::rows($connection, 'select id from fx_categories order by id'), 'ID'))->toBe([1, 2, 3, 4, 5]);
});
test('implicit results inherit the executes options', function (): void {
    $options = self::options(
        fetchSize: 3,
        values: new ValueMapping(numbers: NumberMode::Float),
        rowShape: RowShape::List,
        keyCase: KeyCase::Lower,
    );
    $execution = $this->connect()->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 5], $options);
    [$items, $categories] = results($execution, 2);

    expect(array_column($items->columns(), 'name'))->toBe(['id', 'name', 'price'])
        ->and($items->fetchBatch())->toBe([[1, 'Item 0001', 1.25], [2, 'Item 0002', 2.5], [3, 'Item 0003', 3.75]])
        ->and($items->fetchBatch())->toBe([[4, 'Item 0004', 5], [5, 'Item 0005', 6.25]])
        ->and(array_column($categories->columns(), 'name'))->toBe(['id', 'name'])
        ->and($categories->fetchBatch())->toHaveCount(3);
});
test('an implicit result reads fetch size rows per round trip', function (): void {
    $levels = <<<'SQL'
            declare
              c sys_refcursor;
            begin
              open c for select level n from dual connect by level <= 25;
              dbms_sql.return_result(c);
            end;
            SQL;

    $connection = $this->connect();
    $sid = self::sid($connection);
    $cursor = results($connection->execute($levels, [], self::options(fetchSize: 10)), 1)[0];
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

    expect($sizes)->toBe([10, 10, 5])
        ->and($trips)->toBe(3);
});
test('after close implicit results fail as use after close', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $connection = $this->connect(...$fields);
    $session = self::session($connection);
    [$items, $categories] = results($connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options(fetchSize: 2)), 2);
    expect($items->fetchBatch())->toHaveCount(2);

    $connection->close();

    foreach (['read' => $items, 'unread' => $categories] as $which => $cursor) {
        foreach (['fetchBatch()' => $cursor->fetchBatch(...), 'columns()' => $cursor->columns(...)] as $call => $read) {
            try {
                $read();
                self::fail("$call of the $which Implicit result after close() succeeded.");
            } catch (UsageException $e) {
                expect($e->getMessage())->toBe('The Connection is closed.', "$which $call");
            }
        }
        $cursor->close();
    }

    $next = $this->connect(...$fields);
    expect(self::session($next))->toBe($session);
    $results = results($next->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options()), 2);
    expect(array_column(drain($results[0]), 'ID'))->toBe([1, 2, 3]);
});
test('implicit results dropped unread raise no ora 01000', function (): void {
    $connection = $this->connect();
    for ($i = 0; $i < 1000; ++$i) {
        $execution = $connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options());
        expect($execution->implicitResults())->toHaveCount(2);
    }
    unset($execution);

    $results = results($connection->execute('begin fx_cursor.implicit_results(:n); end;', ['n' => 3], self::options()), 2);
    expect(array_column(drain($results[1]), 'ID'))->toBe([1, 2, 3, 4, 5]);
});
/**
 * @return list<DriverCursor>
 */
function results(DriverExecution $execution, int $count): array
{
    $results = $execution->implicitResults();
    expect($results)->toHaveCount($count);

    return $results;
}
/**
 * @return list<array<int|string, mixed>>
 */
function drain(DriverCursor $cursor): array
{
    $rows = [];
    while (($batch = $cursor->fetchBatch()) !== []) {
        array_push($rows, ...$batch);
    }

    return $rows;
}
