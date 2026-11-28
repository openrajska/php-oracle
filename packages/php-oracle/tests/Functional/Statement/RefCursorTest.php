<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Statement\RefCursorTest;

use Closure;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\DataType\Type;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\Column;
use Rajska\Oracle\Statement\Cursor;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table ref_categories (id number(10) primary key, name varchar2(50) not null)');
    schema()->run(<<<'SQL'
        create table ref_items (
            id number(10) primary key,
            category_id number(10) not null references ref_categories,
            name varchar2(100) not null,
            price number(10, 2) not null
        )
        SQL);
    schema()->run(<<<'SQL'
        insert into ref_categories (id, name)
        select 1, 'Plain' from dual union all
        select 2, 'Ação' from dual union all
        select 3, 'Café' from dual union all
        select 4, 'Straße' from dual union all
        select 5, '日本' from dual
        SQL);
    schema()->run(<<<'SQL'
        insert into ref_items (id, category_id, name, price)
        select level, mod(level - 1, 5) + 1, 'Item ' || lpad(level, 4, '0'), level * 1.25
          from dual
        connect by level <= 250
        SQL);
    schema()->run(<<<'SQL'
        create package ref_cursors as
            -- io_label gains a '!'; io_names holds the names of categories 1 and 2.
            procedure report(
                p_limit number,
                io_label in out varchar2,
                o_items out sys_refcursor,
                o_categories out sys_refcursor,
                o_total out number,
                io_names in out sys_refcursor
            );

            -- Each category with a CURSOR() column of its first 4 items.
            function categories_with_items return sys_refcursor;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body ref_cursors as
            procedure report(
                p_limit number,
                io_label in out varchar2,
                o_items out sys_refcursor,
                o_categories out sys_refcursor,
                o_total out number,
                io_names in out sys_refcursor
            ) is
            begin
                io_label := io_label || '!';
                open o_items for select id, name, price from ref_items where id <= p_limit order by id;
                open o_categories for select id, name from ref_categories order by id;
                select count(*) into o_total from ref_items;
                open io_names for select name from ref_categories where id <= 2 order by id;
            end;

            function categories_with_items return sys_refcursor is
                l_cursor sys_refcursor;
            begin
                open l_cursor for
                    select c.id, c.name,
                           cursor(select i.id, i.name, i.price from ref_items i
                                   where i.category_id = c.id and i.id <= 20 order by i.id) as items
                      from ref_categories c
                     order by c.id;
                return l_cursor;
            end;
        end;
        SQL);

    $this->connection = $this->connect();
});

/**
 * @return iterable<string, array{Closure(Cursor): mixed}>
 */
dataset('cursorsBoundBack', function () {
    yield 'as a plain value' => [static fn (Cursor $cursor): Cursor => $cursor];
    yield 'through InOut\Cursor' => [static fn (Cursor $cursor): InOut\Cursor => new InOut\Cursor($cursor)];
});

/**
 * @return iterable<string, array{Closure(Execution): mixed, string}>
 */
dataset('cursorGetterMistakes', function () {
    yield 'cursor() of a NUMBER' => [
        static fn (Execution $execution): Cursor => $execution->out('n')->cursor(),
        "OUT bind 'n' holds int, not a Cursor. Read it with int().",
    ];
    yield 'int() of a REF CURSOR' => [
        static fn (Execution $execution): int | null => $execution->out('c')->int(),
        "OUT bind 'c' holds Rajska\\Oracle\\Statement\\Cursor, not an int. Read it with cursor().",
    ];
});

