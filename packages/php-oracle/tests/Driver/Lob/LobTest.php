<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Lob\LobTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob\Type;
use Rajska\Oracle\Statement\LobMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;
use Throwable;

uses(TestCase::class);

const SMALL = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

const ALL_LOBS = <<<'SQL'
    select id, text_value, to_nclob(text_value) as national_value, binary_value
      from lob_reads where id <= 4 order by id
    SQL;

/*
 * lob_reads: row 1 small, row 2 empty, row 3 NULL, row 4 a 16 MiB CLOB and
 * BLOB (largeText(), largeBinary()).
 */
beforeEach(function (): void {
    schema()->run(<<<'SQL'
        create table lob_reads (
            id number(10) primary key,
            label varchar2(50) not null,
            text_value clob,
            binary_value blob
        )
        SQL);
    schema()->run("insert into lob_reads values (1, 'small', unistr('Hello, LOB! A\\00e7\\00e3o \\65e5\\672c'), hextoraw('DEADBEEF'))");
    schema()->run("insert into lob_reads values (2, 'empty', empty_clob(), empty_blob())");
    schema()->run("insert into lob_reads values (3, 'null', null, null)");
    schema()->run(<<<'SQL'
        declare
            l_text clob;
            l_binary blob;
            l_buffer varchar2(32767);
            l_hex varchar2(32767);
        begin
            insert into lob_reads values (4, 'large', empty_clob(), empty_blob())
            returning text_value, binary_value into l_text, l_binary;
            for i in 1 .. 262144 loop
                l_buffer := l_buffer || lpad(i, 8, '0') || ' ' || unistr('a\00e7\00e3o caf\00e9 \65e5\672c') || ' ' || rpad('x', 41, 'x') || chr(10);
                if mod(i, 400) = 0 or i = 262144 then
                    dbms_lob.writeappend(l_text, length(l_buffer), l_buffer);
                    l_buffer := null;
                end if;
            end loop;
            for k in 0 .. 8191 loop
                l_hex := l_hex || to_char(mod(k, 256), 'FM0X');
            end loop;
            for i in 1 .. 2048 loop
                dbms_lob.writeappend(l_binary, 8192, hextoraw(l_hex));
            end loop;
        end;
        SQL);
});

/**
 * Each LOB a query gives: the type, size and contents it reads as.
 *
 * @return iterable<string, array{string, Type, int, string}>
 */
dataset('lobCells', function () {
    yield 'a stored CLOB' => ['select text_value from lob_reads where id = 1', Type::Clob, 19, SMALL];
    yield 'a stored BLOB' => ['select binary_value from lob_reads where id = 1', Type::Blob, 4, "\xde\xad\xbe\xef"];
    yield 'an NCLOB, sized in UCS-2 code units' => ["select to_nclob(unistr('A\\00e7\\00e3o \\65e5\\672c \\D83D\\DE00')) from dual", Type::NClob, 10, "Ação 日本 \u{1f600}"];
    yield 'a temporary CLOB' => ["select to_clob('a') from dual", Type::Clob, 1, 'a'];
    yield 'a temporary BLOB' => ["select to_blob(hextoraw('ff')) from dual", Type::Blob, 1, "\xff"];
});

test('a lob cell is a driver lob with its type size and contents', function (string $sql, Type $type, int $size, string $contents): void {
    $connection = $this->connect();

    $lob = lob(array_values($this->rows($connection, $sql)[0])[0]);

    expect($lob->type())->toBe($type)
        ->and($lob->size())->toBe($size)
        ->and(read($lob))->toBe($contents);
})->with('lobCells');

/**
 * @return iterable<string, array{string, Type}>
 */
dataset('lobColumns', function () {
    yield 'CLOB' => ['text_value', Type::Clob];
    yield 'NCLOB' => ['to_nclob(text_value)', Type::NClob];
    yield 'BLOB' => ['binary_value', Type::Blob];
});

