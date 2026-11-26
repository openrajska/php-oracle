<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\LobsTest;

use Pest\Expectation;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor as CursorContract;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob\Type;
use Rajska\Oracle\Statement\LobMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use RuntimeException;

test('a lob cell is a driver lob read chunk by chunk', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    [$row] = $this->rows($this->connect(), 'select id, text_value, binary_value from fx_lobs where id = 1');

    expect($row['ID'])->toBe(1);
    assert($row['TEXT_VALUE'] instanceof Locator);
    expect($row['TEXT_VALUE']->type())->toBe(Type::Clob)
        ->and($row['TEXT_VALUE']->size())->toBe(19)
        ->and(read($row['TEXT_VALUE']))->toBe($small);
    assert($row['BINARY_VALUE'] instanceof Locator);
    expect($row['BINARY_VALUE']->type())->toBe(Type::Blob)
        ->and($row['BINARY_VALUE']->size())->toBe(4)
        ->and(read($row['BINARY_VALUE']))->toBe("\xde\xad\xbe\xef");
});

test('an empty lob has size 0 and null is null', function (): void {
    $connection = $this->connect();
    $rows = $this->rows($connection, <<<'SQL'
            select text_value, binary_value, to_nclob(text_value) as national_value
              from fx_lobs where id in (2, 3) order by id
            SQL);

    foreach (['TEXT_VALUE' => Type::Clob, 'BINARY_VALUE' => Type::Blob, 'NATIONAL_VALUE' => Type::NClob] as $column => $type) {
        $empty = $rows[0][$column];
        assert($empty instanceof Locator);
        expect($empty->type())->toBe($type, $column)
            ->and($empty->size())->toBe(0, $column)
            ->and(roundTrips($connection, fn (): Expectation => expect($empty->readChunk())->toBeEmpty()))->toBe(0, $column)
            ->and($rows[1][$column])->toBeNull($column);
    }

    [$row] = $this->rows($connection, "select to_clob('a') as c, to_blob(hextoraw('ff')) as b from dual");
    assert($row['C'] instanceof Locator);
    assert($row['B'] instanceof Locator);
    expect([$row['C']->size(), read($row['C'])])->toBe([1, 'a'])
        ->and([$row['B']->size(), read($row['B'])])->toBe([1, "\xff"]);
});

test('an nclob reads as utf8 and size counts ucs2 code units', function (): void {
    $grin = "\u{1f600}";

    $text = 'Ação 日本 ' . $grin;
    [$row] = $this->rows($this->connect(), "select to_nclob(unistr('A\\00e7\\00e3o \\65e5\\672c \\D83D\\DE00')) as n from dual");

    assert($row['N'] instanceof Locator);
    expect($row['N']->type())->toBe(Type::NClob)
        ->and($row['N']->size())->toBe(10)
        ->and(read($row['N']))->toBe($text);
});

test('a 16 mib clob and blob read back exactly one round trip per chunk', function (): void {
    $connection = $this->connect();
    [$row] = $this->rows($connection, 'select text_value, binary_value from fx_lobs where id = 4');
    $text = $row['TEXT_VALUE'];
    $binary = $row['BINARY_VALUE'];
    assert($text instanceof Locator);
    assert($binary instanceof Locator);
    expect($text->size())->toBe(16 * 1024 * 1024)
        ->and($binary->size())->toBe(16 * 1024 * 1024);

    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();

    try {
        foreach ([[$text, largeText()], [$binary, largeBinary()]] as [$lob, $expected]) {
            $contents = '';
            $chunks = 0;
            do {
                $chunk = '';
                $trips = $counter->during($sid, static function () use ($lob, &$chunk): void {
                    $chunk = $lob->readChunk();
                });
                expect($trips)->toBeLessThanOrEqual(1, 'Round trips of one chunk');
                $contents .= $chunk;
                ++$chunks;
            } while ($chunk !== '');
            $contentBytes = \strlen($contents);
            expect($contentBytes)->toBe(\strlen($expected))
                ->and(md5($contents))->toBe(md5($expected))
                ->and($chunks)->toBe(17, 'Sixteen chunks of 1 Mi, then the end');
        }
    } finally {
        $counter->close();
    }
});

