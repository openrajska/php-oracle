<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\CursorTest;

use Closure;
use Fiber;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\DataType\Type;
use Rajska\Oracle\DataType\VectorFormat;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob\Lob;
use Rajska\Oracle\Statement\Column;
use Rajska\Oracle\Statement\Cursor;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Functional\TestCase;
use RuntimeException;

uses(TestCase::class);

const FIVE_ROWS = 'select level n from dual connect by level <= 5';

/*
 * 30 items, three fetches of 10: item n is named 'Item 000n'.
 */
beforeEach(function (): void {
    schema()->run('create table cursor_items (id number(10) primary key, name varchar2(100) not null, price number(10, 2) not null)');
    schema()->run(<<<'SQL'
        insert into cursor_items (id, name, price)
        select level, 'Item ' || lpad(level, 4, '0'), level * 1.25 from dual connect by level <= 30
        SQL);
    schema()->run('create table cursor_writes (id number)');

    $this->connection = $this->connect();
});

test('select gives the rows run gives', function (): void {
    $sql = 'select id, name from cursor_items where id <= :last order by id';

    $selected = $this->connection->select($sql, ['last' => 3])->all();

    expect($selected)->toBe($this->connection->run($sql, ['last' => 3])->rows()->all())
        ->and($selected)->toHaveCount(3);
});

test('select executes and brings the first batch in one round trip', function (): void {
    $sid = $this->sid($this->connection);

    $trips = roundTrips($sid, fn (): Cursor => $this->connection->select('select id from cursor_items order by id', [], new Options(fetchSize: 10)));

    expect($trips)->toBe(1);
});

test('reading a cursor fetches each further batch in one round trip', function (): void {
    $sid = $this->sid($this->connection);
    $cursor = $this->connection->select('select id from cursor_items order by id', [], new Options(fetchSize: 10));

    $trips = roundTrips($sid, static fn (): array => $cursor->all());

    expect($trips)->toBe(2);
});