test('an empty lob has size 0 and reads empty with no round trip', function (string $column, Type $type): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $lob = lob(array_values($this->rows($connection, "select $column from lob_reads where id = 2")[0])[0]);
    $chunk = null;

    $trips = roundTrips($sid, static function () use ($lob, &$chunk): void {
        $chunk = $lob->readChunk();
    });

    expect($lob->type())->toBe($type)
        ->and($lob->size())->toBe(0)
        ->and($chunk)->toBe('')
        ->and($trips)->toBe(0);
})->with('lobColumns');

test('a null lob is null', function (string $column): void {
    $connection = $this->connect();

    $row = $this->rows($connection, "select $column as v from lob_reads where id = 3")[0];

    expect($row['V'])->toBeNull();
})->with('lobColumns');

/**
 * @return iterable<string, array{string, string}>
 */
dataset('largeLobs', function () {
    yield 'CLOB' => ['text_value', 'text'];
    yield 'BLOB' => ['binary_value', 'binary'];
});

test('a 16 mib lob reads back exactly at one round trip per chunk', function (string $column, string $kind): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $lob = lob(array_values($this->rows($connection, "select $column from lob_reads where id = 4")[0])[0]);
    $contents = '';
    $trips = [];

    do {
        $chunk = '';
        $trips[] = roundTrips($sid, static function () use ($lob, &$chunk): void {
            $chunk = $lob->readChunk();
        });
        $contents .= $chunk;
    } while ($chunk !== '');

    $expected = $kind === 'text' ? largeText() : largeBinary();
    expect($lob->size())->toBe(16 * 1024 * 1024)
        ->and(md5($contents))->toBe(md5($expected))
        ->and($trips)->toHaveCount(17, 'Sixteen chunks of 1 Mi, then the end')
        ->and(max($trips))->toBeLessThanOrEqual(1);
})->with('largeLobs');

/**
 * @return iterable<string, array{string, string}>
 */
dataset('chunkBoundaries', function () {
    foreach (['a chunk end inside a pair' => 'a', 'a chunk end between two pairs' => 'ab'] as $where => $prefix) {
        yield "CLOB, $where" => ['clob', $prefix];
        yield "NCLOB, $where" => ['nclob', $prefix];
    }
});

test('surrogate pairs survive at every chunk boundary', function (string $type, string $prefix): void {
    $connection = $this->connect();

    $values = $connection->execute(
        "declare
               l $type;
               g varchar2(32767);
             begin
               dbms_lob.createtemporary(l, true);
               dbms_lob.append(l, to_$type(:prefix));
               for i in 1 .. 4000 loop g := g || unistr('\\D83D\\DE00'); end loop;
               for i in 1 .. 140 loop dbms_lob.append(l, to_$type(g)); end loop;
               :l := l;
             end;",
        ['prefix' => $prefix, 'l' => $type === 'clob' ? new Out\Clob() : new Out\NClob()],
        $this->options(),
    )->outValues();

    $lob = lob($values['l'][0] ?? null);
    expect($lob->size())->toBe(\strlen($prefix) + 140 * 8000)
        ->and(read($lob))->toBe($prefix . str_repeat("\u{1f600}", 140 * 4000));
})->with('chunkBoundaries');

test('lob out binds of a block bring back driver lobs', function (): void {
    $connection = $this->connect();

    $values = $connection->execute(
        "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102')); :none := null; end;",
        ['c' => new Out\Clob(), 'n' => new Out\NClob(), 'b' => new Out\Blob(), 'none' => new Out\Clob()],
        $this->options(),
    )->outValues();

    $lobs = [lob($values['c'][0] ?? null), lob($values['n'][0] ?? null), lob($values['b'][0] ?? null)];
    expect(array_map(read(...), $lobs))->toBe(['text', "\u{65e5}\u{672c}", "\x01\x02"])
        ->and(array_map(static fn (Locator $lob): Type => $lob->type(), $lobs))->toBe([Type::Clob, Type::NClob, Type::Blob])
        ->and($values['none'])->toBe([null]);
});