describe('ref cursors', function (): void {
    it('gives the ref cursors of a procedure as cursors next to its scalars', function (): void {
        $execution = report($this->connection);

        $items = $execution->out('o_items')->cursor();

        expect($execution->out('o_items')->cursor())->toBe($items)
            ->and($execution->out('io_label')->string())->toBe('total!')
            ->and($execution->out('o_total')->int())->toBe(250)
            ->and($execution->out('io_names')->cursor()->all())->toBe([['NAME' => 'Plain'], ['NAME' => 'Ação']]);
    });

    it('reads the ref cursors of one call interleaved', function (): void {
        $execution = report($this->connection);
        $items = $execution->out('o_items')->cursor();
        $categories = $execution->out('O_CATEGORIES')->cursor();
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
            ->and($names)->toBe(['Plain', 'Ação', 'Café', 'Straße', '日本']);
    });

    it('gives the ref cursor of a function as a cursor', function (): void {
        $categories = $this->connection->function('ref_cursors.categories_with_items')->cursor();

        $items = [];
        foreach ($categories as $category) {
            $name = $category['NAME'];
            assert(\is_string($name));
            $items[$name] = array_column(nested($category['ITEMS'])->all(), 'ID');
        }

        expect($items)->toBe(['Plain' => [1, 6, 11, 16], 'Ação' => [2, 7, 12, 17], 'Café' => [3, 8, 13, 18], 'Straße' => [4, 9, 14, 19], '日本' => [5, 10, 15, 20]]);
    });

    it('reads a ref cursor out value as a cursor through value', function (): void {
        $execution = $this->connection->run('begin open :c for select 1 from dual; end;', ['c' => new Out\Cursor()]);

        $cursor = $execution->out('c')->value();

        expect(nested($cursor)->value())->toBe(1);
    });

    it('names the right getter when read with the wrong one', function (Closure $read, string $message): void {
        $execution = $this->connection->run('begin open :c for select 1 from dual; :n := 5; end;', [
            'c' => new Out\Cursor(),
            'n' => new Out\Number(),
        ]);

        $call = static fn (): mixed => $read($execution);

        expect($call)->toThrow(UsageException::class, $message);
    })->with('cursorGetterMistakes');

    it('fails before any round trip when a cursor is bound back to the server', function (Closure $bind): void {
        $sid = $this->sid($this->connection);
        $cursor = $this->connection->function('ref_cursors.categories_with_items')->cursor();

        $trips = roundTrips($sid, function () use ($bind, $cursor): void {
            expect(fn () => $this->connection->run('declare c sys_refcursor := :c; begin null; end;', ['c' => $bind($cursor)]))
                ->toThrow(UnsupportedException::class, "Bind 'c': passing a Cursor back to the server is not supported yet");
        });

        expect($trips)->toBe(0);
    })->with('cursorsBoundBack');
});

describe('nested cursors', function (): void {
    it('gives them the options of the execution', function (): void {
        $options = new Options(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );

        $categories = $this->connection->function('ref_cursors.categories_with_items', options: $options)->cursor();

        [$id, $name, $items] = $categories->getIterator()->current();
        expect(array_map(static fn (Column $column): string => $column->name, $categories->columns()))->toBe(['id', 'name', 'items'])
            ->and($categories->columns()[2]->type)->toBe(Type::Cursor)
            ->and([$id, $name])->toBe([1, 'Plain'])
            ->and(nested($items)->all())->toBe([[1, 'Item 0001', 1.25], [6, 'Item 0006', 7.5], [11, 'Item 0011', 13.75], [16, 'Item 0016', 20]]);
    });

    it('fails to read them once their parent closes', function (bool $started): void {
        $parent = $this->connection->select(<<<'SQL'
            select c.id, cursor(select i.id from ref_items i where i.category_id = c.id and i.id <= 20 order by i.id) as items
              from ref_categories c
             order by c.id
            SQL);
        $child = nested($parent->all()[0]['ITEMS']);
        if ($started) {
            $child->getIterator()->current();
        }

        $parent->close();

        expect(fn (): array => $child->all())->toThrow(UsageException::class, 'The Cursor this one is nested in is closed, which closed this one too.');
    })->with(['started' => [true], 'unread' => [false]]);

    it('fetches them with the fetch size of the execution', function (): void {
        $sid = $this->sid($this->connection);
        $options = new Options(fetchSize: 3, values: new ValueMapping(numbers: NumberMode::Float), keyCase: KeyCase::Lower);
        $parent = $this->connection->select('select cursor(select level / 2 as half from dual connect by level <= 7) as halves from dual', options: $options);
        $halves = nested($parent->all()[0]['halves']);
        $rows = [];

        $trips = roundTrips($sid, static function () use ($halves, &$rows): void {
            $rows = $halves->all();
        });

        expect($rows)->toBe([['half' => 0.5], ['half' => 1], ['half' => 1.5], ['half' => 2], ['half' => 2.5], ['half' => 3], ['half' => 3.5]])
            ->and($trips)->toBe(3);
    });
});

function report(Connection $connection): Execution
{
    return $connection->procedure('ref_cursors.report', [
        'p_limit' => 250,
        'io_label' => new InOut\Varchar('total'),
        'o_items' => new Out\Cursor(),
        'o_categories' => new Out\Cursor(),
        'o_total' => new Out\Number(),
        'io_names' => new InOut\Cursor(),
    ]);
}

function nested(mixed $cell): Cursor
{
    assert($cell instanceof Cursor);

    return $cell;
}
