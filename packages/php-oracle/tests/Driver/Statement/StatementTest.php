<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Statement\StatementTest;

use Closure;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Tests\Driver\TestCase;
use Rajska\Oracle\Tracing;
use Throwable;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run(<<<'SQL'
        create table statement_items (
            id number(10) primary key,
            price number(10, 2) not null,
            name varchar2(100) not null,
            created_at date not null
        )
        SQL);
    schema()->run('create table statement_rows (id number(10))');
    schema()->run('create table statement_longs (text long)');
    schema()->run('create table statement_long_raws (bytes long raw)');
    schema()->run('create type statement_point as object (x number, y number, label varchar2(30))');
    schema()->run('create type statement_numbers as table of number');
});

test('bind values never reach the sql text', function (): void {
    $tag = 'sql-text-' . bin2hex(random_bytes(6));
    $value = 'bound-value-' . bin2hex(random_bytes(6));
    $statements = [
        "select :v /* $tag */ from dual",
        "update statement_items set name = name where name = :v /* $tag */",
        "declare x varchar2(100) := :v; /* $tag */ begin null; end;",
    ];
    $connection = $this->connect();

    foreach ($statements as $sql) {
        $connection->execute($sql, ['v' => $value], $this->options());
    }

    $texts = array_column(otherSession()->select('select sql_text from v$sql where instr(sql_text, :tag) > 0', ['tag' => $tag])->all(), 'SQL_TEXT');
    $leaks = otherSession()->select('select sql_text from v$sql where instr(sql_text, :value) > 0', ['value' => $value])->all();
    expect($texts)->toContain(...$statements)
        ->and($leaks)->toBeEmpty();
});

/**
 * Each statement kind, the row count it reports and whether it gives a
 * Cursor.
 *
 * @return iterable<string, array{string, int, bool}>
 */
dataset('statementKinds', function () {
    yield 'DDL' => ['create or replace view statement_view as select 1 as x from dual', 0, false];
    yield 'DML' => ['insert into statement_rows select level from dual connect by level <= 3', 3, false];
    yield 'query' => ['select id from statement_rows', 0, true];
    yield 'anonymous block' => ['begin update statement_rows set id = id + 1; end;', 0, false];
});

test('every statement kind returns a complete execution', function (string $sql, int $rowCount, bool $query): void {
    $connection = $this->connect();

    $execution = $connection->execute($sql, [], $this->options());

    expect($execution->rowCount())->toBe($rowCount)
        ->and($execution->cursor() instanceof Cursor)->toBe($query)
        ->and($execution->outValues())->toBeEmpty()
        ->and($execution->implicitResults())->toBeEmpty()
        ->and($execution->warning())->toBeNull();
})->with('statementKinds');

/**
 * @return iterable<string, array{RowShape, KeyCase, list<array<int|string, int>>}>
 */
dataset('rowShapes', function () {
    yield 'native keys, as Oracle reports them' => [RowShape::Assoc, KeyCase::Native, [['ONE' => 1, 'Two' => 2, 'three' => 3]]];
    yield 'lower keys' => [RowShape::Assoc, KeyCase::Lower, [['one' => 1, 'two' => 2, 'three' => 3]]];
    yield 'list rows, in column order' => [RowShape::List, KeyCase::Native, [[1, 2, 3]]];
});

test('rows take the row shape and key case of the options', function (RowShape $rowShape, KeyCase $keyCase, array $expected): void {
    $connection = $this->connect();

    $rows = $this->rows($connection, 'select 1 as one, 2 as "Two", 3 as "three" from dual', options: $this->options(rowShape: $rowShape, keyCase: $keyCase));

    expect($rows)->toBe($expected);
})->with('rowShapes');

/**
 * @return iterable<string, array{string, KeyCase}>
 */
dataset('duplicateColumns', function () {
    yield 'the same name' => ['select 1 as a, 2 as a from dual', KeyCase::Native];
    yield 'names equal once lowercased' => ['select 1 as a, 2 as "a" from dual', KeyCase::Lower];
});