test('a lob returning into of a block brings back a driver lob', function (): void {
    $connection = $this->connect();

    $values = $connection->execute(
        'begin update lob_reads set label = label where id = 1 returning text_value into :t; end;',
        ['t' => new Out\Clob()],
        $this->options(autocommit: false),
    )->outValues();

    expect(read(lob($values['t'][0] ?? null)))->toBe(SMALL);
    $connection->rollback();
});

test('a lob returning into of dml brings back one driver lob per row', function (): void {
    $connection = $this->connect();

    $values = $connection->execute(
        'update lob_reads set label = label where id in (1, 2, 3) returning id, text_value, binary_value into :id, :t, :b',
        ['id' => new Out\Number(), 't' => new Out\Clob(), 'b' => new Out\Blob()],
        $this->options(autocommit: false),
    )->outValues();

    $rows = array_map(
        static fn (mixed $id, mixed $text, mixed $binary): array => [$id, $text === null ? null : read(lob($text)), $binary === null ? null : read(lob($binary))],
        $values['id'],
        $values['t'],
        $values['b'],
    );
    usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    expect($rows)->toBe([[1, SMALL, "\xDE\xAD\xBE\xEF"], [2, '', ''], [3, null, null]]);
    $connection->rollback();
});

test('a lob does not keep the session alive', function (): void {
    $fields = ['maxConnections' => 1, 'acquireTimeout' => 0.5] + $this->ownPool();
    $connection = $this->driver()->connect($this->config(...$fields));
    $cursor = $connection->execute('select text_value from lob_reads where id = 1', [], $this->options())->cursor();
    assert($cursor !== null);
    $lob = lob($cursor->fetchBatch()[0]['TEXT_VALUE'] ?? null);
    unset($connection, $cursor);

    $this->connect(...$fields)->close();

    expect(fn (): string => $lob->readChunk())->toThrow(UsageException::class, 'The Connection is closed.');
});

/**
 * @return iterable<string, array{string}>
 */
dataset('lobReads', function () {
    yield 'type()' => ['type'];
    yield 'size()' => ['size'];
    yield 'readChunk()' => ['readChunk'];
});

test('a closed lob fails and close is idempotent', function (string $read): void {
    $lob = lob($this->rows($this->connect(), 'select binary_value from lob_reads where id = 1')[0]['BINARY_VALUE'] ?? null);
    $lob->close();
    $lob->close();

    expect(fn () => $lob->$read())->toThrow(UsageException::class, 'The Lob is closed.');
})->with('lobReads');

test('inline lob columns arrive as strings empty and null', function (): void {
    $connection = $this->connect();

    $rows = $this->rows($connection, <<<'SQL'
        select id, text_value, binary_value, to_nclob(text_value) as national_value
          from lob_reads where id <= 3 order by id
        SQL, [], inline());

    expect($rows)->toBe([
        ['ID' => 1, 'TEXT_VALUE' => SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef", 'NATIONAL_VALUE' => SMALL],
        ['ID' => 2, 'TEXT_VALUE' => '', 'BINARY_VALUE' => '', 'NATIONAL_VALUE' => ''],
        ['ID' => 3, 'TEXT_VALUE' => null, 'BINARY_VALUE' => null, 'NATIONAL_VALUE' => null],
    ]);
});

test('inline lob out binds arrive as strings empty and null', function (): void {
    $connection = $this->connect();

    $values = $connection->execute(
        "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102'));
               :e := empty_clob(); :none := null; end;",
        ['c' => new Out\Clob(), 'n' => new Out\NClob(), 'b' => new Out\Blob(), 'e' => new Out\Clob(), 'none' => new Out\Blob()],
        inline(),
    )->outValues();

    expect($values)->toBe(['c' => ['text'], 'n' => ["\u{65e5}\u{672c}"], 'b' => ["\x01\x02"], 'e' => [''], 'none' => [null]]);
});

/**
 * Inline LOBs around the 32767 the LOB prefetch brings with a row: the
 * query, and the Round trips it costs on top of the same query under
 * LobMode::Locator.
 *
 * @return iterable<string, array{string, int}>
 */
dataset('prefetchSizes', function () {
    // `Ação 日本 😀😀 `: multibyte text and surrogate pairs.
    $text = 'A\\00e7\\00e3o \\65e5\\672c \\D83D\\DE00\\D83D\\DE00 ';
    yield 'five rows of three LOBs' => [
        "select to_clob(unistr('$text') || level), to_nclob(unistr('$text') || level),"
        . " to_blob(hextoraw('dead' || to_char(level, 'fm0x'))) from dual connect by level <= 5",
        0,
    ];
    yield 'empty and NULL' => ['select text_value, to_nclob(text_value), binary_value from lob_reads where id in (2, 3)', 0];
    yield 'one LOB of 32767 characters' => ['select ' . characters(32_767) . ' from dual', 0];
    yield 'one LOB of 32768 characters' => ['select ' . characters(32_768) . ' from dual', 1];
    yield 'one LOB of 16 MiB' => ['select text_value from lob_reads where id = 4', 1];
});

test('inline lobs within the prefetch size cost no round trip of their own', function (string $sql, int $extra): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $query = fn (Options $options): int => roundTrips($sid, fn (): array => $this->rows($connection, $sql, [], $options));
    $query(locator());
    $query(inline());

    $locatorTrips = $query(locator());
    $inlineTrips = $query(inline());

    expect($inlineTrips)->toBe($locatorTrips + $extra);
})->with('prefetchSizes');