test('surrogate pairs survive at every chunk boundary', function (): void {
    $grin = "\u{1f600}";
    $chunk = 1 << 20;

    $connection = $this->connect();
    foreach (['a', 'ab'] as $prefix) {
        foreach (['clob' => new Out\Clob(), 'nclob' => new Out\NClob()] as $type => $bind) {
            // The prefix puts a chunk end inside a pair, or between two.
            $outValues = $connection->execute(
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
                ['prefix' => $prefix, 'l' => $bind],
                $this->options(),
            )->outValues();
            $lob = $outValues['l'][0];
            assert($lob instanceof Locator);
            $units = \strlen($prefix) + 140 * 8000;
            expect($lob->size())->toBe($units, "$prefix, $type")
                ->and($units)->toBeGreaterThan($chunk);

            $contents = read($lob);
            expect($contents)->toBe($prefix . str_repeat($grin, 140 * 4000), "$prefix, $type");
        }
    }
});

test('lob out binds bring back driver lobs', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $connection = $this->connect();
    $outValues = $connection->execute(
        "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102')); :none := null; end;",
        ['c' => new Out\Clob(), 'n' => new Out\NClob(), 'b' => new Out\Blob(), 'none' => new Out\Clob()],
        $this->options(),
    )->outValues();

    expect(array_map(
        function (mixed $lob): string {
            assert($lob instanceof Locator);

            return read($lob);
        },
        [$outValues['c'][0], $outValues['n'][0], $outValues['b'][0]],
    ))->toBe(['text', "\u{65e5}\u{672c}", "\x01\x02"])
        ->and([lob($outValues['c'][0])->type(), lob($outValues['n'][0])->type(), lob($outValues['b'][0])->type()])->toBe([Type::Clob, Type::NClob, Type::Blob])
        ->and($outValues['none'])->toBe([null]);

    $returned = $connection->execute(
        'begin update fx_lobs set label = label where id = 1 returning text_value into :t; end;',
        ['t' => new Out\Clob()],
        $this->options(autocommit: false),
    )->outValues();
    expect(read(lob($returned['t'][0])))->toBe($small);
    $connection->rollback();

    $returned = $connection->execute(
        'update fx_lobs set label = label where id in (1, 2, 3) returning id, text_value, binary_value into :id, :t, :b',
        ['id' => new Out\Number(), 't' => new Out\Clob(), 'b' => new Out\Blob()],
        $this->options(autocommit: false),
    )->outValues();
    $rows = array_map(null, $returned['id'], $returned['t'], $returned['b']);
    usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    expect(array_map(static fn (array $row): array => [
        $row[0],
        $row[1] === null ? null : read(lob($row[1])),
        $row[2] === null ? null : read(lob($row[2])),
    ], $rows))->toBe([[1, $small, "\xDE\xAD\xBE\xEF"], [2, '', ''], [3, null, null]]);
    $connection->rollback();
});

test('a lob after release fails even when its session went to another connection', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $fields = ['maxConnections' => 1] + $this->ownPool();
    $connection = $this->connect(...$fields);
    $session = $this->session($connection);
    [$row] = $this->rows($connection, 'select text_value from fx_lobs where id = 1');
    $lob = lob($row['TEXT_VALUE']);
    $connection->close();

    $next = $this->connect(...$fields);
    expect($this->session($next))->toBe($session, 'The same Session');
    assertUseAfterClose($lob);
    $lob->close();

    [$row] = $this->rows($next, 'select text_value from fx_lobs where id = 1');
    expect(read(lob($row['TEXT_VALUE'])))->toBe($small);
});

test('a lob does not keep the session alive', function (): void {
    $fields = ['maxConnections' => 1, 'acquireTimeout' => 0.5] + $this->ownPool();
    $config = TestDatabase::fromEnvironment()->config(...$fields);
    $connection = $this->driver()->connect($config);
    $cursor = $connection->execute('select text_value from fx_lobs where id = 1', [], $this->options())->cursor();
    assert($cursor !== null);
    $lob = lob($cursor->fetchBatch()[0]['TEXT_VALUE']);
    unset($connection, $cursor);

    // The Pool's only Session is free again.
    $this->connect(...$fields)->close();
    assertUseAfterClose($lob);
});

