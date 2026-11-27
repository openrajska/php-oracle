<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\ScopeTest;

use Rajska\Oracle\Binds\Bindable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor as CursorContract;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Tests\Driver\TestCase;
use RuntimeException;

uses(TestCase::class);

beforeAll(function (): void {
    schema()->run('create table scope_long_rows (id number(10) primary key, text long)');
    schema()->run('create table scope_long_raw_rows (id number(10) primary key, bytes long raw)');
});

beforeEach(function (): void {
    schema()->run('truncate table scope_long_rows');
    schema()->run('truncate table scope_long_raw_rows');
});

test('long and long raw values up to 32767 bytes read back as written', function (int $bytes): void {
    $connection = $this->connect();
    $text = str_repeat('ç', intdiv($bytes, 2)) . str_repeat('x', $bytes % 2);
    $binary = substr(str_repeat("\x00\xff\x7f", $bytes), 0, $bytes);

    $connection->execute('insert into scope_long_rows (id, text) values (1, :text)', ['text' => new In\Varchar($text)], $this->options());
    $connection->execute('insert into scope_long_rows (id, text) values (2, :text)', ['text' => $text], $this->options());
    $connection->execute('insert into scope_long_raw_rows (id, bytes) values (1, :bytes)', ['bytes' => new In\Raw($binary)], $this->options());

    expect($this->rows($connection, 'select text from scope_long_rows order by id'))->toBe([['TEXT' => $text], ['TEXT' => $text]])
        ->and($this->rows($connection, 'select bytes from scope_long_raw_rows'))->toBe([['BYTES' => $binary]]);
})->with([1, 4000, 32767]);

/**
 * Writing more than 32767 bytes into a LONG or LONG RAW column takes a
 * LONG bind, which v1 does not have.
 *
 * @return iterable<string, array{Bindable, string}>
 */
dataset('longBinds', function () {
    yield 'Varchar' => [new In\Varchar(str_repeat('a', 32768)), 'over 32767 bytes; bind it with In\Clob'];
    yield 'NVarchar' => [new In\NVarchar(str_repeat('a', 32768)), 'over 32767 bytes; bind it with In\Clob'];
    yield 'Varchar IN OUT' => [new InOut\Varchar(str_repeat('a', 32768)), 'over 32767 bytes; bind it with In\Clob'];
    yield 'Raw' => [new In\Raw(str_repeat("\x00", 32768)), 'over 32767; bind them with In\Blob'];
});

test('a long bind over 32767 bytes is unsupported before any round trip', function (Bindable $bind, string $fix): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $thrown = null;

    $trips = $this->roundTrips($sid, function () use ($connection, $bind, &$thrown): void {
        try {
            $connection->execute('insert into scope_long_rows (id, text) values (1, :v)', ['v' => $bind], $this->options());
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }
    });

    assert($thrown instanceof UnsupportedException);
    expect($thrown->getMessage())->toContain("Bind 'v'")
        ->toContain($fix)
        ->toContain('more than 32767 bytes')
        ->and($trips)->toBe(0);
})->with('longBinds');

/**
 * The column types v1 describes but does not read: the query, the type its
 * column describes, the object type, and the name its error gives.
 *
 * @return iterable<string, array{string, string, ?string, string}>
 */
dataset('unreadableColumns', function () {
    yield 'BFILE' => ["select bfilename('NO_SUCH_DIRECTORY', 'x') v from dual", 'bfile', null, 'BFILE'];
    yield 'XMLTYPE' => ["select xmltype('<a>1</a>') v from dual", 'xmltype', null, 'XMLTYPE'];
    yield 'XMLTYPE, NULL' => ["select case when 1 = 0 then xmltype('<a>1</a>') end v from dual", 'xmltype', null, 'XMLTYPE'];
    yield 'SYS.ANYDATA' => ['select sys.anydata.convertnumber(5) v from dual', 'object', 'SYS.ANYDATA', 'SYS.ANYDATA'];
});