/**
 * @return iterable<string, array{string, list<list<string>>}>
 */
dataset('inlineContents', function () {
    $grins = "\u{1f600}\u{1f600}";
    yield 'multibyte text and surrogate pairs' => [
        "select to_clob(unistr('A\\00e7\\00e3o \\65e5\\672c \\D83D\\DE00\\D83D\\DE00 ') || level), to_blob(hextoraw('dead' || to_char(level, 'fm0x'))) from dual connect by level <= 3",
        array_map(static fn (int $level): array => ["Ação 日本 $grins $level", "\xde\xad" . \chr($level)], range(1, 3)),
    ];
    yield '32767 characters' => ['select ' . characters(32_767) . ' from dual', [[str_repeat('x', 32_767)]]];
    yield '32768 characters' => ['select ' . characters(32_768) . ' from dual', [[str_repeat('x', 32_768)]]];
});

test('inline lobs read whole on either side of the prefetch size', function (string $sql, array $expected): void {
    $connection = $this->connect();

    $rows = $this->rows($connection, $sql, [], inline());

    expect(array_map(array_values(...), $rows))->toBe($expected);
})->with('inlineContents');

test('an inline 16 mib lob reads whole', function (string $column, string $kind): void {
    $connection = $this->connect();

    $value = array_values($this->rows($connection, "select $column from lob_reads where id = 4", [], inline())[0])[0];

    assert(\is_string($value));
    expect(md5($value))->toBe(md5($kind === 'text' ? largeText() : largeBinary()));
})->with('largeLobs');

/**
 * How each statement gives a Cursor over a query: the query itself, a REF
 * CURSOR, a nested CURSOR() or an Implicit result.
 *
 * @return iterable<string, array{string}>
 */
dataset('cursorKinds', fn (): array => ['query' => ['query'], 'REF CURSOR' => ['ref'], 'nested CURSOR()' => ['nested'], 'Implicit result' => ['implicit']]);