test('select of a statement that is not a query runs it and throws usage exception', function (): void {
    $thrown = null;

    try {
        $this->connection->select('insert into cursor_writes values (1)');
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    expect($thrown?->getMessage())->toContain('results()', 'out()')
        ->and($thrown?->sql())->toBe('insert into cursor_writes values (1)')
        ->and($this->connection->select('select count(*) from cursor_writes')->value())->toBe(1);
});

test('foreach is single pass and continues where it stopped', function (): void {
    $cursor = $this->connection->select(FIVE_ROWS, [], new Options(fetchSize: 2));
    $seen = [];
    foreach ($cursor as $position => $row) {
        $seen[$position] = $row['N'];
        if ($position === 2) {
            break;
        }
    }

    foreach ($cursor as $position => $row) {
        $seen[$position] = $row['N'];
    }

    expect($seen)->toBe([0 => 1, 1 => 2, 2 => 3, 3 => 4, 4 => 5])
        ->and(iterator_to_array($cursor))->toBeEmpty();
});

test('all returns the remaining rows', function (): void {
    $cursor = $this->connection->select(FIVE_ROWS, [], new Options(fetchSize: 2));
    foreach ($cursor as $row) {
        break;
    }

    $rows = $cursor->all();

    expect($rows)->toBe([['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5]])
        ->and($cursor->all())->toBeEmpty();
});

/**
 * @return iterable<string, array{Closure(Cursor): mixed, mixed}>
 */
dataset('nextRowReads', function () {
    yield 'first()' => [static fn (Cursor $cursor): mixed => $cursor->first(), ['N' => 2]];
    yield 'value()' => [static fn (Cursor $cursor): mixed => $cursor->value(), 2];
});

test('first and value read the next row and close the cursor', function (Closure $read, mixed $expected): void {
    $cursor = $this->connection->select(FIVE_ROWS);
    foreach ($cursor as $row) {
        break;
    }

    $value = $read($cursor);

    expect($value)->toBe($expected);
    assertClosed($cursor);
})->with('nextRowReads');

test('first and value of an empty result are null', function (Closure $read): void {
    $cursor = $this->connection->select('select 1 from dual where 1 = 0');

    $value = $read($cursor);

    expect($value)->toBeNull();
})->with('nextRowReads');

test('value reads the first column under either row shape', function (RowShape $rowShape): void {
    $cursor = $this->connection->select(FIVE_ROWS, [], new Options(rowShape: $rowShape));

    $value = $cursor->value();

    expect($value)->toBe(1);
})->with([RowShape::Assoc, RowShape::List]);

test('reading after close fails and a double close is fine', function (): void {
    $cursor = $this->connection->select(FIVE_ROWS, [], new Options(fetchSize: 2));
    foreach ($cursor as $row) {
        break;
    }

    $cursor->close();
    $cursor->close();

    assertClosed($cursor);
});

test('two cursors read interleaved with dml and commit between batches', function (): void {
    $options = new Options(fetchSize: 3);
    $sql = 'select id from cursor_items where id <= :last order by id';
    $iterators = [
        $this->connection->select($sql, ['last' => 10], $options)->getIterator(),
        $this->connection->select($sql, ['last' => 10], $options)->getIterator(),
    ];
    $ids = [[], []];

    while ($iterators[0]->valid() || $iterators[1]->valid()) {
        foreach ($iterators as $which => $rows) {
            if ($rows->valid()) {
                $ids[$which][] = $rows->current()['ID'];
                $rows->next();
            }
        }
        $this->connection->run('update cursor_items set name = name where id = 1');
        $this->connection->run('commit');
    }

    expect($ids)->toBe([range(1, 10), range(1, 10)]);
});

test('cursors read from two fibers interleave', function (): void {
    $sql = 'select id from cursor_items where id <= 10 order by id';
    $read = [];
    $fibers = [];
    foreach (['a', 'b'] as $name) {
        $cursor = $this->connection->select($sql, [], new Options(fetchSize: 3));
        $fibers[$name] = new Fiber(static function () use ($cursor, $name, &$read): void {
            foreach ($cursor as $row) {
                $read[$name][] = $row['ID'];
                Fiber::suspend();
            }
        });
    }

    foreach ($fibers as $fiber) {
        $fiber->start();
    }
    while (array_filter($fibers, static fn (Fiber $fiber): bool => !$fiber->isTerminated()) !== []) {
        foreach ($fibers as $fiber) {
            if (!$fiber->isTerminated()) {
                $fiber->resume();
            }
        }
    }

    expect($read)->toBe(['a' => range(1, 10), 'b' => range(1, 10)]);
});

test('cursors dropped half read free their server cursors', function (): void {
    $options = new Options(fetchSize: 2);

    for ($i = 0; $i < 2000; ++$i) {
        $cursor = $this->connection->select(FIVE_ROWS, [], $options);
        foreach ($cursor as $row) {
            break;
        }
        unset($cursor);
    }

    expect($this->connection->select('select count(*) from (' . FIVE_ROWS . ')')->value())->toBe(5);
});

test('columns are column objects before the first row', function (): void {
    $cursor = $this->connection->select(
        "select id, price, to_vector('[1,2,3]', 3, float32) embedding from cursor_items where id = 1",
        [],
        new Options(keyCase: KeyCase::Lower),
    );

    $columns = $cursor->columns();

    expect($columns)->toEqual([
        new Column(name: 'id', type: Type::Number, nullable: false, precision: 10, scale: 0, maxSize: null, objectType: null, vectorDimensions: null, vectorFormat: null, sparse: false),
        new Column(name: 'price', type: Type::Number, nullable: false, precision: 10, scale: 2, maxSize: null, objectType: null, vectorDimensions: null, vectorFormat: null, sparse: false),
        new Column(name: 'embedding', type: Type::Vector, nullable: true, precision: null, scale: null, maxSize: null, objectType: null, vectorDimensions: 3, vectorFormat: VectorFormat::Float32, sparse: false),
    ]);
});

/**
 * Config fields, the execute Options that override them, a query, and its
 * row under each.
 *
 * @return iterable<string, array{array<string, mixed>, Options, string, mixed, mixed}>
 */
dataset('configOverrides', function () {
    $item = 'select id, name from cursor_items where id = 1';
    $numbers = 'select 12.5 half, 5 five from dual';
    yield 'row shape' => [
        ['keyCase' => KeyCase::Lower],
        new Options(rowShape: RowShape::List),
        $item,
        ['id' => 1, 'name' => 'Item 0001'],
        [1, 'Item 0001'],
    ];
    yield 'key case' => [
        ['keyCase' => KeyCase::Lower],
        new Options(keyCase: KeyCase::Native),
        $item,
        ['id' => 1, 'name' => 'Item 0001'],
        ['ID' => 1, 'NAME' => 'Item 0001'],
    ];
    yield 'value mapping' => [
        ['values' => new ValueMapping(numbers: NumberMode::Float)],
        new Options(values: new ValueMapping()),
        $numbers,
        ['HALF' => 12.5, 'FIVE' => 5],
        ['HALF' => '12.5', 'FIVE' => 5],
    ];
});

test('rows read as the config says', function (array $fields, Options $options, string $sql, mixed $configured): void {
    $connection = $this->connect(...$fields);

    $row = $connection->select($sql)->first();

    expect($row)->toBe($configured);
})->with('configOverrides');

test('execute options override the config for that execution only', function (array $fields, Options $options, string $sql, mixed $configured, mixed $overridden): void {
    $connection = $this->connect(...$fields);

    $row = $connection->select($sql, [], $options)->first();

    expect($row)->toBe($overridden)
        ->and($connection->select($sql)->first())->toBe($configured);
})->with('configOverrides');

test('a compilation error is a warning on the execution', function (): void {
    $execution = $this->connection->run('create or replace procedure cursor_warned is begin no_such_thing; end;');

    $warning = $execution->warning();

    expect($warning)->not->toBeNull()
        ->and($execution->affected())->toBe(0);
});

test('a unit that compiles gives no warning', function (): void {
    $execution = $this->connection->run('create or replace procedure cursor_compiled is begin null; end;');

    $warning = $execution->warning();

    expect($warning)->toBeNull();
});

/**
 * Each result a Connection hands out, opened, and a read of it.
 *
 * @return iterable<string, array{Closure(Connection): (Closure(): mixed)}>
 */
dataset('connectionResults', function () {
    yield 'a Cursor with rows left in its batch' => [static function (Connection $connection): Closure {
        $cursor = $connection->select(FIVE_ROWS);
        foreach ($cursor as $row) {
            break;
        }

        return $cursor->all(...);
    }];
    yield 'a REF CURSOR' => [static function (Connection $connection): Closure {
        $cursor = $connection->run('begin open :c for select 1 from dual; end;', ['c' => new Out\Cursor()])->out('c')->cursor();

        return $cursor->all(...);
    }];
    yield 'a nested Cursor' => [static function (Connection $connection): Closure {
        $cursor = $connection->select('select cursor(select 1 from dual) n from dual')->all()[0]['N'];
        assert($cursor instanceof Cursor);

        return $cursor->all(...);
    }];
    yield 'an Implicit result' => [static function (Connection $connection): Closure {
        [$cursor] = $connection->run('declare c sys_refcursor; begin open c for select 1 from dual; dbms_sql.return_result(c); end;')->results();

        return $cursor->all(...);
    }];
    yield 'a Lob' => [static function (Connection $connection): Closure {
        $lob = $connection->select("select to_clob('text') from dual")->value();
        assert($lob instanceof Lob);

        return $lob->contents(...);
    }];
    yield 'a Lob stream read part way' => [static function (Connection $connection): Closure {
        $lob = $connection->run(<<<'SQL'
            declare
                l blob;
            begin
                dbms_lob.createtemporary(l, true);
                for i in 1 .. 64 loop
                    dbms_lob.writeappend(l, 32767, utl_raw.copies(hextoraw('00'), 32767));
                end loop;
                :l := l;
            end;
            SQL, ['l' => new Out\Blob()])->out('l')->lob();
        assert($lob instanceof Lob);
        $stream = $lob->stream();
        fread($stream, 8192);

        return static fn (): false | string => stream_get_contents($stream);
    }];
});

test('results fail after their connection closes', function (Closure $open): void {
    $connection = $this->connect();
    $read = $open($connection);

    $connection->close();

    expect($read)->toThrow(UsageException::class, 'The Connection is closed.');
})->with('connectionResults');

function assertClosed(Cursor $cursor): void
{
    foreach ([
        'foreach' => static fn (): array => iterator_to_array($cursor),
        'all()' => $cursor->all(...),
        'first()' => $cursor->first(...),
        'value()' => $cursor->value(...),
    ] as $read => $call) {
        try {
            $call();

            throw new RuntimeException("$read after close() succeeded.");
        } catch (UsageException) {
            test()->addToAssertionCount(1);
        }
    }
}