test('a closed lob fails and close is idempotent', function (): void {
    [$row] = $this->rows($this->connect(), 'select binary_value from fx_lobs where id = 1');
    $lob = lob($row['BINARY_VALUE']);
    $lob->close();
    $lob->close();

    foreach (['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)] as $call => $read) {
        try {
            $read();
            $this->fail("$call after close() worked.");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Lob is closed.', $call);
        }
    }
});

test('inline lobs give strings empty and null', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $connection = $this->connect();
    $inline = $this->options(values: new ValueMapping(lobs: LobMode::Inline));
    $rows = $this->rows($connection, <<<'SQL'
            select id, text_value, binary_value, to_nclob(text_value) as national_value
              from fx_lobs where id <= 3 order by id
            SQL, [], $inline);

    expect($rows)->toBe([
        ['ID' => 1, 'TEXT_VALUE' => $small, 'BINARY_VALUE' => "\xde\xad\xbe\xef", 'NATIONAL_VALUE' => $small],
        ['ID' => 2, 'TEXT_VALUE' => '', 'BINARY_VALUE' => '', 'NATIONAL_VALUE' => ''],
        ['ID' => 3, 'TEXT_VALUE' => null, 'BINARY_VALUE' => null, 'NATIONAL_VALUE' => null],
    ]);

    $outValues = $connection->execute(
        "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102'));
                   :e := empty_clob(); :none := null; end;",
        ['c' => new Out\Clob(), 'n' => new Out\NClob(), 'b' => new Out\Blob(), 'e' => new Out\Clob(), 'none' => new Out\Blob()],
        $inline,
    )->outValues();
    expect($outValues)->toBe(['c' => ['text'], 'n' => ["\u{65e5}\u{672c}"], 'b' => ["\x01\x02"], 'e' => [''], 'none' => [null]]);

    [$row] = $this->rows($connection, 'select text_value, binary_value from fx_lobs where id = 4', [], $inline);
    assert(\is_string($row['TEXT_VALUE']));
    assert(\is_string($row['BINARY_VALUE']));
    expect(md5($row['TEXT_VALUE']))->toBe(md5(largeText()))
        ->and(md5($row['BINARY_VALUE']))->toBe(md5(largeBinary()));
});

test('inline lobs within the prefetch size cost no round trip of their own', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();
    $rows = [];
    $query = function (string $sql, LobMode $lobs) use ($connection, $counter, $sid, &$rows): int {
        return $counter->during($sid, function () use ($connection, $sql, $lobs, &$rows): void {
            $cursor = $connection->execute($sql, [], $this->options(values: new ValueMapping(lobs: $lobs)))->cursor();
            assert($cursor !== null);
            $types = array_column($cursor->columns(), 'type');
            expect($types)->toBe(\array_slice(['clob', 'nClob', 'blob'], 0, \count($types)));
            $rows = $cursor->fetchBatch();
            $cursor->close();
        });
    };

    // `Ação 日本 😀😀 `: multibyte text and surrogate pairs.
    $text = 'A\\00e7\\00e3o \\65e5\\672c \\D83D\\DE00\\D83D\\DE00 ';
    $chars = static fn (int $count): string => implode(' || ', array_fill(0, intdiv($count, 4000), "to_clob(rpad('x', 4000, 'x'))"))
        . " || rpad('x', " . ($count % 4000) . ", 'x')";

    try {
        foreach ([
            'five rows of three LOBs' => [
                "select to_clob(unistr('$text') || level), to_nclob(unistr('$text') || level),"
                . " to_blob(hextoraw('dead' || to_char(level, 'fm0x'))) from dual connect by level <= 5",
                0,
            ],
            'empty and NULL' => ['select text_value, to_nclob(text_value), binary_value from fx_lobs where id in (2, 3)', 0],
            'one row, two LOBs' => ['select text_value, to_nclob(text_value), binary_value from fx_lobs where id = 1', 0],
            'one LOB of 32767 characters' => ['select ' . $chars(32_767) . ' from dual', 0],
            'one LOB of 32768 characters' => ['select ' . $chars(32_768) . ' from dual', 1],
            'one LOB of 16 MiB' => ['select text_value from fx_lobs where id = 4', 1],
        ] as $case => [$sql, $extra]) {
            $query($sql, LobMode::Locator);
            $locator = $query($sql, LobMode::Locator);
            $query($sql, LobMode::Inline);
            expect($query($sql, LobMode::Inline))->toBe($locator + $extra, $case);
            $inline = $rows;

            if ($case === 'five rows of three LOBs') {
                $grins = "\u{1f600}\u{1f600}";
                expect(array_map(array_values(...), $inline))->toBe(array_map(
                    static fn (int $level): array => ["Ação 日本 $grins $level", "Ação 日本 $grins $level", "\xde\xad" . \chr($level)],
                    range(1, 5),
                ));
            } elseif (str_starts_with($case, 'one LOB of 3276')) {
                expect($inline[0][array_key_first($inline[0])])->toBe(str_repeat('x', (int) substr($case, 11, 5)), $case);
            } elseif ($case === 'one LOB of 16 MiB') {
                $value = $inline[0]['TEXT_VALUE'];
                assert(\is_string($value));
                expect(md5($value))->toBe(md5(largeText()));
            }
        }
    } finally {
        $counter->close();
    }
});

