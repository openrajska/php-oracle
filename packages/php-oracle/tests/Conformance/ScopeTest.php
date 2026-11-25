<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ScopeTest;

use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Cursor;
use Rajska\Oracle\Bind\NVarchar;
use Rajska\Oracle\Bind\Raw;
use Rajska\Oracle\Bind\Struct;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use RuntimeException;

beforeEach(function (): void {
    $this->counter = RoundTripCounter::open();
});

afterEach(function (): void {
    $this->counter->close();
});

test('long and long raw writes up to 32767 bytes round trip', function (): void {
    $connection = $this->connect();
    $written = $this->options(autocommit: false);
    foreach ([1, 4000, 32767] as $bytes) {
        $text = str_repeat('ç', intdiv($bytes, 2)) . str_repeat('x', $bytes % 2);
        $binary = substr(str_repeat("\x00\xff\x7f", $bytes), 0, $bytes);
        $connection->execute('insert into fx_long_rows (id, text) values (:id, :text)', ['id' => 100 + $bytes, 'text' => Varchar::in($text)], $written);
        $connection->execute('insert into fx_long_rows (id, text) values (:id, :text)', ['id' => 200 + $bytes, 'text' => $text], $written);
        $connection->execute('insert into fx_long_raw_rows (id, bytes) values (:id, :bytes)', ['id' => 100 + $bytes, 'bytes' => Raw::in($binary)], $written);

        expect($this->rows($connection, 'select text from fx_long_rows where id in (:a, :b)', ['a' => 100 + $bytes, 'b' => 200 + $bytes], $written))->toBe([['TEXT' => $text], ['TEXT' => $text]], "$bytes bytes")
            ->and($this->rows($connection, 'select bytes from fx_long_raw_rows where id = :id', ['id' => 100 + $bytes], $written))->toBe([['BYTES' => $binary]], "$bytes bytes");
    }
    $connection->rollback();
});

/**
 * Writing more than 32767 bytes into a LONG or LONG RAW column takes a
 * LONG bind, which is later.
 *
 * @return iterable<string, array{Bind, string}>
 */
dataset('longBinds', function () {
    yield 'Varchar' => [Varchar::in(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
    yield 'NVarchar' => [NVarchar::in(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
    yield 'Varchar IN OUT' => [Varchar::inOut(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
    yield 'Raw' => [Raw::in(str_repeat("\x00", 32768)), 'over 32767; bind them with Blob::in()'];
});

test('a long bind over 32767 bytes is unsupported before any round trip', function (Bind $bind, string $fix): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);

    $roundTrips = $this->counter->during($sid, function () use ($connection, $bind, $fix): void {
        try {
            $connection->execute('insert into fx_long_rows (id, text) values (1000, :v)', ['v' => $bind], $this->options(autocommit: false));
            $this->fail('A LONG bind over 32767 bytes ran.');
        } catch (UnsupportedException $unsupportedException) {
            expect($unsupportedException->getMessage())->toContain("Bind 'v'")
                ->and($unsupportedException->getMessage())->toContain($fix)
                ->and($unsupportedException->getMessage())->toContain('more than 32767 bytes');
        }
    });

    expect($roundTrips)->toBe(0);
})->with('longBinds');

/**
 * @return iterable<string, array{string, string, ?string}>
 */
dataset('unfetchable', function () {
    yield 'BFILE' => ["select bfilename('NO_SUCH_DIRECTORY', 'x') v from dual", 'bfile', null];
    yield 'XMLTYPE' => ["select xmltype('<a>1</a>') v from dual", 'xmltype', null];
    yield 'XMLTYPE, NULL' => ["select case when 1 = 0 then xmltype('<a>1</a>') end v from dual", 'xmltype', null];
    yield 'SYS.ANYDATA' => ['select sys.anydata.convertnumber(5) v from dual', 'object', 'SYS.ANYDATA'];
});

test('a later or never column is described and only fetching it fails', function (string $sql, string $type, string | null $objectType): void {
    $connection = $this->connect();
    foreach ([$sql, "$sql where 1 = 0"] as $query) {
        $cursor = $connection->execute($query, [], $this->options())->cursor();
        assert($cursor !== null);
        expect([$cursor->columns()[0]['name'], $cursor->columns()[0]['type'], $cursor->columns()[0]['objectType']])->toBe(['V', $type, $objectType]);
        if ($query !== $sql) {
            // No value, nothing to fail.
            expect($cursor->fetchBatch())->toBeEmpty();
            continue;
        }
        assertFetchFails($cursor, $type === 'object' ? 'SYS.ANYDATA' : strtoupper($type));
    }

    expect($this->value($connection, 'select 1 from dual'))->toBe(1);
})->with('unfetchable');

test('an xmltype column fails on fetch in every cursor kind', function (): void {
    $connection = $this->connect();

    $out = $connection->execute(
        "begin open :c for select xmltype('<a>1</a>') v from dual; end;",
        ['c' => Cursor::out()],
        $this->options(),
    )->outValues()['c'][0];
    assert($out instanceof DriverCursor);
    expect($out->columns()[0]['type'])->toBe('xmltype');
    assertFetchFails($out, 'XMLTYPE');

    $parent = $connection->execute("select cursor(select xmltype('<a>1</a>') v from dual) nested from dual", [], $this->options())->cursor();
    assert($parent !== null);
    $nested = $parent->fetchBatch()[0]['NESTED'];
    assert($nested instanceof DriverCursor);
    expect($nested->columns()[0]['type'])->toBe('xmltype');
    assertFetchFails($nested, 'XMLTYPE');
    $parent->close();

    expect($this->value($connection, 'select 1 from dual'))->toBe(1);
});

test('anydata fails loudly and null stays null', function (): void {
    $connection = $this->connect();

    expect($this->rows($connection, 'select case when 1 = 0 then sys.anydata.convertnumber(5) end v from dual'))->toBe([['V' => null]]);

    try {
        $this->rows($connection, 'select sys.anydata.convertvarchar2(:v) v from dual', ['v' => 'secret']);
        $this->fail('An ANYDATA value was fetched.');
    } catch (UnsupportedException $unsupportedException) {
        expect($unsupportedException->getMessage())->toContain('Column V')
            ->and($unsupportedException->getMessage())->toContain('SYS.ANYDATA')
            ->and($unsupportedException->getMessage())->not->toContain('secret');
    }

    foreach (['IN' => Struct::in('SYS.ANYDATA', []), 'OUT' => Struct::out('sys.anydata')] as $direction => $bind) {
        try {
            $connection->execute('begin :v := :v; end;', ['v' => $bind], $this->options());
            $this->fail("An ANYDATA $direction bind ran.");
        } catch (UnsupportedException $e) {
            expect($e->getMessage())->toContain("Bind 'v'")
                ->and($e->getMessage())->toContain('SYS.ANYDATA is an opaque type');
        }
    }
    expect($this->value($connection, 'select 1 from dual'))->toBe(1);
});

function assertFetchFails(DriverCursor $cursor, string $type): void
{
    try {
        $rows = $cursor->fetchBatch();
        throw new RuntimeException('A value was fetched: ' . var_export($rows, true));
    } catch (UnsupportedException $unsupportedException) {
        expect($unsupportedException->getMessage())->toContain('Column V')
            ->and($unsupportedException->getMessage())->toContain($type);
    } finally {
        $cursor->close();
    }
}
