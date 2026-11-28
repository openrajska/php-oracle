<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Statement\ImplicitResultTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Statement\Column;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table implicit_categories (id number(10) primary key, name varchar2(50) not null)');
    schema()->run(<<<'SQL'
        create table implicit_items (
            id number(10) primary key,
            category_id number(10) not null references implicit_categories,
            name varchar2(100) not null,
            price number(10, 2) not null
        )
        SQL);
    schema()->run(<<<'SQL'
        insert into implicit_categories (id, name)
        select 1, 'Plain' from dual union all
        select 2, 'Ação' from dual union all
        select 3, 'Café' from dual union all
        select 4, 'Straße' from dual union all
        select 5, '日本' from dual
        SQL);
    schema()->run(<<<'SQL'
        insert into implicit_items (id, category_id, name, price)
        select level, mod(level - 1, 5) + 1, 'Item ' || lpad(level, 4, '0'), level * 1.25
          from dual
        connect by level <= 10
        SQL);
    schema()->run(<<<'SQL'
        create procedure implicit_results(p_limit number) is
            l_items sys_refcursor;
            l_categories sys_refcursor;
        begin
            open l_items for select id, name, price from implicit_items where id <= p_limit order by id;
            dbms_sql.return_result(l_items);
            open l_categories for select id, name from implicit_categories order by id;
            dbms_sql.return_result(l_categories);
        end;
        SQL);

    $this->connection = $this->connect();
});

describe('implicit results', function (): void {
    it('gives them in order next to the out values of the block', function (): void {
        $execution = $this->connection->run(
            'begin implicit_results(:n); select count(*) into :total from implicit_items; end;',
            ['n' => 2, 'total' => new Out\Number()],
        );

        [$items, $categories] = $execution->results();

        expect($execution->results())->toHaveCount(2)
            ->and($execution->out('total')->int())->toBe(10)
            ->and(array_column($items->all(), 'NAME'))->toBe(['Item 0001', 'Item 0002'])
            ->and(array_column($categories->all(), 'NAME'))->toBe(['Plain', 'Ação', 'Café', 'Straße', '日本']);
    });

    it('reads them in any order', function (): void {
        $execution = $this->connection->procedure('implicit_results', ['p_limit' => 2], new Options(fetchSize: 2));
        [$items, $categories] = $execution->results();

        $firstCategories = \array_slice($categories->all(), 0, 2);

        expect($execution->results())->toBe([$items, $categories])
            ->and($firstCategories)->toBe([['ID' => 1, 'NAME' => 'Plain'], ['ID' => 2, 'NAME' => 'Ação']])
            ->and($items->all())->toBe([['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'], ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5']]);
    });

    it('gives them the options of the execution', function (): void {
        $sid = $this->sid($this->connection);
        $options = new Options(
            fetchSize: 3,
            values: new ValueMapping(numbers: NumberMode::Float),
            rowShape: RowShape::List,
            keyCase: KeyCase::Lower,
        );
        [$items] = $this->connection->procedure('implicit_results', ['p_limit' => 7], $options)->results();
        $rows = [];

        $trips = roundTrips($sid, static function () use ($items, &$rows): void {
            $rows = $items->all();
        });

        expect(array_map(static fn (Column $column): string => $column->name, $items->columns()))->toBe(['id', 'name', 'price'])
            ->and($rows)->toBe([[1, 'Item 0001', 1.25], [2, 'Item 0002', 2.5], [3, 'Item 0003', 3.75], [4, 'Item 0004', 5], [5, 'Item 0005', 6.25], [6, 'Item 0006', 7.5], [7, 'Item 0007', 8.75]])
            ->and($trips)->toBe(3);
    });
});