test('a column type v1 does not read is described and only fetching it fails', function (string $kind, string $sql, string $type, string | null $objectType, string $name): void {
    $connection = $this->connect();
    [$cursor, $parent] = cursorOf($kind, $connection, $sql);

    $column = $cursor->columns()[0];
    $thrown = fetchFailure($cursor);
    $parent?->close();

    expect([$column['name'], $column['type'], $column['objectType']])->toBe(['V', $type, $objectType])
        ->and($thrown->getMessage())->toContain('Column V')
        ->toContain($name)
        ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
})->with(['query', 'REF CURSOR', 'nested CURSOR()'])->with('unreadableColumns');

test('an empty result of a column type v1 does not read fetches nothing', function (string $sql, string $type, string | null $objectType): void {
    $cursor = $this->connect()->execute("$sql where 1 = 0", [], $this->options())->cursor();
    assert($cursor !== null);

    $rows = $cursor->fetchBatch();

    expect([$cursor->columns()[0]['type'], $cursor->columns()[0]['objectType']])->toBe([$type, $objectType])
        ->and($rows)->toBeEmpty();
})->with('unreadableColumns');

test('a null anydata reads as null', function (): void {
    $connection = $this->connect();

    $rows = $this->rows($connection, 'select case when 1 = 0 then sys.anydata.convertnumber(5) end v from dual');

    expect($rows)->toBe([['V' => null]]);
});

test('an anydata fetch failure never shows the value', function (): void {
    $connection = $this->connect();
    $thrown = null;

    try {
        $this->rows($connection, 'select sys.anydata.convertvarchar2(:v) v from dual', ['v' => 'secret']);
    } catch (UnsupportedException $unsupportedException) {
        $thrown = $unsupportedException;
    }

    assert($thrown instanceof UnsupportedException);
    expect($thrown->getMessage())->toContain('SYS.ANYDATA')
        ->not->toContain('secret');
});

test('an anydata bind fails naming the opaque type', function (Bindable $bind): void {
    $connection = $this->connect();
    $thrown = null;

    try {
        $connection->execute('begin :v := :v; end;', ['v' => $bind], $this->options());
    } catch (UnsupportedException $unsupportedException) {
        $thrown = $unsupportedException;
    }

    assert($thrown instanceof UnsupportedException);
    expect($thrown->getMessage())->toContain("Bind 'v'")
        ->toContain('SYS.ANYDATA is an opaque type')
        ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
})->with([
    'IN' => [new In\Struct('SYS.ANYDATA', [])],
    'OUT' => [new Out\Struct('sys.anydata')],
]);

/**
 * A cursor over $sql of the given kind, and the parent a nested cursor
 * needs open while it is read.
 *
 * @return array{CursorContract, ?CursorContract}
 */
function cursorOf(string $kind, Session $connection, string $sql): array
{
    if ($kind === 'REF CURSOR') {
        $cursor = $connection->execute("begin open :c for $sql; end;", ['c' => new Out\Cursor()], TestCase::options())->outValues()['c'][0] ?? null;
        assert($cursor instanceof CursorContract);

        return [$cursor, null];
    }
    if ($kind === 'nested CURSOR()') {
        $parent = $connection->execute("select cursor($sql) nested from dual", [], TestCase::options())->cursor();
        assert($parent instanceof CursorContract);
        $cursor = $parent->fetchBatch()[0]['NESTED'] ?? null;
        assert($cursor instanceof CursorContract);

        return [$cursor, $parent];
    }
    $cursor = $connection->execute($sql, [], TestCase::options())->cursor();
    assert($cursor instanceof CursorContract);

    return [$cursor, null];
}

function fetchFailure(CursorContract $cursor): UnsupportedException
{
    try {
        $rows = $cursor->fetchBatch();

        throw new RuntimeException('A value was fetched: ' . var_export($rows, true));
    } catch (UnsupportedException $unsupportedException) {
        return $unsupportedException;
    } finally {
        $cursor->close();
    }
}
