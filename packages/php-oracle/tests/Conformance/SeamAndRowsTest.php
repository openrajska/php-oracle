<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\SeamAndRowsTest;

use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;

test('bind values never reach the sql text', function (): void {
    $tag = 'sql-text-' . bin2hex(random_bytes(6));
    $value = 'bound-value-' . bin2hex(random_bytes(6));
    $statements = [
        "select :v /* $tag */ from dual",
        "update fx_items set name = name where name = :v /* $tag */",
        "declare x varchar2(100) := :v; /* $tag */ begin null; end;",
    ];

    $connection = $this->connect();
    foreach ($statements as $sql) {
        $connection->execute($sql, ['v' => $value], $this->options());
    }

    $observer = IndependentSession::open();
    try {
        $texts = array_column(
            $observer->select('select sql_text from v$sql where instr(sql_text, :tag) > 0', ['tag' => $tag]),
            'SQL_TEXT',
        );
        $leaks = $observer->select('select sql_text from v$sql where instr(sql_text, :value) > 0', ['value' => $value]);
    } finally {
        $observer->close();
    }

    foreach ($statements as $sql) {
        expect($texts)->toContain($sql);
    }
    expect($leaks)->toBeEmpty();
});

test('every statement kind returns a complete execution', function (): void {
    $connection = $this->connect();
    $table = 'tmp_kinds_' . bin2hex(random_bytes(4));
    $kinds = [
        'DDL' => ["create table $table (id number)", 0, false],
        'DML' => ["insert into $table select level from dual connect by level <= 3", 3, false],
        'query' => ["select id from $table", 0, true],
        'Anonymous block' => ["begin update $table set id = id + 1; end;", 0, false],
        'DDL again' => ["drop table $table purge", 0, false],
    ];

    foreach ($kinds as $kind => [$sql, $count, $query]) {
        $execution = $connection->execute($sql, [], $this->options());
        expect($execution->rowCount())->toBe($count, $kind)
            ->and($execution->cursor() instanceof DriverCursor)->toBe($query, $kind)
            ->and($execution->outValues())->toBe([], $kind)
            ->and($execution->implicitResults())->toBe([], $kind)
            ->and($execution->warning())->toBeNull($kind);
    }
});

test('native keys are as oracle reports them lower keys are lowercase and list rows follow column order', function (): void {
    $connection = $this->connect();
    $sql = 'select 1 as one, 2 as "Two", 3 as "three" from dual';

    expect($this->rows($connection, $sql))->toBe([['ONE' => 1, 'Two' => 2, 'three' => 3]])
        ->and($this->rows($connection, $sql, options: $this->options(keyCase: KeyCase::Lower)))->toBe([['one' => 1, 'two' => 2, 'three' => 3]])
        ->and($this->rows($connection, $sql, options: $this->options(rowShape: RowShape::List)))->toBe([[1, 2, 3]]);
});

test('duplicate column names under assoc fail at cursor open with a hint', function (): void {
    $connection = $this->connect();
    $cases = [
        'select 1 as a, 2 as a from dual' => $this->options(),
        'select 1 as a, 2 as "a" from dual' => $this->options(keyCase: KeyCase::Lower),
    ];

    foreach ($cases as $sql => $options) {
        expect(fn(): DriverExecution => $connection->execute($sql, [], $options))->toThrow(function (UsageException $usageException) use ($sql): void {
            expect($usageException->getMessage())->toContain('alias', 'RowShape::List')
                ->and($usageException->sql())->toBe($sql);
        });
    }

    expect($this->rows($connection, 'select 1 as a, 2 as a from dual', options: $this->options(rowShape: RowShape::List)))->toBe([[1, 2]]);
});