test('a lob within the prefetch size reads with no round trip', function (string $kind): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    [$cursor, $parent] = cursorOf($kind, $connection, 'select text_value, to_nclob(text_value) as national_value, binary_value from lob_reads where id = 1', locator());
    $lobs = array_map(lob(...), $cursor->fetchBatch()[0] ?? []);
    $contents = [];

    $trips = roundTrips($sid, static function () use ($lobs, &$contents): void {
        $contents = array_map(read(...), $lobs);
    });

    expect($contents)->toBe(['TEXT_VALUE' => SMALL, 'NATIONAL_VALUE' => SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"])
        ->and($trips)->toBe(0);
})->with('cursorKinds');

/**
 * @return array<string, array{string, LobMode}>
 */
dataset('cursorKindsAndLobModes', function (): array {
    $cases = [];
    foreach (['REF CURSOR' => 'ref', 'nested CURSOR()' => 'nested', 'Implicit result' => 'implicit'] as $name => $kind) {
        foreach (LobMode::cases() as $mode) {
            $cases["$name, LobMode::{$mode->name}"] = [$kind, $mode];
        }
    }

    return $cases;
});

test('lob columns of a cursor read like those of a query', function (string $kind, LobMode $mode): void {
    $connection = $this->connect();
    $options = $this->options(values: new ValueMapping(lobs: $mode));
    $query = comparable($this->rows($connection, ALL_LOBS, [], $options));
    [$cursor, $parent] = cursorOf($kind, $connection, ALL_LOBS, $options);

    $rows = comparable(drain($cursor));

    expect(array_column($cursor->columns(), 'type'))->toBe(['number', 'clob', 'nClob', 'blob'])
        ->and($rows)->toBe($query)
        ->and($query)->toBe([
            ['ID' => 1, 'TEXT_VALUE' => SMALL, 'NATIONAL_VALUE' => SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"],
            ['ID' => 2, 'TEXT_VALUE' => '', 'NATIONAL_VALUE' => '', 'BINARY_VALUE' => ''],
            ['ID' => 3, 'TEXT_VALUE' => null, 'NATIONAL_VALUE' => null, 'BINARY_VALUE' => null],
            ['ID' => 4, 'TEXT_VALUE' => md5(largeText()), 'NATIONAL_VALUE' => md5(largeText()), 'BINARY_VALUE' => md5(largeBinary())],
        ]);
})->with('cursorKindsAndLobModes');

test('lob columns of a cursor under lob mode locator cost no fetch round trip of their own', function (string $kind): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $fetch = function (string $sql) use ($kind, $connection, $sid): int {
        [$cursor, $parent] = cursorOf($kind, $connection, $sql, locator());

        return roundTrips($sid, static fn (): array => drain($cursor));
    };

    $withLobs = $fetch(ALL_LOBS);
    $withoutLobs = $fetch('select id from lob_reads where id <= 4 order by id');

    expect($withLobs)->toBe($withoutLobs);
})->with(['REF CURSOR' => ['ref'], 'nested CURSOR()' => ['nested'], 'Implicit result' => ['implicit']]);

test('lob columns of a cursor under lob mode inline cost what they cost in a query', function (string $kind): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $query = function (Options $options) use ($connection, $sid): int {
        $this->rows($connection, ALL_LOBS, [], $options);

        return roundTrips($sid, fn (): array => $this->rows($connection, ALL_LOBS, [], $options));
    };
    $fetch = function (Options $options) use ($kind, $connection, $sid): int {
        [$cursor, $parent] = cursorOf($kind, $connection, ALL_LOBS, $options);

        return roundTrips($sid, static fn (): array => drain($cursor));
    };

    $cursorExtra = $fetch(inline()) - $fetch(locator());
    $queryExtra = $query(inline()) - $query(locator());

    expect($cursorExtra)->toBe($queryExtra);
})->with(['REF CURSOR' => ['ref'], 'nested CURSOR()' => ['nested'], 'Implicit result' => ['implicit']]);

test('an inline lob too large for memory fails pointing to the default mode', function (): void {
    $connection = $this->connect();
    $limit = \ini_get('memory_limit');
    \ini_set('memory_limit', (string) (memory_get_usage() + 8 * 1024 * 1024));
    $thrown = null;

    try {
        $this->rows($connection, 'select binary_value from lob_reads where id = 4', [], inline());
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    } finally {
        \ini_set('memory_limit', (string) $limit);
    }

    expect($thrown)->toBeInstanceOf(UnsupportedException::class)
        ->and($thrown?->getMessage())->toContain('Column BINARY_VALUE', 'LobMode::Locator')
        ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
});

