<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\ImplicitResultTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Contracts\Driver\Response;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

/*
 * Five categories with multibyte names, and 10 items: item n is in category
 * ((n - 1) mod 5) + 1, named 'Item 000n', priced n * 1.25.
 */
beforeAll(function (): void {
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
});

test('implicit results come in order next to an out value', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        'begin implicit_results(:n); select count(*) into :total from implicit_items; end;',
        ['n' => 4, 'total' => new Out\Number()],
        $this->options(),
    );

    [$items, $categories] = results($execution, 2);
    expect($execution->outValues())->toBe(['total' => [10]])
        ->and(array_column($items->columns(), 'name'))->toBe(['ID', 'NAME', 'PRICE'])
        ->and(array_column($categories->columns(), 'name'))->toBe(['ID', 'NAME']);
});

test('implicit results read interleaved', function (): void {
    $execution = $this->connect()->execute('begin implicit_results(:n); end;', ['n' => 4], $this->options(fetchSize: 2));
    [$items, $categories] = results($execution, 2);

    $batches = [$categories->fetchBatch(), $items->fetchBatch(), $categories->fetchBatch(), $items->fetchBatch(), $items->fetchBatch(), $categories->fetchBatch(), $categories->fetchBatch()];

    expect($batches)->toBe([
        [['ID' => 1, 'NAME' => 'Plain'], ['ID' => 2, 'NAME' => 'Ação']],
        [['ID' => 1, 'NAME' => 'Item 0001', 'PRICE' => '1.25'], ['ID' => 2, 'NAME' => 'Item 0002', 'PRICE' => '2.5']],
        [['ID' => 3, 'NAME' => 'Café'], ['ID' => 4, 'NAME' => 'Straße']],
        [['ID' => 3, 'NAME' => 'Item 0003', 'PRICE' => '3.75'], ['ID' => 4, 'NAME' => 'Item 0004', 'PRICE' => 5]],
        [],
        [['ID' => 5, 'NAME' => '日本']],
        [],
    ]);
});

test('an implicit result reads on its own after its execution is dropped', function (): void {
    $connection = $this->connect();
    $categories = results($connection->execute('begin implicit_results(:n); end;', ['n' => 3], $this->options()), 2)[1];

    $ids = array_column(drain($categories), 'ID');

    expect($ids)->toBe([1, 2, 3, 4, 5])
        ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
});

/**
 * @return iterable<string, array{string, array<string, mixed>}>
 */
dataset('withoutResults', function () {
    yield 'a block' => ['begin :x := 1; end;', ['x' => new Out\Number()]];
    yield 'a query' => ['select id from implicit_categories', []];
    yield 'DML' => ['update implicit_categories set name = name where id = 0', []];
});

test('a statement with no return result gives no implicit results', function (string $sql, array $binds): void {
    /** @var array<string, mixed> $binds */
    $connection = $this->connect();

    $results = $connection->execute($sql, $binds, $this->options())->implicitResults();

    expect($results)->toBeEmpty();
})->with('withoutResults');

test('a raise after return result throws every time', function (): void {
    $connection = $this->connect();
    $codes = [];

    for ($i = 0; $i < 500; ++$i) {
        try {
            $connection->execute("begin implicit_results(3); raise_application_error(-20056, 'after the results'); end;", [], $this->options());
            $codes[] = 0;
        } catch (QueryException $queryException) {
            $codes[] = $queryException->getCode();
        }
    }

    expect(array_unique($codes))->toBe([20056])
        ->and(array_column($this->rows($connection, 'select id from implicit_categories order by id'), 'ID'))->toBe([1, 2, 3, 4, 5]);
});

test('implicit results inherit the executes options', function (): void {
    $options = $this->options(
        fetchSize: 3,
        values: new ValueMapping(numbers: NumberMode::Float),
        rowShape: RowShape::List,
        keyCase: KeyCase::Lower,
    );

    $execution = $this->connect()->execute('begin implicit_results(:n); end;', ['n' => 5], $options);

    [$items, $categories] = results($execution, 2);
    expect(array_column($items->columns(), 'name'))->toBe(['id', 'name', 'price'])
        ->and($items->fetchBatch())->toBe([[1, 'Item 0001', 1.25], [2, 'Item 0002', 2.5], [3, 'Item 0003', 3.75]])
        ->and($items->fetchBatch())->toBe([[4, 'Item 0004', 5], [5, 'Item 0005', 6.25]])
        ->and(array_column($categories->columns(), 'name'))->toBe(['id', 'name'])
        ->and($categories->fetchBatch())->toHaveCount(3);
});

/**
 * @return list<Cursor>
 */
function results(Response $execution, int $count): array
{
    $results = $execution->implicitResults();
    expect($results)->toHaveCount($count);

    return $results;
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
