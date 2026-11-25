<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\RefCursorsTest;

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

test('a ref cursor out arrives as a driver cursor', function (): void {
    $execution = $this->connect()->execute(
        'begin open :o for select id, name, price from fx_items where id <= :n order by id; end;',
        ['o' => Cursor::out(), 'n' => 3],
        self::options(),
    );

    expect(drain(cursor($execution, 'o')))->toBe([
        ['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'],
        ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5'],
        ['ID' => 3, 'NAME' => 'Item 0003', 'PRICE' => '3.75'],
    ]);
});
test('ref cursors with a date column read in full', function (): void {
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

        expect(array_map(Temporals::shown(...), array_column(drain(cursor($items, 'o')), 'CREATED_AT')))->toBe(['+2026-01-01 00:00:00.000000 UTC', '+2026-01-01 01:00:00.000000 UTC'])
            ->and(array_column(drain(cursor($typed, 'o')), 'CREATED_AT'))->toBe(['2026-01-01 00:00:00', '2026-01-01 01:00:00'])
            ->and(array_map(static fn(array $row): array => array_map(Temporals::shown(...), $row), drain(cursor($two, 'items'))))->toBe([['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25', 'CREATED_AT' => '+2026-01-01 00:00:00.000000 UTC']]);
    });
});
test('a ref cursor in out is opened by the server', function (): void {
    $execution = $this->connect()->execute(
        'begin open :io for select name from fx_categories where id <= 2 order by id; end;',
        ['io' => Cursor::inOut()],
        self::options(),
    );

    expect(drain(cursor($execution, 'io')))->toBe([['NAME' => 'Plain'], ['NAME' => 'Ação']]);
});
test('a ref cursor function return arrives as a driver cursor', function (): void {
    $execution = $this->connect()->execute(
        'begin :o := fx_cursor.categories_with_items; end;',
        ['o' => Cursor::out()],
        self::options(rowShape: RowShape::List),
    );

    $cursor = cursor($execution, 'o');
    expect(array_column($cursor->columns(), 'name'))->toBe(['ID', 'NAME', 'ITEMS'])
        ->and(array_column($cursor->columns(), 'type'))->toBe(['number', 'varchar', 'cursor']);
});
test('an unopened ref cursor fails on its first read', function (): void {
    $connection = $this->connect();
    $execution = $connection->execute('declare c sys_refcursor; begin :o := c; end;', ['o' => Cursor::out()], self::options());
    $cursor = cursor($execution, 'o');

    try {
        $cursor->fetchBatch();
        self::fail('An unopened REF CURSOR was read.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(1001)
            ->and($queryException->getMessage())->toStartWith('ORA-01001');
    }
    expect(self::value($connection, 'select 1 from dual'))->toBe(1);
});
test('an empty ref cursor describes its columns', function (): void {
    $execution = $this->connect()->execute(
        'begin open :o for select id, name from fx_categories where 1 = 0; end;',
        ['o' => Cursor::out()],
        self::options(),
    );
    $cursor = cursor($execution, 'o');

    expect($cursor->columns())->toBe([
        ['name' => 'ID', 'type' => 'number', 'nullable' => false, 'precision' => 10, 'scale' => 0, 'maxSize' => null, 'objectType' => null, 'vectorDimensions' => null, 'vectorFormat' => null, 'sparse' => false],
        ['name' => 'NAME', 'type' => 'varchar', 'nullable' => false, 'precision' => null, 'scale' => null, 'maxSize' => 50, 'objectType' => null, 'vectorDimensions' => null, 'vectorFormat' => null, 'sparse' => false],
    ])
        ->and([$cursor->fetchBatch(), $cursor->fetchBatch()])->toBe([[], []]);
});
test('every nested cursor reads after the parent is read to its end', function (): void {
    $nested = <<<'SQL'
        select c.id, c.name,
               cursor(select i.id, i.price from fx_items i
                       where i.category_id = c.id and i.id <= 20 order by i.id) as items
          from fx_categories c
         order by c.id
        SQL;

    $parent = $this->connect()->execute($nested, [], self::options(fetchSize: 2))->cursor();
    expect($parent)->not->toBeNull();
    $rows = drain($parent);

    expect(array_column($rows, 'ID'))->toBe([1, 2, 3, 4, 5]);
    $items = [];
    foreach ($rows as $row) {
        $name = $row['NAME'];
        expect($name)->toBeString()
            ->and($row['ITEMS'])->toBeInstanceOf(DriverCursor::class);
        $items[$name] = array_column(drain($row['ITEMS']), 'ID');
    }
    expect($items)->toBe(['Plain' => [1, 6, 11, 16], 'Ação' => [2, 7, 12, 17], 'Café' => [3, 8, 13, 18], 'Straße' => [4, 9, 14, 19], '日本' => [5, 10, 15, 20]]);
});
test('a nested cursor fails after its parents close', function (): void {
    $nested = <<<'SQL'
            select c.id, c.name,
                   cursor(select i.id, i.price from fx_items i
                           where i.category_id = c.id and i.id <= 20 order by i.id) as items
              from fx_categories c
             order by c.id
            SQL;

    $connection = $this->connect();
    $parent = $connection->execute($nested, [], self::options())->cursor();
    expect($parent)->not->toBeNull();
    [$first, $second] = array_column($parent->fetchBatch(), 'ITEMS');
    expect($first)->toBeInstanceOf(DriverCursor::class)
        ->and($second)->toBeInstanceOf(DriverCursor::class)
        ->and(\array_slice($first->fetchBatch(), 0, 1))->toBe([['ID' => 1, 'PRICE' => '1.25']]);

    $parent->close();

    foreach (['read' => $first, 'unread' => $second] as $which => $child) {
        foreach (['fetchBatch()' => $child->fetchBatch(...), 'columns()' => $child->columns(...)] as $call => $read) {
            expect($read)->toThrow(function (UsageException $usageException) use ($nested, $call, $which): void {
                expect($usageException->getMessage())->toBe('The Cursor this one is nested in is closed, which closed this one too.', "$which $call")
                    ->and($usageException->sql())->toBe($nested);
            });
        }
        $child->close();
    }
    expect(self::value($connection, 'select 1 from dual'))->toBe(1);
});
test('a nested cursor keeps its parent open after the parent object is dropped', function (): void {
    $nested = <<<'SQL'
        select c.id, c.name,
               cursor(select i.id, i.price from fx_items i
                       where i.category_id = c.id and i.id <= 20 order by i.id) as items
          from fx_categories c
         order by c.id
        SQL;

    $connection = $this->connect(...self::ownPool() + ['statementCacheSize' => 0]);
    $parent = $connection->execute($nested, [], self::options())->cursor();
    expect($parent)->not->toBeNull();
    $children = array_column($parent->fetchBatch(), 'ITEMS', 'ID');
    unset($parent);

    // A Round trip that would carry the parent's close.
    expect(self::value($connection, 'select 1 from dual'))->toBe(1);

    $child = $children[3];
    expect($child)->toBeInstanceOf(DriverCursor::class)
        ->and(array_column(drain($child), 'ID'))->toBe([3, 8, 13, 18]);
});
test('ref cursors and nested cursors inherit the executes options', function (): void {
    $options = self::options(
        fetchSize: 3,
        values: new ValueMapping(numbers: NumberMode::Float),
        rowShape: RowShape::List,
        keyCase: KeyCase::Lower,
    );
    $execution = $this->connect()->execute('begin :o := fx_cursor.categories_with_items; end;', ['o' => Cursor::out()], $options);
    $categories = cursor($execution, 'o');

    expect(array_column($categories->columns(), 'name'))->toBe(['id', 'name', 'items']);
    $batch = $categories->fetchBatch();
    expect($batch)->toHaveCount(3)
        ->and(\array_slice($batch[0], 0, 2))->toBe([1, 'Plain']);

    $items = $batch[0][2];
    expect($items)->toBeInstanceOf(DriverCursor::class)
        ->and(array_column($items->columns(), 'name'))->toBe(['id', 'name', 'price'])
        ->and($items->fetchBatch())->toBe([[1, 'Item 0001', 1.25], [6, 'Item 0006', 7.5], [11, 'Item 0011', 13.75]])
        ->and($items->fetchBatch())->toBe([[16, 'Item 0016', 20]])
        ->and($items->fetchBatch())->toBe([])
        ->and($categories->fetchBatch())->toHaveCount(2);
});
test('a ref cursor reads fetch size rows per round trip', function (): void {
    $connection = $this->connect();
    $sid = self::sid($connection);
    $execution = $connection->execute(
        'begin open :o for select level n from dual connect by level <= 25; end;',
        ['o' => Cursor::out()],
        self::options(fetchSize: 10),
    );
    $cursor = cursor($execution, 'o');
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
test('after close ref cursors and nested cursors fail as use after close', function (): void {
    $fields = self::ownPool() + ['maxConnections' => 1];
    $connection = $this->connect(...$fields);
    $session = self::session($connection);
    $execution = $connection->execute('begin :o := fx_cursor.categories_with_items; end;', ['o' => Cursor::out()], self::options());
    $categories = cursor($execution, 'o');
    $items = $categories->fetchBatch()[0]['ITEMS'];
    expect($items)->toBeInstanceOf(DriverCursor::class);

    $connection->close();

    foreach (['REF CURSOR' => $categories, 'nested Cursor' => $items] as $kind => $cursor) {
        foreach (['fetchBatch()' => $cursor->fetchBatch(...), 'columns()' => $cursor->columns(...)] as $call => $read) {
            try {
                $read();
                self::fail("$call of a $kind after close() succeeded.");
            } catch (UsageException $e) {
                expect($e->getMessage())->toBe('The Connection is closed.', "$kind $call");
            }
        }
        $cursor->close();
    }

    $next = $this->connect(...$fields);
    expect(self::session($next))->toBe($session);
    $execution = $next->execute('begin :o := fx_cursor.categories_with_items; end;', ['o' => Cursor::out()], self::options());
    expect(array_column(drain(cursor($execution, 'o')), 'ID'))->toBe([1, 2, 3, 4, 5]);
});
test('cursors dropped unread raise no ora 01000', function (): void {
    $nested = <<<'SQL'
        select c.id, c.name,
               cursor(select i.id, i.price from fx_items i
                       where i.category_id = c.id and i.id <= 20 order by i.id) as items
          from fx_categories c
         order by c.id
        SQL;

    $connection = $this->connect();
    for ($i = 0; $i < 1000; ++$i) {
        $parent = $connection->execute($nested, [], self::options())->cursor();
        expect($parent)->not->toBeNull()
            ->and($parent->fetchBatch())->toHaveCount(5);
        $execution = $connection->execute('begin open :o for select id from fx_categories; end;', ['o' => Cursor::out()], self::options());
        expect($execution->outValues()['o'][0])->toBeInstanceOf(DriverCursor::class);
    }
    unset($parent, $execution);

    $parent = $connection->execute($nested, [], self::options())->cursor();
    expect($parent)->not->toBeNull();
    $items = $parent->fetchBatch()[4]['ITEMS'];
    expect($items)->toBeInstanceOf(DriverCursor::class)
        ->and(array_column(drain($items), 'ID'))->toBe([5, 10, 15, 20]);
});
/**
 * The one REF CURSOR in an OUT value list.
 */
function cursor(DriverExecution $execution, string $name): DriverCursor
{
    $values = $execution->outValues()[$name] ?? null;
    expect($values)->toBeArray()
        ->toHaveCount(1)
        ->and($values[0])->toBeInstanceOf(DriverCursor::class);

    return $values[0];
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