test('duplicate column names under assoc fail at cursor open with a hint', function (string $sql, KeyCase $keyCase): void {
    $connection = $this->connect();
    $thrown = null;

    try {
        $connection->execute($sql, [], $this->options(keyCase: $keyCase));
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    expect($thrown?->getMessage())->toContain('alias', 'RowShape::List')
        ->and($thrown?->sql())->toBe($sql);
})->with('duplicateColumns');

test('duplicate column names read as list rows', function (): void {
    $connection = $this->connect();

    $rows = $this->rows($connection, 'select 1 as a, 2 as a from dual', options: $this->options(rowShape: RowShape::List));

    expect($rows)->toBe([[1, 2]]);
});

test('columns describe every scalar field before the first row also for an empty result', function (): void {
    $connection = $this->connect();

    $cursor = $connection->execute(<<<'SQL'
        select i.id, i.price, i.name, i.created_at, i.rowid,
               cast(null as number) unconstrained, cast(null as float(53)) float53,
               cast(null as char(5)) ch, cast(null as nchar(5)) nch, cast(null as nvarchar2(10)) nv,
               cast(null as raw(16)) r,
               cast(null as timestamp(3)) ts, cast(null as timestamp with time zone) tstz,
               cast(null as timestamp(9) with local time zone) tsltz,
               cast(null as interval day(3) to second(2)) ids, cast(null as interval year(4) to month) iym,
               cast(null as binary_float) bf, cast(null as binary_double) bd, true b,
               cast(null as urowid) ur, empty_clob() cl, to_nclob('') ncl, empty_blob() bl
          from statement_items i
         where 1 = 0
        SQL, [], $this->options())->cursor();

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
});

test('columns describe json vector object collection cursor and bfile fields', function (): void {
    $schema = strtoupper($this->config()->user);
    $connection = $this->connect();

    $cursor = $connection->execute(<<<'SQL'
        select cast(null as json) j,
               to_vector('[1,2,3]', 3, float32) v, to_vector('[1,2]', *, *) flexible,
               to_vector('[10,[1],[1.0]]', 10, float64, sparse) sparse,
               statement_point(1, 2, 'p') point, statement_numbers(1, 2) numbers,
               cursor(select 1 from dual) nested, bfilename('NO_SUCH_DIRECTORY', 'x') locator
          from dual
        SQL, [], $this->options())->cursor();

    assert($cursor !== null);
    expect($cursor->columns())->toBe([
        column('J', 'json'),
        column('V', 'vector', vectorDimensions: 3, vectorFormat: 'float32'),
        column('FLEXIBLE', 'vector'),
        column('SPARSE', 'vector', vectorDimensions: 10, vectorFormat: 'float64', sparse: true),
        column('POINT', 'object', objectType: "$schema.STATEMENT_POINT"),
        column('NUMBERS', 'collection', objectType: "$schema.STATEMENT_NUMBERS"),
        column('NESTED', 'cursor'),
        column('LOCATOR', 'bfile'),
    ]);
    $cursor->close();
});

test('columns describe long columns and follow the key case', function (): void {
    $connection = $this->connect();

    $cursor = $connection->execute(
        'select t.text, b.bytes from statement_longs t cross join statement_long_raws b',
        [],
        $this->options(keyCase: KeyCase::Lower),
    )->cursor();

    assert($cursor !== null);
    expect($cursor->columns())->toBe([column('text', 'long'), column('bytes', 'longRaw')]);
    $cursor->close();
});

/**
 * Each use of a Connection or of what it returned, prepared while it is
 * open: the prepared closure runs after close().
 *
 * @return iterable<string, array{Closure(Session): (Closure(): mixed)}>
 */
dataset('usesAfterClose', function () {
    $options = TestCase::options();
    yield 'execute()' => [static fn (Session $c): Closure => static fn (): mixed => $c->execute('select 1 from dual', [], $options)];
    yield 'executeMany()' => [static fn (Session $c): Closure => static fn (): mixed => $c->executeMany('insert into statement_rows (id) values (:id)', [['id' => 1]], $options)];
    yield 'commit()' => [static fn (Session $c): Closure => $c->commit(...)];
    yield 'rollback()' => [static fn (Session $c): Closure => $c->rollback(...)];
    yield 'trace()' => [static fn (Session $c): Closure => static function () use ($c): void {
        $c->trace(new Tracing(module: 'after close'));
    }];
    yield 'serverVersion()' => [static fn (Session $c): Closure => $c->serverVersion(...)];
    yield "a cursor's fetchBatch()" => [static fn (Session $c): Closure => started(query($c))->fetchBatch(...)];
    yield "a cursor's columns()" => [static fn (Session $c): Closure => query($c)->columns(...)];
    yield "a REF CURSOR's fetchBatch()" => [static fn (Session $c): Closure => refCursor($c)->fetchBatch(...)];
    yield "a REF CURSOR's columns()" => [static fn (Session $c): Closure => refCursor($c)->columns(...)];
    yield "a nested cursor's fetchBatch()" => [static fn (Session $c): Closure => nestedCursor($c)->fetchBatch(...)];
    yield "a nested cursor's columns()" => [static fn (Session $c): Closure => nestedCursor($c)->columns(...)];
    yield "an Implicit result's fetchBatch()" => [static fn (Session $c): Closure => implicitResult($c)->fetchBatch(...)];
    yield "an Implicit result's columns()" => [static fn (Session $c): Closure => implicitResult($c)->columns(...)];
    yield "a LOB's type()" => [static fn (Session $c): Closure => lob($c)->type(...)];
    yield "a LOB's size()" => [static fn (Session $c): Closure => lob($c)->size(...)];
    yield "a LOB's readChunk()" => [static fn (Session $c): Closure => lob($c)->readChunk(...)];
});

test('after close every call and every result fails as use after close', function (Closure $prepare): void {
    $connection = $this->connect(...$this->ownPool());
    $use = $prepare($connection);
    assert($use instanceof Closure);
    $connection->close();
    $thrown = null;

    try {
        $use();
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    }

    expect($thrown)->toBeInstanceOf(UsageException::class)
        ->and($thrown?->getMessage())->toBe('The Connection is closed.');
})->with('usesAfterClose');

test("after close the session's next owner is unaffected by what the last one left", function (Closure $prepare): void {
    $fields = $this->ownPool() + ['maxConnections' => 1];
    $connection = $this->connect(...$fields);
    $session = $this->session($connection);
    $use = $prepare($connection);
    assert($use instanceof Closure);
    $connection->close();
    expect(fn () => $use())->toThrow(UsageException::class);

    $next = $this->connect(...$fields);

    expect($this->session($next))->toBe($session)
        ->and($this->rows($next, 'select level as n from dual connect by level <= 3', [], $this->options(fetchSize: 1)))->toBe([['N' => 1], ['N' => 2], ['N' => 3]]);
})->with('usesAfterClose');

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

function query(Session $connection): Cursor
{
    $cursor = $connection->execute('select level as n from dual connect by level <= 3', [], TestCase::options(fetchSize: 1))->cursor();
    assert($cursor instanceof Cursor);

    return $cursor;
}

/**
 * A Cursor with one batch read and rows left.
 */
function started(Cursor $cursor): Cursor
{
    $cursor->fetchBatch();

    return $cursor;
}

function refCursor(Session $connection): Cursor
{
    $values = $connection->execute(
        'begin open :o for select level as n from dual connect by level <= 3; end;',
        ['o' => new Out\Cursor()],
        TestCase::options(),
    )->outValues();
    $cursor = $values['o'][0] ?? null;
    assert($cursor instanceof Cursor);

    return $cursor;
}

function nestedCursor(Session $connection): Cursor
{
    $parent = $connection->execute('select cursor(select level as n from dual connect by level <= 3) as rows_of from dual', [], TestCase::options())->cursor();
    assert($parent instanceof Cursor);
    $nested = $parent->fetchBatch()[0]['ROWS_OF'] ?? null;
    assert($nested instanceof Cursor);

    return $nested;
}

function implicitResult(Session $connection): Cursor
{
    $results = $connection->execute(
        'declare c sys_refcursor; begin open c for select level as n from dual connect by level <= 3; dbms_sql.return_result(c); end;',
        [],
        TestCase::options(),
    )->implicitResults();
    assert(isset($results[0]));

    return $results[0];
}

function lob(Session $connection): Locator
{
    $cursor = $connection->execute("select to_clob('text') as c from dual", [], TestCase::options())->cursor();
    assert($cursor instanceof Cursor);
    $lob = $cursor->fetchBatch()[0]['C'] ?? null;
    assert($lob instanceof Locator);

    return $lob;
}
