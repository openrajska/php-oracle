<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\RefCursorsTest;

use Rajska\Oracle\Bind\Cursor as CursorBind;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Column;
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

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});
afterEach(function (): void {
    $this->connection->close();
});
test('a procedure gives its ref cursors as cursors read interleaved', function (): void {
    $execution = $this->connection->procedure('fx_cursor.report', [
        'p_limit' => 250,
        'io_label' => Varchar::inOut('total'),
        'o_items' => CursorBind::out(),
        'o_categories' => CursorBind::out(),
        'o_total' => Number::out(),
        'io_names' => CursorBind::inOut(),
    ]);

    expect($execution->out('io_label')->string())->toBe('total!')
        ->and($execution->out('o_total')->int())->toBe(1000);

    $items = $execution->out('o_items')->cursor();
    $categories = $execution->out('O_CATEGORIES')->cursor();
    expect($execution->out('o_items')->cursor())->toBe($items);

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
    expect($ids)->toBe(range(1, 250))
        ->and($names)->toBe(['Plain', 'Ação', 'Café', 'Straße', '日本'])
        ->and($execution->out('io_names')->cursor()->all())->toBe([['NAME' => 'Plain'], ['NAME' => 'Ação']]);
});
test('the cursor terminal gives a functions ref cursor', function (): void {
    $categories = $this->connection->function('fx_cursor.categories_with_items')->cursor();

    $items = [];
    foreach ($categories as $category) {
        $name = $category['NAME'];
        expect($name)->toBeString();
        $items[$name] = array_column(nested($category['ITEMS'])->all(), 'ID');
    }
    expect($items)->toBe(['Plain' => [1, 6, 11, 16], 'Ação' => [2, 7, 12, 17], 'Café' => [3, 8, 13, 18], 'Straße' => [4, 9, 14, 19], '日本' => [5, 10, 15, 20]]);
});
test('every nested cursor reads after the parents all', function (): void {
    $nested = <<<'SQL'
            select c.id, c.name,
                   cursor(select i.id, i.price from fx_items i
                           where i.category_id = c.id and i.id <= 20 order by i.id) as items
              from fx_categories c
             order by c.id
            SQL;

    $rows = $this->connection->select($nested, options: new ExecuteOptions(fetchSize: 2))->all();

    expect(array_column($rows, 'ID'))->toBe([1, 2, 3, 4, 5])
        ->and(array_map(static fn(array $row): array => array_column(nested($row['ITEMS'])->all(), 'ID'), $rows))->toBe([[1, 6, 11, 16], [2, 7, 12, 17], [3, 8, 13, 18], [4, 9, 14, 19], [5, 10, 15, 20]]);
});
test('a nested cursor fails after its parents close', function (): void {
    $nested = <<<'SQL'
            select c.id, c.name,
                   cursor(select i.id, i.price from fx_items i
                           where i.category_id = c.id and i.id <= 20 order by i.id) as items
              from fx_categories c
             order by c.id
            SQL;

    $parent = $this->connection->select($nested);
    $children = array_column($parent->all(), 'ITEMS');
    [$started, $unread] = array_map(nested(...), $children);
    expect($started->getIterator()->current())->toBe(['ID' => 1, 'PRICE' => '1.25']);

    $parent->close();

    foreach (['started' => $started, 'unread' => $unread] as $which => $child) {
        try {
            $child->all();
            self::fail("The $which child read after its parent's close().");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Cursor this one is nested in is closed, which closed this one too.', $which);
        }
    }

    $first = $this->connection->select($nested)->first();
    expect($first)->not->toBeNull()
        ->and(fn(): array => nested($first['ITEMS'])->all())->toThrow(UsageException::class);
});
test('ref cursors and nested cursors inherit the executions options', function (): void {
    $options = new ExecuteOptions(
        fetchSize: 3,
        values: new ValueMapping(numbers: NumberMode::Float),
        rowShape: RowShape::List,
        keyCase: KeyCase::Lower,
    );
    $categories = $this->connection->function('fx_cursor.categories_with_items', options: $options)->cursor();

    expect(array_map(static fn(Column $c): string => $c->name, $categories->columns()))->toBe(['id', 'name', 'items'])
        ->and($categories->columns()[2]->type)->toBe(Type::Cursor);
    [$id, $name, $items] = $categories->getIterator()->current();
    expect([$id, $name])->toBe([1, 'Plain'])
        ->and($items)->toBeInstanceOf(Cursor::class)
        ->and($items->all())->toBe([[1, 'Item 0001', 1.25], [6, 'Item 0006', 7.5], [11, 'Item 0011', 13.75], [16, 'Item 0016', 20]]);
});
test('a nested cursor reads with the executions options', function (): void {
    $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
    expect($sid)->toBeString();
    $options = new ExecuteOptions(fetchSize: 3, values: new ValueMapping(numbers: NumberMode::Float), keyCase: KeyCase::Lower);
    $parent = $this->connection->select(
        'select cursor(select level / 2 as half from dual connect by level <= 7) as halves from dual',
        options: $options,
    );
    $halves = $parent->all()[0]['halves'];
    expect($halves)->toBeInstanceOf(Cursor::class);
    $counter = RoundTripCounter::open();

    try {
        $rows = [];
        $trips = $counter->during((int) $sid, static function () use ($halves, &$rows): void {
            $rows = $halves->all();
        });
    } finally {
        $counter->close();
    }

    expect($rows)->toBe([['half' => 0.5], ['half' => 1], ['half' => 1.5], ['half' => 2], ['half' => 2.5], ['half' => 3], ['half' => 3.5]])
        ->and($trips)->toBe(3);
});
test('after close ref cursors and nested cursors fail as use after close', function (): void {
    $categories = $this->connection->function('fx_cursor.categories_with_items')->cursor();
    $iterator = $categories->getIterator();
    $items = $iterator->current()['ITEMS'];
    expect($items)->toBeInstanceOf(Cursor::class);

    $this->connection->close();

    foreach (['REF CURSOR' => $categories, 'nested Cursor' => $items] as $kind => $cursor) {
        try {
            $cursor->all();
            self::fail("A $kind read after close().");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Connection is closed.', $kind);
        }
    }
});
test('an unopened ref cursor fails on its first read', function (): void {
    $cursor = $this->connection->run('declare c sys_refcursor; begin :o := c; end;', ['o' => CursorBind::out()])->out('o')->cursor();

    try {
        $cursor->all();
        self::fail('An unopened REF CURSOR was read.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(1001);
    }
});
test('a cursor passed back as in fails loudly', function (): void {
    $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
    expect($sid)->toBeString();
    $cursor = $this->connection->function('fx_cursor.categories_with_items')->cursor();
    $counter = RoundTripCounter::open();

    try {
        foreach (['a plain value' => $cursor, 'Cursor::inOut()' => CursorBind::inOut($cursor)] as $how => $bind) {
            $trips = $counter->during((int) $sid, function () use ($bind, $how): void {
                try {
                    $this->connection->run('declare c sys_refcursor := :c; begin null; end;', ['c' => $bind]);
                    self::fail("A Cursor bound back as $how.");
                } catch (UnsupportedException $unsupportedException) {
                    expect($unsupportedException->getMessage())->toContain("Bind 'c': passing a Cursor back to the server is not supported yet");
                }
            });
            expect($trips)->toBe(0, $how);
        }
    } finally {
        $counter->close();
    }
});
test('getters narrow between cursors and scalars', function (): void {
    $execution = $this->connection->run('begin open :c for select 1 from dual; :n := 5; end;', [
        'c' => CursorBind::out(),
        'n' => Number::out(),
    ]);
    expect(function () use ($execution): void {
        $execution->out('n')->cursor();
        self::fail('cursor() read a NUMBER.');
    })->toThrow(UsageException::class, "OUT bind 'n' holds int, not a Cursor. Read it with int().")
        ->and(function () use ($execution): void {
            $execution->out('c')->int();
            self::fail('int() read a REF CURSOR.');
        })->toThrow(UsageException::class, "OUT bind 'c' holds Rajska\\Oracle\\Cursor, not an int. Read it with cursor().")
        ->and(nested($execution->out('c')->value())->value())->toBe(1);
});
function nested(mixed $cell): Cursor
{
    expect($cell)->toBeInstanceOf(Cursor::class);

    return $cell;
}
