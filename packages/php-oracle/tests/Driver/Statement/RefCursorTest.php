<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Statement\RefCursorTest;

use Closure;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Contracts\Driver\Response;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

const NESTED = <<<'SQL'
    select c.id, c.name,
           cursor(select i.id, i.price from ref_items i
                   where i.category_id = c.id order by i.id) as items
      from ref_categories c
     order by c.id
    SQL;

beforeEach(function (): void {
    schema()->run('create table ref_categories (id number(10) primary key, name varchar2(50) not null)');
    schema()->run(<<<'SQL'
        create table ref_items (
            id number(10) primary key,
            category_id number(10) not null references ref_categories,
            name varchar2(100) not null,
            price number(10, 2) not null,
            created_at date not null
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
        insert into ref_items (id, category_id, name, price, created_at)
        select level, mod(level - 1, 5) + 1, 'Item ' || lpad(level, 4, '0'), level * 1.25, date '2026-01-01' + (level - 1) / 24
          from dual
        connect by level <= 20
        SQL);
    schema()->run(<<<'SQL'
        create package ref_cursors as
            type item_cursor is ref cursor return ref_items%rowtype;

            function items(p_limit number) return sys_refcursor;

            procedure typed_cursor(p_limit number, o_items out item_cursor);

            procedure two_cursors(p_limit number, o_items out sys_refcursor, o_categories out sys_refcursor, o_total out number);

            function categories_with_items return sys_refcursor;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body ref_cursors as
            function items(p_limit number) return sys_refcursor is
                l_cursor sys_refcursor;
            begin
                open l_cursor for select id, category_id, name, price, created_at from ref_items where id <= p_limit order by id;
                return l_cursor;
            end;

            procedure typed_cursor(p_limit number, o_items out item_cursor) is
            begin
                open o_items for select * from ref_items where id <= p_limit order by id;
            end;

            procedure two_cursors(p_limit number, o_items out sys_refcursor, o_categories out sys_refcursor, o_total out number) is
            begin
                open o_items for select id, name, price, created_at from ref_items where id <= p_limit order by id;
                open o_categories for select id, name from ref_categories order by id;
                select count(*) into o_total from ref_items;
            end;

            function categories_with_items return sys_refcursor is
                l_cursor sys_refcursor;
            begin
                open l_cursor for
                    select c.id, c.name,
                           cursor(select i.id, i.name, i.price from ref_items i where i.category_id = c.id order by i.id) as items
                      from ref_categories c
                     order by c.id;
                return l_cursor;
            end;
        end;
        SQL);
});

/**
 * Each way a REF CURSOR with a DATE column arrives: the dates of items 1 and
 * 2 it reads, as compared.
 *
 * @return iterable<string, array{string, array<string, mixed>, string, TemporalMode, list<mixed>}>
 */
dataset('dateCursors', function () {
    $objects = ['+2026-01-01 00:00:00.000000 UTC', '+2026-01-01 01:00:00.000000 UTC'];
    yield 'a function return' => ['begin :o := ref_cursors.items(2); end;', ['o' => new Out\Cursor()], 'o', TemporalMode::Object, $objects];
    yield 'a strongly typed OUT' => ['begin ref_cursors.typed_cursor(2, :o); end;', ['o' => new Out\Cursor()], 'o', TemporalMode::String, ['2026-01-01 00:00:00', '2026-01-01 01:00:00']];
    yield 'one of several OUTs' => [
        'begin ref_cursors.two_cursors(2, :items, :categories, :total); end;',
        ['items' => new Out\Cursor(), 'categories' => new Out\Cursor(), 'total' => new Out\Number()],
        'items',
        TemporalMode::Object,
        $objects,
    ];
});

/**
 * @return iterable<string, array{bool, string}>
 */
dataset('nestedReads', function () {
    foreach (['read' => true, 'unread' => false] as $which => $started) {
        yield "fetchBatch() of a $which nested cursor" => [$started, 'fetchBatch'];
        yield "columns() of a $which nested cursor" => [$started, 'columns'];
    }
});

/**
 * Each kind of Cursor a statement can give, opened and dropped unread.
 *
 * @return iterable<string, array{Closure(Session): mixed}>
 */
dataset('cursorKinds', function () {
    $options = TestCase::options();
    yield 'REF CURSOR' => [static fn (Session $c): Response => $c->execute('begin open :o for select id from ref_categories; end;', ['o' => new Out\Cursor()], $options)];
    yield 'nested cursor' => [static fn (Session $c): mixed => $c->execute(NESTED, [], $options)->cursor()?->fetchBatch()];
    yield 'Implicit result' => [static fn (Session $c): Response => $c->execute(
        'declare c sys_refcursor; begin open c for select id from ref_categories; dbms_sql.return_result(c); end;',
        [],
        $options,
    )];
});

describe('ref cursors', function (): void {
    it('arrives as a driver cursor from an out bind', function (): void {
        $connection = $this->connect();

        $execution = $connection->execute(
            'begin open :o for select id, name, price from ref_items where id <= :n order by id; end;',
            ['o' => new Out\Cursor(), 'n' => 3],
            $this->options(),
        );

        expect(drain(cursor($execution, 'o')))->toBe([
            ['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'],
            ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5'],
            ['ID' => 3, 'NAME' => 'Item 0003', 'PRICE' => '3.75'],
        ]);
    });

    it('is opened by the server through an in out bind', function (): void {
        $connection = $this->connect();

        $execution = $connection->execute(
            'begin open :io for select name from ref_categories where id <= 2 order by id; end;',
            ['io' => new InOut\Cursor()],
            $this->options(),
        );

        expect(drain(cursor($execution, 'io')))->toBe([['NAME' => 'Plain'], ['NAME' => 'Ação']]);
    });

    it('arrives as a driver cursor with its columns from a function return', function (): void {
        $connection = $this->connect();

        $execution = $connection->execute('begin :o := ref_cursors.categories_with_items; end;', ['o' => new Out\Cursor()], $this->options());

        $columns = cursor($execution, 'o')->columns();
        expect(array_column($columns, 'name'))->toBe(['ID', 'NAME', 'ITEMS'])
            ->and(array_column($columns, 'type'))->toBe(['number', 'varchar', 'cursor']);
    });

    it('reads in full with a date column', function (string $sql, array $binds, string $name, TemporalMode $temporals, array $expected): void {
        /** @var array<string, mixed> $binds */
        $dates = inTimeZone('UTC', function () use ($sql, $binds, $name, $temporals): array {
            $execution = $this->connect()->execute($sql, $binds, $this->options(values: new ValueMapping(temporals: $temporals)));

            return array_map(temporalText(...), array_column(drain(cursor($execution, $name)), 'CREATED_AT'));
        });

        expect($dates)->toBe($expected);
    })->with('dateCursors');

    it('fails on its first read when unopened and the connection goes on', function (): void {
        $connection = $this->connect();
        $execution = $connection->execute('declare c sys_refcursor; begin :o := c; end;', ['o' => new Out\Cursor()], $this->options());
        $thrown = null;

        try {
            cursor($execution, 'o')->fetchBatch();
        } catch (QueryException $queryException) {
            $thrown = $queryException;
        }

        expect($thrown?->getCode())->toBe(1001)
            ->and($thrown?->getMessage())->toStartWith('ORA-01001')
            ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
    });

    it('describes its columns when empty', function (): void {
        $connection = $this->connect();
        $execution = $connection->execute(
            'begin open :o for select id, name from ref_categories where 1 = 0; end;',
            ['o' => new Out\Cursor()],
            $this->options(),
        );
        $cursor = cursor($execution, 'o');

        $columns = $cursor->columns();

        expect($columns)->toBe([
            ['name' => 'ID', 'type' => 'number', 'nullable' => false, 'precision' => 10, 'scale' => 0, 'maxSize' => null, 'objectType' => null, 'vectorDimensions' => null, 'vectorFormat' => null, 'sparse' => false],
            ['name' => 'NAME', 'type' => 'varchar', 'nullable' => false, 'precision' => null, 'scale' => null, 'maxSize' => 50, 'objectType' => null, 'vectorDimensions' => null, 'vectorFormat' => null, 'sparse' => false],
        ])
            ->and([$cursor->fetchBatch(), $cursor->fetchBatch()])->toBe([[], []]);
    });
});

describe('nested cursors', function (): void {
    it('reads after the parent is read to its end', function (): void {
        $parent = $this->connect()->execute(NESTED, [], $this->options(fetchSize: 2))->cursor();
        assert($parent !== null);
        $rows = drain($parent);

        $items = [];
        foreach ($rows as $row) {
            assert(\is_string($row['NAME']) && $row['ITEMS'] instanceof Cursor);
            $items[$row['NAME']] = array_column(drain($row['ITEMS']), 'ID');
        }

        expect($items)->toBe(['Plain' => [1, 6, 11, 16], 'Ação' => [2, 7, 12, 17], 'Café' => [3, 8, 13, 18], 'Straße' => [4, 9, 14, 19], '日本' => [5, 10, 15, 20]]);
    });

    it('fails after its parent closes and the connection goes on', function (bool $started, string $read): void {
        $connection = $this->connect();
        $parent = $connection->execute(NESTED, [], $this->options())->cursor();
        assert($parent !== null);
        $child = $parent->fetchBatch()[$started ? 0 : 1]['ITEMS'] ?? null;
        assert($child instanceof Cursor);
        if ($started) {
            $child->fetchBatch();
        }
        $parent->close();
        $thrown = null;

        try {
            $child->$read();
        } catch (UsageException $usageException) {
            $thrown = $usageException;
        }

        expect($thrown?->getMessage())->toBe('The Cursor this one is nested in is closed, which closed this one too.')
            ->and($thrown?->sql())->toBe(NESTED)
            ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
    })->with('nestedReads');

    it('keeps its parent open after the parent object is dropped', function (): void {
        $connection = $this->connect(...$this->ownPool() + ['statementCacheSize' => 0]);
        $parent = $connection->execute(NESTED, [], $this->options())->cursor();
        assert($parent !== null);
        $child = $parent->fetchBatch()[2]['ITEMS'] ?? null;
        assert($child instanceof Cursor);
        unset($parent);

        // A Round trip that would carry the parent's close.
        $this->value($connection, 'select 1 from dual');

        expect(array_column(drain($child), 'ID'))->toBe([3, 8, 13, 18]);
    });
});

describe('cursors of every kind', function (): void {
    it('inherit the options of their execution', function (): void {
        $options = $this->options(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );
        $execution = $this->connect()->execute('begin :o := ref_cursors.categories_with_items; end;', ['o' => new Out\Cursor()], $options);
        $categories = cursor($execution, 'o');

        $batch = $categories->fetchBatch();
        $items = $batch[0][2] ?? null;
        assert($items instanceof Cursor);

        expect(array_column($categories->columns(), 'name'))->toBe(['id', 'name', 'items'])
            ->and($batch)->toHaveCount(3)
            ->and(\array_slice($batch[0], 0, 2))->toBe([1, 'Plain'])
            ->and(array_column($items->columns(), 'name'))->toBe(['id', 'name', 'price'])
            ->and($items->fetchBatch())->toBe([[1, 'Item 0001', 1.25], [6, 'Item 0006', 7.5], [11, 'Item 0011', 13.75]])
            ->and($items->fetchBatch())->toBe([[16, 'Item 0016', 20]]);
    });

    it('raise no ora 01000 when dropped unread', function (Closure $open): void {
        $connection = $this->connect();

        for ($i = 0; $i < 1000; ++$i) {
            $open($connection);
        }

        expect(array_column($this->rows($connection, 'select id from ref_categories order by id'), 'ID'))->toBe([1, 2, 3, 4, 5]);
    })->with('cursorKinds');
});

/**
 * The one REF CURSOR in an OUT value list.
 */
function cursor(Response $execution, string $name): Cursor
{
    $cursor = $execution->outValues()[$name][0] ?? null;
    assert($cursor instanceof Cursor);

    return $cursor;
}

/**
 * @return list<array<int|string, mixed>>
 */
function drain(Cursor $cursor): array
{
    $rows = [];
    while (($batch = $cursor->fetchBatch()) !== []) {
        array_push($rows, ...$batch);
    }

    return $rows;
}