test('a chunk too large for memory fails and the lob reads once memory allows', function (string $column): void {
    $connection = $this->connect();
    $lob = lob(array_values($this->rows($connection, "select $column from lob_reads where id = 4")[0])[0]);
    $limit = \ini_get('memory_limit');
    \ini_set('memory_limit', (string) (memory_get_usage(true) + 512 * 1024));
    $thrown = null;

    try {
        $lob->readChunk();
    } catch (Throwable $throwable) {
        $thrown = $throwable;
    } finally {
        \ini_set('memory_limit', (string) $limit);
    }

    expect($thrown)->toBeInstanceOf(UnsupportedException::class)
        ->and($thrown?->getMessage())->toContain('memory_limit')
        ->and(\strlen($lob->readChunk()))->toBeGreaterThanOrEqual(1024 * 1024);
})->with(['BLOB' => ['binary_value'], 'CLOB' => ['text_value']]);

function locator(): Options
{
    return TestCase::options(values: new ValueMapping(lobs: LobMode::Locator));
}

function inline(): Options
{
    return TestCase::options(values: new ValueMapping(lobs: LobMode::Inline));
}

/**
 * A CLOB expression of $count `x` characters.
 */
function characters(int $count): string
{
    return implode(' || ', array_fill(0, intdiv($count, 4000), "to_clob(rpad('x', 4000, 'x'))"))
        . " || rpad('x', " . ($count % 4000) . ", 'x')";
}

/**
 * The second element keeps the parent of a nested Cursor open.
 *
 * @return array{Cursor, ?Cursor}
 */
function cursorOf(string $kind, Session $connection, string $query, Options $options): array
{
    if ($kind === 'query') {
        $cursor = $connection->execute($query, [], $options)->cursor();
        assert($cursor instanceof Cursor);

        return [$cursor, null];
    }
    if ($kind === 'ref') {
        $cursor = $connection->execute("begin open :o for $query; end;", ['o' => new Out\Cursor()], $options)->outValues()['o'][0] ?? null;
        assert($cursor instanceof Cursor);

        return [$cursor, null];
    }
    if ($kind === 'nested') {
        $parent = $connection->execute("select cursor($query) as nested_rows from dual", [], $options)->cursor();
        assert($parent instanceof Cursor);
        $nested = $parent->fetchBatch()[0]['NESTED_ROWS'] ?? null;
        assert($nested instanceof Cursor);

        return [$nested, $parent];
    }
    $results = $connection->execute("declare c sys_refcursor; begin open c for $query; dbms_sql.return_result(c); end;", [], $options)->implicitResults();
    assert(isset($results[0]));

    return [$results[0], null];
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

/**
 * The rows with each LOB read to its contents, and contents longer than
 * 1 KiB replaced by their MD5, so that rows compare readably.
 *
 * @param list<array<int|string, mixed>> $rows
 *
 * @return list<array<int|string, mixed>>
 */
function comparable(array $rows): array
{
    return array_map(static fn (array $row): array => array_map(static function (mixed $value): mixed {
        $value = $value instanceof Locator ? read($value) : $value;

        return \is_string($value) && \strlen($value) > 1024 ? md5($value) : $value;
    }, $row), $rows);
}

function read(Locator $lob): string
{
    $contents = '';
    while (($chunk = $lob->readChunk()) !== '') {
        $contents .= $chunk;
    }

    return $contents;
}

function lob(mixed $value): Locator
{
    assert($value instanceof Locator);

    return $value;
}

/**
 * lob_reads row 4's CLOB: 262,144 lines of 64 characters; line n is the
 * 8-digit n, a space, `ação café 日本`, a space, 41 `x` and LF (71 bytes).
 */
function largeText(): string
{
    $tail = " a\u{e7}\u{e3}o caf\u{e9} \u{65e5}\u{672c} " . str_repeat('x', 41) . "\n";
    $text = '';
    for ($line = 1; $line <= 262_144; ++$line) {
        $text .= \sprintf('%08d', $line) . $tail;
    }

    return $text;
}

/**
 * lob_reads row 4's BLOB: the byte at offset k is k mod 256.
 */
function largeBinary(): string
{
    return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 65_536);
}