test('columns describe every field before the first row also for an empty result', function (): void {
    $connection = $this->connect();
    $schema = strtoupper(TestDatabase::fromEnvironment()->user);
    $cursor = $connection->execute(
        <<<'SQL'
                select i.id, i.price, i.name, i.created_at, i.rowid,
                       cast(null as number) unconstrained, cast(null as float(53)) float53,
                       cast(null as char(5)) ch, cast(null as nchar(5)) nch, cast(null as nvarchar2(10)) nv,
                       cast(null as raw(16)) r,
                       cast(null as timestamp(3)) ts, cast(null as timestamp with time zone) tstz,
                       cast(null as timestamp(9) with local time zone) tsltz,
                       cast(null as interval day(3) to second(2)) ids, cast(null as interval year(4) to month) iym,
                       cast(null as binary_float) bf, cast(null as binary_double) bd, true b,
                       cast(null as urowid) ur, empty_clob() cl, to_nclob('') ncl, empty_blob() bl
                  from fx_items i
                 where 1 = 0
                SQL,
        [],
        $this->options(),
    )->cursor();
    assert($cursor !== null);
    expect($cursor->columns())->toBe([
        column('ID', 'number', nullable: false, precision: 10, scale: 0),
        column('PRICE', 'number', nullable: false, precision: 10, scale: 2),
        column('NAME', 'varchar', nullable: false, maxSize: 100),
        column('CREATED_AT', 'date', nullable: false),
        column('ROWID', 'rowid', nullable: false),
        column('UNCONSTRAINED', 'number'),
        column('FLOAT53', 'number', precision: 53),
        column('CH', 'char', maxSize: 5),
        column('NCH', 'nChar', maxSize: 5),
        column('NV', 'nVarchar', maxSize: 10),
        column('R', 'raw', maxSize: 16),
        column('TS', 'timestamp', scale: 3),
        column('TSTZ', 'timestampTz', scale: 6),
        column('TSLTZ', 'timestampLtz', scale: 9),
        column('IDS', 'intervalDs', precision: 3, scale: 2),
        column('IYM', 'intervalYm', precision: 4),
        column('BF', 'binaryFloat'),
        column('BD', 'binaryDouble'),
        column('B', 'boolean'),
        column('UR', 'urowid'),
        column('CL', 'clob'),
        column('NCL', 'nClob'),
        column('BL', 'blob'),
    ])
        ->and($cursor->fetchBatch())->toBeEmpty();

    $cursor = $connection->execute(
        <<<'SQL'
                select cast(null as json) j,
                       to_vector('[1,2,3]', 3, float32) v, to_vector('[1,2]', *, *) flexible,
                       to_vector('[10,[1],[1.0]]', 10, float64, sparse) sparse,
                       fx_point(1, 2, 'p') point, fx_number_list(1, 2) numbers,
                       cursor(select 1 from dual) nested, bfilename('NO_SUCH_DIRECTORY', 'x') locator
                  from dual
                SQL,
        [],
        $this->options(),
    )->cursor();
    assert($cursor !== null);
    expect($cursor->columns())->toBe([
        column('J', 'json'),
        column('V', 'vector', vectorDimensions: 3, vectorFormat: 'float32'),
        column('FLEXIBLE', 'vector'),
        column('SPARSE', 'vector', vectorDimensions: 10, vectorFormat: 'float64', sparse: true),
        column('POINT', 'object', objectType: "$schema.FX_POINT"),
        column('NUMBERS', 'collection', objectType: "$schema.FX_NUMBER_LIST"),
        column('NESTED', 'cursor'),
        column('LOCATOR', 'bfile'),
    ]);
    $cursor->close();
});

test('columns describe long columns and follow the key case', function (): void {
    $connection = $this->connect();

    // A table holds one LONG or LONG RAW column at most.
    $suffix = bin2hex(random_bytes(4));
    $connection->execute("create table tmp_long_$suffix (text long)", [], $this->options());

    try {
        $connection->execute("create table tmp_long_raw_$suffix (bytes long raw)", [], $this->options());
        try {
            $cursor = $connection->execute(
                "select t.text, b.bytes from tmp_long_$suffix t cross join tmp_long_raw_$suffix b",
                [],
                $this->options(keyCase: KeyCase::Lower),
            )->cursor();
            assert($cursor !== null);
            expect($cursor->columns())->toBe([column('text', 'long'), column('bytes', 'longRaw')]);
            $cursor->close();
        } finally {
            $connection->execute("drop table tmp_long_raw_$suffix purge", [], $this->options());
        }
    } finally {
        $connection->execute("drop table tmp_long_$suffix purge", [], $this->options());
    }
});

test('a bfile column is described and only fetching it fails', function (): void {
    $cursor = $this->connect()->execute("select bfilename('NO_SUCH_DIRECTORY', 'x') f from dual", [], $this->options())->cursor();
    assert($cursor !== null);
    expect($cursor->columns()[0]['type'])->toBe('bfile')
        ->and(fn() => $cursor->fetchBatch())->toThrow(UnsupportedException::class);
});

test('after close the connections cursors fail as use after close and the next owner is unaffected', function (): void {
    $fields = $this->ownPool() + ['maxConnections' => 1];
    $connection = $this->connect(...$fields);
    $session = $this->session($connection);
    $sql = 'select level as n from dual connect by level <= 3';
    $cursor = $connection->execute($sql, [], $this->options(fetchSize: 1))->cursor();
    assert($cursor !== null);
    expect($cursor->fetchBatch())->toBe([['N' => 1]]);

    $connection->close();

    foreach (['fetchBatch()' => $cursor->fetchBatch(...), 'columns()' => $cursor->columns(...)] as $call => $read) {
        try {
            $read();
            $this->fail("$call after close() succeeded.");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Connection is closed.', $call);
        }
    }
    $cursor->close();

    $next = $this->connect(...$fields);
    expect($this->session($next))->toBe($session)
        ->and($this->rows($next, $sql, [], $this->options(fetchSize: 1)))->toBe([['N' => 1], ['N' => 2], ['N' => 3]]);
});

/**
 * @return array<string, mixed>
 */
function column(string $name, string $type, bool $nullable = true, int | null $precision = null, int | null $scale = null, int | null $maxSize = null, string | null $objectType = null, int | null $vectorDimensions = null, string | null $vectorFormat = null, bool $sparse = false): array
{
    return [
        'name' => $name,
        'type' => $type,
        'nullable' => $nullable,
        'precision' => $precision,
        'scale' => $scale,
        'maxSize' => $maxSize,
        'objectType' => $objectType,
        'vectorDimensions' => $vectorDimensions,
        'vectorFormat' => $vectorFormat,
        'sparse' => $sparse,
    ];
}