test('a lob within the prefetch size reads with no round trip', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $connection = $this->connect();
    [$row] = $this->rows($connection, 'select text_value, to_nclob(text_value) as national_value, binary_value from fx_lobs where id = 1');
    $read = [];
    $trips = roundTrips($connection, static function () use ($row, &$read): void {
        $read = array_map(read(...), array_map(lob(...), $row));
    });

    expect($read)->toBe(['TEXT_VALUE' => $small, 'NATIONAL_VALUE' => $small, 'BINARY_VALUE' => "\xde\xad\xbe\xef"])
        ->and($trips)->toBe(0);
});

test('an inline lob too large for memory fails pointing to the default mode', function (): void {
    $connection = $this->connect();
    $limit = \ini_get('memory_limit');
    \ini_set('memory_limit', (string) (memory_get_usage() + 8 * 1024 * 1024));

    try {
        $this->rows($connection, 'select binary_value from fx_lobs where id = 4', [], $this->options(values: new ValueMapping(lobs: LobMode::Inline)));
        $this->fail('A 16 MiB LOB was read inline under an 8 MiB margin.');
    } catch (UnsupportedException $unsupportedException) {
        expect($unsupportedException->getMessage())->toContain('Column BINARY_VALUE')
            ->and($unsupportedException->getMessage())->toContain('LobMode::Locator');
    } finally {
        \ini_set('memory_limit', (string) $limit);
    }
    expect($this->value($connection, 'select 1 from dual'))->toBe(1, 'The Connection still works');
});

test('a chunk too large for memory fails instead of ending the request', function (): void {
    $connection = $this->connect();
    $cursor = $connection->execute('select binary_value, text_value from fx_lobs where id = 4', [], $this->options())->cursor();
    assert($cursor !== null);
    $row = $cursor->fetchBatch()[0];
    $limit = \ini_get('memory_limit');

    foreach (['BINARY_VALUE', 'TEXT_VALUE'] as $column) {
        $lob = lob($row[$column]);
        \ini_set('memory_limit', (string) (memory_get_usage(true) + 512 * 1024));
        try {
            $lob->readChunk();
            $this->fail("$column: a 1 MiB chunk was read under a 512 KiB margin.");
        } catch (UnsupportedException $e) {
            expect($e->getMessage())->toContain('memory_limit');
        } finally {
            \ini_set('memory_limit', (string) $limit);
        }
        expect(\strlen($lob->readChunk()))->toBeGreaterThanOrEqual(1024 * 1024, "$column reads once memory allows");
    }
});

/**
 * @return array<string, array{string}>
 */
dataset('cursorKinds', fn (): array => ['REF CURSOR' => ['ref'], 'nested CURSOR()' => ['nested'], 'Implicit result' => ['implicit']]);

/**
 * @return array<string, array{string, LobMode}>
 */
dataset('cursorKindsAndLobModes', function (): array {
    $cases = [];
    foreach (['REF CURSOR' => ['ref'], 'nested CURSOR()' => ['nested'], 'Implicit result' => ['implicit']] as $name => [$kind]) {
        foreach (LobMode::cases() as $mode) {
            $cases["$name, LobMode::{$mode->name}"] = [$kind, $mode];
        }
    }

    return $cases;
});

test('lob columns of a cursor read like those of a query', function (string $kind, LobMode $mode): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";
    $allLobs = <<<'SQL'
            select id, text_value, to_nclob(text_value) as national_value, binary_value
              from fx_lobs where id <= 4 order by id
            SQL;

    $connection = $this->connect();
    $options = $this->options(values: new ValueMapping(lobs: $mode));
    $query = comparable($this->rows($connection, $allLobs, [], $options));
    [$cursor] = cursorOf($kind, $connection, $allLobs, $options);

    expect(array_column($cursor->columns(), 'type'))->toBe(['number', 'clob', 'nClob', 'blob'])
        ->and(comparable(drain($cursor)))->toBe($query)
        ->and($query)->toBe([
            ['ID' => 1, 'TEXT_VALUE' => $small, 'NATIONAL_VALUE' => $small, 'BINARY_VALUE' => "\xde\xad\xbe\xef"],
            ['ID' => 2, 'TEXT_VALUE' => '', 'NATIONAL_VALUE' => '', 'BINARY_VALUE' => ''],
            ['ID' => 3, 'TEXT_VALUE' => null, 'NATIONAL_VALUE' => null, 'BINARY_VALUE' => null],
            ['ID' => 4, 'TEXT_VALUE' => md5(largeText()), 'NATIONAL_VALUE' => md5(largeText()), 'BINARY_VALUE' => md5(largeBinary())],
        ]);
})->with('cursorKindsAndLobModes');

