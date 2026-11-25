<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\CursorTest;

use Fiber;
use Rajska\Oracle\Column;
use Rajska\Oracle\Cursor;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\SparseVector;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Type;
use Rajska\Oracle\ValueMapping;
use Rajska\Oracle\VectorFormat;
use RuntimeException;

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});
afterEach(function (): void {
    $this->connection->close();
});
test('select equals run rows', function (): void {
    $sql = 'select id, name from fx_items where id <= :last order by id';

    expect($this->connection->select($sql, ['last' => 3])->all())->toBe($this->connection->run($sql, ['last' => 3])->rows()->all())
        ->and($this->connection->select($sql, ['last' => 1])->all())->toBe([['ID' => 1, 'NAME' => 'Item 0001']]);
});
test('select is lazy', function (): void {
    $sid = sid();
    $counter = RoundTripCounter::open();
    $options = new ExecuteOptions(fetchSize: 10);

    try {
        $cursor = null;
        expect($counter->during($sid, function () use (&$cursor, $options): void {
            $cursor = $this->connection->select('select id from fx_items order by id', [], $options);
        }))->toBe(1)
            ->and($cursor)->toBeInstanceOf(Cursor::class);
        // The execute brought the first batch; the second and third
        // batches cost one Round trip each.
        expect($counter->during($sid, function () use ($cursor): void {
            foreach ($cursor as $position => $row) {
                if ($position === 29) {
                    break;
                }
            }
        }))->toBe(2);
    } finally {
        $counter->close();
    }
});
test('select of an update runs it then fails in rows', function (): void {
    $table = 'tmp_select_' . bin2hex(random_bytes(4));
    $this->connection->run("create table $table (id number)");

    try {
        expect(fn() => $this->connection->select("insert into $table values (1)"))->toThrow(function (UsageException $usageException) use ($table): void {
            expect($usageException->getMessage())->toContain('results()', 'out()')
                ->and($usageException->sql())->toBe("insert into $table values (1)");
        })
            ->and($this->connection->select("select count(*) from $table")->value())->toBe(1)
            ->and(fn() => $this->connection->run('begin null; end;')->rows())->toThrow(UsageException::class);
    } finally {
        $this->connection->run("drop table $table purge");
    }
});
test('foreach is single pass and continues where it stopped', function (): void {
    $fiveRows = 'select level n from dual connect by level <= 5';

    $cursor = $this->connection->select($fiveRows, [], new ExecuteOptions(fetchSize: 2));

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
        ->and(iterator_to_array($cursor))->toBeEmpty()
        ->and($cursor->all())->toBe([]);
});
test('all returns the remaining rows', function (): void {
    $fiveRows = 'select level n from dual connect by level <= 5';

    $cursor = $this->connection->select($fiveRows, [], new ExecuteOptions(fetchSize: 2));

    foreach ($cursor as $row) {
        break;
    }

    expect($cursor->all())->toBe([['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5]])
        ->and($cursor->all())->toBe([]);
});
test('first and value return the next row then close', function (): void {
    $fiveRows = 'select level n from dual connect by level <= 5';

    $cursor = $this->connection->select($fiveRows);
    foreach ($cursor as $row) {
        break;
    }
    expect($cursor->first())->toBe(['N' => 2]);
    assertClosed($cursor);

    expect($this->connection->select($fiveRows)->value())->toBe(1)
        ->and($this->connection->select('select 1 from dual where 1 = 0')->value())->toBeNull()
        ->and($this->connection->select('select 1 from dual where 1 = 0')->first())->toBeNull()
        ->and($this->connection->select($fiveRows, [], new ExecuteOptions(rowShape: RowShape::List))->value())->toBe(1);

    $cursor = $this->connection->select($fiveRows);
    $cursor->value();
    assertClosed($cursor);
});
test('reading after close fails and a double close is fine', function (): void {
    $fiveRows = 'select level n from dual connect by level <= 5';

    $cursor = $this->connection->select($fiveRows, [], new ExecuteOptions(fetchSize: 2));
    foreach ($cursor as $row) {
        break;
    }

    $cursor->close();
    $cursor->close();

    assertClosed($cursor);
});
test('two cursors read interleaved with dml and commit between batches', function (): void {
    $options = new ExecuteOptions(fetchSize: 3);
    $sql = 'select id from fx_items where id <= :last order by id';
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
        $this->connection->run('update fx_items set name = name where id = 1');
        $this->connection->run('commit');
    }

    expect($ids)->toBe([range(1, 10), range(1, 10)]);

    $fibers = [];
    $read = [];
    foreach (['a', 'b'] as $name) {
        $cursor = $this->connection->select($sql, ['last' => 10], $options);
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
    while (array_filter($fibers, static fn(Fiber $f): bool => !$f->isTerminated()) !== []) {
        foreach ($fibers as $fiber) {
            if (!$fiber->isTerminated()) {
                $fiber->resume();
            }
        }
    }

    expect($read)->toBe(['a' => range(1, 10), 'b' => range(1, 10)]);
});
test('two thousand cursors dropped half read raise no ora 01000', function (): void {
    $fiveRows = 'select level n from dual connect by level <= 5';

    $options = new ExecuteOptions(fetchSize: 2);
    for ($i = 0; $i < 2000; ++$i) {
        $cursor = $this->connection->select($fiveRows, [], $options);
        foreach ($cursor as $row) {
            break;
        }
        unset($cursor);
    }

    expect($this->connection->select('select count(*) from (' . $fiveRows . ')')->value())->toBe(5);
});
test('columns are column objects before the first row', function (): void {
    $cursor = $this->connection->select(
        "select id, price, to_vector('[1,2,3]', 3, float32) embedding from fx_items where id = 1",
        [],
        new ExecuteOptions(keyCase: KeyCase::Lower),
    );

    $columns = $cursor->columns();

    expect($columns)->toEqual([
        new Column(
            name: 'id',
            type: Type::Number,
            nullable: false,
            precision: 10,
            scale: 0,
            maxSize: null,
            objectType: null,
            vectorDimensions: null,
            vectorFormat: null,
            sparse: false,
        ),
        new Column(
            name: 'price',
            type: Type::Number,
            nullable: false,
            precision: 10,
            scale: 2,
            maxSize: null,
            objectType: null,
            vectorDimensions: null,
            vectorFormat: null,
            sparse: false,
        ),
        new Column(
            name: 'embedding',
            type: Type::Vector,
            nullable: true,
            precision: null,
            scale: null,
            maxSize: null,
            objectType: null,
            vectorDimensions: 3,
            vectorFormat: VectorFormat::Float32,
            sparse: false,
        ),
    ])
        ->and($cursor->columns()[0]->type)->toBe(Type::Number);

    $cursor->close();
});
test('an xmltype column is described and reading it is unsupported', function (): void {
    $cursor = $this->connection->select("select xmltype('<a>1</a>') doc from dual");
    expect($cursor->columns()[0]->type)->toBe(Type::Xmltype)
        ->and(function () use ($cursor): void {
            $cursor->first();
            self::fail('An XMLTYPE value was read.');
        })->toThrow(UnsupportedException::class, 'Column DOC: XMLTYPE values cannot be fetched yet.')
        ->and($this->connection->select(<<<'SQL'
                select to_binary_float(0.1) bf, true flag, hextoraw('00FF') bytes,
                       cast('a' as char(3)) ch, l.text long_text
                  from fx_long_rows l
                 where l.id = 1
                SQL)->first())->toBe(['BF' => 0.1, 'FLAG' => true, 'BYTES' => "\x00\xff", 'CH' => 'a  ', 'LONG_TEXT' => 'long text']);
});
test('json and vector columns arrive decoded', function (): void {
    $row = $this->connection->select(
        "select json('{\"a\": [1, 0.5]}') doc, v8, s8 from fx_vectors where id = 1",
        [],
        new ExecuteOptions(keyCase: KeyCase::Lower),
    )->first();

    expect($row)->not->toBeNull()
        ->and($row['doc'])->toBe(['a' => [1, '0.5']])
        ->and($row['v8'])->toBe([1, -2, 127])
        ->and($row['s8'])->toEqual(new SparseVector(10, [0 => -128, 9 => 127]));
});
test('row shape and key case come from the config and execute options override them', function (): void {
    $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(rowShape: RowShape::Assoc, keyCase: KeyCase::Lower));
    $sql = 'select id, name from fx_items where id = 1';

    try {
        expect($connection->select($sql)->first())->toBe(['id' => 1, 'name' => 'Item 0001'])
            ->and($connection->select($sql, [], new ExecuteOptions(rowShape: RowShape::List))->first())->toBe([1, 'Item 0001'])
            ->and($connection->select($sql, [], new ExecuteOptions(keyCase: KeyCase::Native))->first())->toBe(['ID' => 1, 'NAME' => 'Item 0001'])
            ->and($connection->select($sql)->first())->toBe(['id' => 1, 'name' => 'Item 0001']);
    } finally {
        $connection->close();
    }
});
test('numbers float works per connection and per execution', function (): void {
    $float = new ValueMapping(numbers: NumberMode::Float);
    $sql = 'select 12.5 half, 5 five from dual';

    expect($this->connection->select($sql, [], new ExecuteOptions(values: $float))->first())->toBe(['HALF' => 12.5, 'FIVE' => 5])
        ->and($this->connection->select($sql)->first())->toBe(['HALF' => '12.5', 'FIVE' => 5]);

    $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(values: $float));
    try {
        expect($connection->select($sql)->first())->toBe(['HALF' => 12.5, 'FIVE' => 5])
            ->and($connection->select($sql, [], new ExecuteOptions(values: new ValueMapping()))->first())->toBe(['HALF' => '12.5', 'FIVE' => 5]);
    } finally {
        $connection->close();
    }
});
test('ddl runs through run and a compilation error is a warning', function (): void {
    $procedure = 'tmp_warning_' . bin2hex(random_bytes(4));

    $created = $this->connection->run("create or replace procedure $procedure is begin no_such_thing; end;");
    try {
        expect($created->affected())->toBe(0)
            ->and($created->warning())->not->toBeNull()
            ->and($this->connection->run("create or replace procedure $procedure is begin null; end;")->warning())->toBeNull();
    } finally {
        $this->connection->run("drop procedure $procedure");
    }
});
test('after the connection closes its cursor fails even with rows left in its batch', function (): void {
    $fiveRows = 'select level n from dual connect by level <= 5';

    $cursor = $this->connection->select($fiveRows);
    foreach ($cursor as $row) {
        expect($row)->toBe(['N' => 1]);
        break;
    }

    $this->connection->close();

    $served = [];
    expect(function () use ($cursor, &$served): void {
        foreach ($cursor as $row) {
            $served[] = $row;
        }
        self::fail('foreach after the Connection closed succeeded.');
    })->toThrow(UsageException::class, 'The Connection is closed.')
        ->and($served)->toBe([], 'No row of the batch is served after the Connection closed.');
    assertClosed($cursor);
    $cursor->close();
});
function assertClosed(Cursor $cursor): void
{
    foreach ([
        'foreach' => static fn(): array => iterator_to_array($cursor),
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
function sid(): int
{
    $sid = test()->connection->select("select sys_context('userenv', 'sid') from dual")->value();
    expect($sid)->toBeString();

    return (int) $sid;
}
