<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\ImplicitResultsTest;

use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Column;
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

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});
afterEach(function (): void {
    $this->connection->close();
});
test('a blocks implicit results come in order next to an out value', function (): void {
    $execution = $this->connection->run(
        'begin fx_cursor.implicit_results(:n); select count(*) into :total from fx_items; end;',
        ['n' => 3, 'total' => Number::out()],
        new ExecuteOptions(fetchSize: 2),
    );

    expect($execution->out('total')->int())->toBe(1000);
    [$items, $categories] = results($execution, 2);
    $itemRows = $items->getIterator();
    $read = [];
    foreach ($categories as $category) {
        $read[] = $category['NAME'];
        if ($itemRows->valid()) {
            $read[] = $itemRows->current()['NAME'];
            $itemRows->next();
        }
    }
    expect($read)->toBe(['Plain', 'Item 0001', 'Ação', 'Item 0002', 'Café', 'Item 0003', 'Straße', '日本'])
        ->and($items->all())->toBeEmpty();
});
test('a procedures implicit results read in any order', function (): void {
    $execution = $this->connection->procedure('fx_cursor.implicit_results', ['p_limit' => 2]);

    $results = results($execution, 2);
    expect($execution->results())->toBe($results)
        ->and(\array_slice($results[1]->all(), 0, 2))->toBe([['ID' => 1, 'NAME' => 'Plain'], ['ID' => 2, 'NAME' => 'Ação']])
        ->and($results[0]->all())->toBe([['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'], ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5']]);
});
test('a statement with no return result gives no results', function (): void {
    expect($this->connection->run('begin null; end;')->results())->toBe([])
        ->and($this->connection->run('select 1 from dual')->results())->toBe([])
        ->and($this->connection->run('update fx_categories set name = name where id = 0')->results())->toBe([]);
});
test('a raise after return result throws', function (): void {
    try {
        $this->connection->run("begin fx_cursor.implicit_results(3); raise_application_error(-20056, 'after the results'); end;");
        self::fail('A RAISE after RETURN_RESULT did not throw.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(20056);
    }
    expect($this->connection->select('select 1 from dual')->value())->toBe(1);
});
test('implicit results inherit the executions options', function (): void {
    $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
    expect($sid)->toBeString();
    $options = new ExecuteOptions(
        fetchSize: 3,
        values: new ValueMapping(numbers: NumberMode::Float),
        rowShape: RowShape::List,
        keyCase: KeyCase::Lower,
    );
    [$items] = results($this->connection->procedure('fx_cursor.implicit_results', ['p_limit' => 7], $options), 2);
    expect(array_map(static fn(Column $column): string => $column->name, $items->columns()))->toBe(['id', 'name', 'price']);
    $counter = RoundTripCounter::open();

    try {
        $rows = [];
        $trips = $counter->during((int) $sid, static function () use ($items, &$rows): void {
            $rows = $items->all();
        });
    } finally {
        $counter->close();
    }

    expect($rows)->toBe([[1, 'Item 0001', 1.25], [2, 'Item 0002', 2.5], [3, 'Item 0003', 3.75], [4, 'Item 0004', 5], [5, 'Item 0005', 6.25], [6, 'Item 0006', 7.5], [7, 'Item 0007', 8.75]])
        ->and($trips)->toBe(3);
});
test('after close implicit results fail as use after close', function (): void {
    [$items, $categories] = results($this->connection->procedure('fx_cursor.implicit_results', ['p_limit' => 5], new ExecuteOptions(fetchSize: 2)), 2);
    $iterator = $items->getIterator();
    expect($iterator->current()['ID'])->toBe(1);

    $this->connection->close();

    foreach (['read' => $items, 'unread' => $categories] as $which => $cursor) {
        try {
            $cursor->all();
            self::fail("The $which Implicit result read after close().");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Connection is closed.', $which);
        }
    }
});
/**
 * @return list<Cursor>
 */
function results(Execution $execution, int $count): array
{
    $results = $execution->results();
    expect($results)->toHaveCount($count);

    return $results;
}