test('lob columns of a cursor cost the round trips they cost in a query', function (string $kind): void {
    $smallText = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";
    $allLobs = <<<'SQL'
            select id, text_value, to_nclob(text_value) as national_value, binary_value
              from fx_lobs where id <= 4 order by id
            SQL;

    $connection = $this->connect();
    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();
    $locator = $this->options(values: new ValueMapping(lobs: LobMode::Locator));
    $inline = $this->options(values: new ValueMapping(lobs: LobMode::Inline));

    try {
        $query = function (Options $options) use ($connection, $counter, $sid, $allLobs): int {
            $this->rows($connection, $allLobs, [], $options);

            return $counter->during($sid, function () use ($connection, $options, $allLobs): void {
                $this->rows($connection, $allLobs, [], $options);
            });
        };
        $fetch = static function (string $sql, Options $options) use ($kind, $connection, $counter, $sid): int {
            [$cursor, $parent] = cursorOf($kind, $connection, $sql, $options);

            return $counter->during($sid, static function () use ($cursor): void {
                drain($cursor);
            });
        };

        expect($fetch($allLobs, $locator))->toBe($fetch('select id from fx_lobs where id <= 4 order by id', $locator), 'LobMode::Locator: the fetch costs what it costs without the LOB columns')
            ->and($fetch($allLobs, $inline) - $fetch($allLobs, $locator))->toBe($query($inline) - $query($locator), 'LobMode::Inline adds what it adds to the query');

        [$cursor, $parent] = cursorOf($kind, $connection, $allLobs, $locator);
        $small = $cursor->fetchBatch()[0];
        unset($small['ID']);
        $read = [];
        $trips = $counter->during($sid, static function () use ($small, &$read): void {
            $read = array_map(read(...), array_map(lob(...), $small));
        });
        expect($read)->toBe(['TEXT_VALUE' => $smallText, 'NATIONAL_VALUE' => $smallText, 'BINARY_VALUE' => "\xde\xad\xbe\xef"])
            ->and($trips)->toBe(0, 'A LOB within the LOB prefetch size reads with no Round trip');
    } finally {
        $counter->close();
    }
})->with('cursorKinds');

/**
 * The second element keeps the parent of a nested Cursor open.
 *
 * @return array{CursorContract, ?CursorContract}
 */
function cursorOf(string $kind, Session $connection, string $query, Options $options): array
{
    if ($kind === 'ref') {
        $values = $connection->execute("begin open :o for $query; end;", ['o' => new Out\Cursor()], $options)->outValues()['o'] ?? [];
        $cursor = $values[0] ?? null;
        assert($cursor instanceof CursorContract);

        return [$cursor, null];
    }
    if ($kind === 'nested') {
        $parent = $connection->execute("select cursor($query) as nested_rows from dual", [], $options)->cursor();
        assert($parent instanceof CursorContract);
        $nested = $parent->fetchBatch()[0]['NESTED_ROWS'] ?? null;
        assert($nested instanceof CursorContract);

        return [$nested, $parent];
    }
    $results = $connection->execute(
        "declare c sys_refcursor; begin open c for $query; dbms_sql.return_result(c); end;",
        [],
        $options,
    )->implicitResults();
    expect($results)->toHaveCount(1);

    return [$results[0], null];
}

/**
 * @return list<array<int|string, mixed>>
 */
function drain(CursorContract $cursor): array
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
    expect($lob->readChunk())->toBe('', 'after the end');

    return $contents;
}

function lob(mixed $value): Locator
{
    assert($value instanceof Locator);

    return $value;
}

function assertUseAfterClose(Locator $lob): void
{
    foreach (['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)] as $call => $read) {
        try {
            $read();
            throw new RuntimeException("$call after the Release worked.");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Connection is closed.', $call);
        }
    }
}

/**
 * @param callable(): mixed $action
 */
function roundTrips(Session $connection, callable $action, int | null $sid = null): int
{
    $sid ??= test()->sid($connection);
    $counter = RoundTripCounter::open();
    try {
        return $counter->during($sid, $action);
    } finally {
        $counter->close();
    }
}

/**
 * fx_lobs row 4's CLOB: 262,144 lines of 64 characters (README).
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
 * fx_lobs row 4's BLOB: the byte at offset k is k mod 256.
 */
function largeBinary(): string
{
    return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 65_536);
}
