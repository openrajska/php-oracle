<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Blob;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\Cursor;
use Rajska\Oracle\Bind\NClob;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\LobMode;
use Rajska\Oracle\LobType;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;

/**
 * Conformance catalog: LOB reads (Values 13, 14, 15; Scope 1 for NCLOB;
 * Native surface 8 and 10; Seam and rows 2), as handle cells of the Driver
 * contract (fixtures/sql/05_lob.sql), in the rows of a query, a REF CURSOR, a
 * nested `CURSOR()` or an Implicit result.
 */
final class LobsTest extends ConformanceTestCase
{
    /** `Hello, LOB! Ação 日本`: 19 characters, 23 bytes in UTF-8. */
    private const string SMALL = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    /** U+1F600, a supplemental character: two UCS-2 code units. */
    private const string GRIN = "\u{1f600}";

    /** One chunk of the native Lob, in UCS-2 code units or bytes. */
    private const int CHUNK = 1 << 20;

    /**
     * Every kind of LOB of fx_lobs as CLOB, NCLOB and BLOB: small multibyte,
     * empty, NULL, and 16 MiB (over the LOB prefetch size).
     */
    private const string ALL_LOBS = <<<'SQL'
        select id, text_value, to_nclob(text_value) as national_value, binary_value
          from fx_lobs where id <= 4 order by id
        SQL;

    /**
     * Values 13 and Seam and rows 2: a CLOB and a BLOB cell is a DriverLob
     * read chunk by chunk; the scalar cells beside it are plain values.
     */
    #[Test]
    public function a_lob_cell_is_a_driver_lob_read_chunk_by_chunk(): void
    {
        [$row] = self::rows($this->connect(), 'select id, text_value, binary_value from fx_lobs where id = 1');

        self::assertSame(1, $row['ID']);
        self::assertInstanceOf(DriverLob::class, $row['TEXT_VALUE']);
        self::assertSame(LobType::Clob, $row['TEXT_VALUE']->type());
        self::assertSame(19, $row['TEXT_VALUE']->size());
        self::assertSame(self::SMALL, self::read($row['TEXT_VALUE']));
        self::assertInstanceOf(DriverLob::class, $row['BINARY_VALUE']);
        self::assertSame(LobType::Blob, $row['BINARY_VALUE']->type());
        self::assertSame(4, $row['BINARY_VALUE']->size());
        self::assertSame("\xde\xad\xbe\xef", self::read($row['BINARY_VALUE']));
    }

    /**
     * Values 13: `EMPTY_*()` is a LOB of size 0, read with no Round trip;
     * NULL is `null`; a 1-byte LOB.
     */
    #[Test]
    public function an_empty_lob_has_size_0_and_null_is_null(): void
    {
        $connection = $this->connect();
        $rows = self::rows($connection, <<<'SQL'
            select text_value, binary_value, to_nclob(text_value) as national_value
              from fx_lobs where id in (2, 3) order by id
            SQL);

        foreach (['TEXT_VALUE' => LobType::Clob, 'BINARY_VALUE' => LobType::Blob, 'NATIONAL_VALUE' => LobType::NClob] as $column => $type) {
            $empty = $rows[0][$column];
            self::assertInstanceOf(DriverLob::class, $empty, $column);
            self::assertSame($type, $empty->type(), $column);
            self::assertSame(0, $empty->size(), $column);
            self::assertSame(0, $this->roundTrips($connection, static fn () => self::assertSame('', $empty->readChunk())), $column);
            self::assertNull($rows[1][$column], $column);
        }

        [$row] = self::rows($connection, "select to_clob('a') as c, to_blob(hextoraw('ff')) as b from dual");
        self::assertInstanceOf(DriverLob::class, $row['C']);
        self::assertInstanceOf(DriverLob::class, $row['B']);
        self::assertSame([1, 'a'], [$row['C']->size(), self::read($row['C'])]);
        self::assertSame([1, "\xff"], [$row['B']->size(), self::read($row['B'])]);
    }

    /**
     * Values 13 and Scope 1: an NCLOB reads as UTF-8; `size()` counts UCS-2
     * code units, so a supplemental character counts two.
     */
    #[Test]
    public function an_nclob_reads_as_utf8_and_size_counts_ucs2_code_units(): void
    {
        $text = 'Ação 日本 ' . self::GRIN;
        [$row] = self::rows($this->connect(), "select to_nclob(unistr('A\\00e7\\00e3o \\65e5\\672c \\D83D\\DE00')) as n from dual");

        self::assertInstanceOf(DriverLob::class, $row['N']);
        self::assertSame(LobType::NClob, $row['N']->type());
        self::assertSame(10, $row['N']->size());
        self::assertSame($text, self::read($row['N']));
    }

    /**
     * Values 13: the 16 MiB CLOB and BLOB read back exactly, each chunk in
     * at most one Round trip.
     */
    #[Test]
    public function a_16_mib_clob_and_blob_read_back_exactly_one_round_trip_per_chunk(): void
    {
        $connection = $this->connect();
        [$row] = self::rows($connection, 'select text_value, binary_value from fx_lobs where id = 4');
        $text = $row['TEXT_VALUE'];
        $binary = $row['BINARY_VALUE'];
        self::assertInstanceOf(DriverLob::class, $text);
        self::assertInstanceOf(DriverLob::class, $binary);
        self::assertSame(16 * 1024 * 1024, $text->size());
        self::assertSame(16 * 1024 * 1024, $binary->size());
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();

        try {
            foreach ([[$text, self::largeText()], [$binary, self::largeBinary()]] as [$lob, $expected]) {
                $contents = '';
                $chunks = 0;
                do {
                    $chunk = '';
                    $trips = $counter->during($sid, static function () use ($lob, &$chunk): void {
                        $chunk = $lob->readChunk();
                    });
                    self::assertLessThanOrEqual(1, $trips, 'Round trips of one chunk');
                    $contents .= $chunk;
                    ++$chunks;
                } while ($chunk !== '');
                self::assertSame(\strlen($expected), \strlen($contents));
                self::assertSame(md5($expected), md5($contents));
                self::assertSame(17, $chunks, 'Sixteen chunks of 1 Mi, then the end');
            }
        } finally {
            $counter->close();
        }
    }

    /**
     * Values 13 (C9): a supplemental character is never split, wherever a
     * chunk ends, in a CLOB and in an NCLOB, from an OUT bind too.
     */
    #[Test]
    public function surrogate_pairs_survive_at_every_chunk_boundary(): void
    {
        $connection = $this->connect();
        foreach (['a', 'ab'] as $prefix) {
            foreach (['clob' => Clob::out(), 'nclob' => NClob::out()] as $type => $bind) {
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
                    self::options(),
                )->outValues();
                $lob = $outValues['l'][0];
                self::assertInstanceOf(DriverLob::class, $lob);
                $units = \strlen($prefix) + 140 * 8000;
                self::assertSame($units, $lob->size(), "$prefix, $type");
                self::assertGreaterThan(self::CHUNK, $units);

                $contents = self::read($lob);
                self::assertSame($prefix . str_repeat(self::GRIN, 140 * 4000), $contents, "$prefix, $type");
            }
        }
    }

    /**
     * Binds: `Clob::out()`, `NClob::out()` and `Blob::out()` bring back a
     * DriverLob from PL/SQL, `RETURNING … INTO` inside a block included;
     * NULL is `null`. SQL `RETURNING … INTO` of a LOB fails loudly before
     * any Round trip (the crate cannot read it back yet).
     */
    #[Test]
    public function lob_out_binds_bring_back_driver_lobs(): void
    {
        $connection = $this->connect();
        $outValues = $connection->execute(
            "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102')); :none := null; end;",
            ['c' => Clob::out(), 'n' => NClob::out(), 'b' => Blob::out(), 'none' => Clob::out()],
            self::options(),
        )->outValues();

        self::assertSame(['text', "\u{65e5}\u{672c}", "\x01\x02"], array_map(
            static function (mixed $lob): string {
                self::assertInstanceOf(DriverLob::class, $lob);

                return self::read($lob);
            },
            [$outValues['c'][0], $outValues['n'][0], $outValues['b'][0]],
        ));
        self::assertSame([LobType::Clob, LobType::NClob, LobType::Blob], [
            self::lob($outValues['c'][0])->type(),
            self::lob($outValues['n'][0])->type(),
            self::lob($outValues['b'][0])->type(),
        ]);
        self::assertSame([null], $outValues['none']);

        $returned = $connection->execute(
            'begin update fx_lobs set label = label where id = 1 returning text_value into :t; end;',
            ['t' => Clob::out()],
            self::options(autocommit: false),
        )->outValues();
        self::assertSame(self::SMALL, self::read(self::lob($returned['t'][0])));
        $connection->rollback();

        $sid = self::sid($connection);
        $trips = $this->roundTrips($connection, static function () use ($connection): void {
            try {
                $connection->execute(
                    'update fx_lobs set label = label where id in (1, 2) returning text_value into :t',
                    ['t' => Clob::out()],
                    self::options(autocommit: false),
                );
                self::fail('A LOB came back from SQL RETURNING.');
            } catch (UnsupportedException $e) {
                self::assertStringStartsWith("OUT bind 't': a LOB cannot be read back from RETURNING … INTO yet", $e->getMessage());
            }
        }, $sid);
        self::assertSame(0, $trips, 'Fails before any Round trip');
    }

    /**
     * Values 14 and Native surface 8: after Release, every call of a LOB
     * fails as use after close, also once its Session went to another
     * Connection, which is unaffected.
     */
    #[Test]
    public function a_lob_after_release_fails_even_when_its_session_went_to_another_connection(): void
    {
        $fields = ['maxConnections' => 1] + self::ownPool();
        $connection = $this->connect(...$fields);
        $session = self::session($connection);
        [$row] = self::rows($connection, 'select text_value from fx_lobs where id = 1');
        $lob = self::lob($row['TEXT_VALUE']);
        $connection->close();

        $next = $this->connect(...$fields);
        self::assertSame($session, self::session($next), 'The same Session');
        self::assertUseAfterClose($lob);
        $lob->close();

        [$row] = self::rows($next, 'select text_value from fx_lobs where id = 1');
        self::assertSame(self::SMALL, self::read(self::lob($row['TEXT_VALUE'])));
    }

    /**
     * Values 14: a LOB does not keep its Session: once its Connection and
     * Cursor are gone, the Session goes back to the Pool, and the LOB fails.
     */
    #[Test]
    public function a_lob_does_not_keep_the_session_alive(): void
    {
        $fields = ['maxConnections' => 1, 'acquireTimeout' => 0.5] + self::ownPool();
        $config = TestDatabase::fromEnvironment()->config(...$fields);
        $connection = $this->driver()->connect($config);
        $cursor = $connection->execute('select text_value from fx_lobs where id = 1', [], self::options())->cursor();
        self::assertNotNull($cursor);
        $lob = self::lob($cursor->fetchBatch()[0]['TEXT_VALUE']);
        unset($connection, $cursor);

        // The Pool's only Session is free again.
        $this->connect(...$fields)->close();
        self::assertUseAfterClose($lob);
    }

    /**
     * Native surface 8: a closed LOB fails as use after close; `close()` is
     * idempotent.
     */
    #[Test]
    public function a_closed_lob_fails_and_close_is_idempotent(): void
    {
        [$row] = self::rows($this->connect(), 'select binary_value from fx_lobs where id = 1');
        $lob = self::lob($row['BINARY_VALUE']);
        $lob->close();
        $lob->close();

        foreach (['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)] as $call => $read) {
            try {
                $read();
                self::fail("$call after close() worked.");
            } catch (UsageException $e) {
                self::assertSame('The Lob is closed.', $e->getMessage(), $call);
            }
        }
    }

    /**
     * Values 15: `lobs: Inline` gives the same values as strings and bytes,
     * `EMPTY_*()` as `''` and NULL as `null`, OUT LOBs too.
     */
    #[Test]
    public function inline_lobs_give_strings_empty_and_null(): void
    {
        $connection = $this->connect();
        $inline = self::options(values: new ValueMapping(lobs: LobMode::Inline));
        $rows = self::rows($connection, <<<'SQL'
            select id, text_value, binary_value, to_nclob(text_value) as national_value
              from fx_lobs where id <= 3 order by id
            SQL, [], $inline);

        self::assertSame([
            ['ID' => 1, 'TEXT_VALUE' => self::SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef", 'NATIONAL_VALUE' => self::SMALL],
            ['ID' => 2, 'TEXT_VALUE' => '', 'BINARY_VALUE' => '', 'NATIONAL_VALUE' => ''],
            ['ID' => 3, 'TEXT_VALUE' => null, 'BINARY_VALUE' => null, 'NATIONAL_VALUE' => null],
        ], $rows);

        $outValues = $connection->execute(
            "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102'));
                   :e := empty_clob(); :none := null; end;",
            ['c' => Clob::out(), 'n' => NClob::out(), 'b' => Blob::out(), 'e' => Clob::out(), 'none' => Blob::out()],
            $inline,
        )->outValues();
        self::assertSame(
            ['c' => ['text'], 'n' => ["\u{65e5}\u{672c}"], 'b' => ["\x01\x02"], 'e' => [''], 'none' => [null]],
            $outValues,
        );

        [$row] = self::rows($connection, 'select text_value, binary_value from fx_lobs where id = 4', [], $inline);
        self::assertIsString($row['TEXT_VALUE']);
        self::assertIsString($row['BINARY_VALUE']);
        self::assertSame(md5(self::largeText()), md5($row['TEXT_VALUE']));
        self::assertSame(md5(self::largeBinary()), md5($row['BINARY_VALUE']));
    }

    /**
     * Values 15: under `lobs: Inline`, a query of LOBs no larger than the LOB
     * prefetch size (32767 characters or bytes) costs the Round trips of the
     * query alone, as under `lobs: Locator` with nothing read: each LOB came
     * with its locator. A LOB over the prefetch size costs one Round trip
     * more, and still reads exactly; an empty or NULL one costs none. The
     * Column types stay the LOB types.
     */
    #[Test]
    public function inline_lobs_within_the_prefetch_size_cost_no_round_trip_of_their_own(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();
        $rows = [];
        $query = static function (string $sql, LobMode $lobs) use ($connection, $counter, $sid, &$rows): int {
            return $counter->during($sid, static function () use ($connection, $sql, $lobs, &$rows): void {
                $cursor = $connection->execute($sql, [], self::options(values: new ValueMapping(lobs: $lobs)))->cursor();
                self::assertNotNull($cursor);
                $types = array_column($cursor->columns(), 'type');
                self::assertSame(\array_slice(['clob', 'nClob', 'blob'], 0, \count($types)), $types, 'The Column types stay the LOB types');
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
                self::assertSame($locator + $extra, $query($sql, LobMode::Inline), $case);
                $inline = $rows;

                if ($case === 'five rows of three LOBs') {
                    $grins = "\u{1f600}\u{1f600}";
                    self::assertSame(array_map(
                        static fn (int $level): array => ["Ação 日本 $grins $level", "Ação 日本 $grins $level", "\xde\xad" . \chr($level)],
                        range(1, 5),
                    ), array_map(array_values(...), $inline));
                } elseif (str_starts_with($case, 'one LOB of 3276')) {
                    self::assertSame(str_repeat('x', (int) substr($case, 11, 5)), $inline[0][array_key_first($inline[0])], $case);
                } elseif ($case === 'one LOB of 16 MiB') {
                    $value = $inline[0]['TEXT_VALUE'];
                    self::assertIsString($value);
                    self::assertSame(md5(self::largeText()), md5($value));
                }
            }
        } finally {
            $counter->close();
        }
    }

    /**
     * Values 13: a LOB no larger than the LOB prefetch size came with its
     * locator, so `readChunk()` reads it with no Round trip.
     */
    #[Test]
    public function a_lob_within_the_prefetch_size_reads_with_no_round_trip(): void
    {
        $connection = $this->connect();
        [$row] = self::rows($connection, 'select text_value, to_nclob(text_value) as national_value, binary_value from fx_lobs where id = 1');
        $read = [];
        $trips = $this->roundTrips($connection, static function () use ($row, &$read): void {
            $read = array_map(self::read(...), array_map(self::lob(...), $row));
        });

        self::assertSame(['TEXT_VALUE' => self::SMALL, 'NATIONAL_VALUE' => self::SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"], $read);
        self::assertSame(0, $trips);
    }

    /**
     * Values 15 (spec §12): an inline LOB too large for PHP's memory_limit
     * throws UnsupportedException, pointing to the default mode.
     */
    #[Test]
    public function an_inline_lob_too_large_for_memory_fails_pointing_to_the_default_mode(): void
    {
        $connection = $this->connect();
        $limit = \ini_get('memory_limit');
        \ini_set('memory_limit', (string) (memory_get_usage() + 8 * 1024 * 1024));

        try {
            self::rows($connection, 'select binary_value from fx_lobs where id = 4', [], self::options(values: new ValueMapping(lobs: LobMode::Inline)));
            self::fail('A 16 MiB LOB was read inline under an 8 MiB margin.');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('Column BINARY_VALUE', $e->getMessage());
            self::assertStringContainsString('LobMode::Locator', $e->getMessage());
        } finally {
            \ini_set('memory_limit', (string) $limit);
        }
        self::assertSame(1, self::value($connection, 'select 1 from dual'), 'The Connection still works');
    }

    /**
     * The Cursors a query's rows can arrive in, besides its own.
     *
     * @return array<string, array{string}>
     */
    public static function cursorKinds(): array
    {
        return ['REF CURSOR' => ['ref'], 'nested CURSOR()' => ['nested'], 'Implicit result' => ['implicit']];
    }

    /**
     * Each Cursor kind under each LobMode.
     *
     * @return array<string, array{string, LobMode}>
     */
    public static function cursorKindsAndLobModes(): array
    {
        $cases = [];
        foreach (self::cursorKinds() as $name => [$kind]) {
            foreach (LobMode::cases() as $mode) {
                $cases["$name, LobMode::{$mode->name}"] = [$kind, $mode];
            }
        }

        return $cases;
    }

    /**
     * Values 13 and 15: the CLOB, NCLOB and BLOB columns of a REF CURSOR, a
     * nested `CURSOR()` or an Implicit result read like those of the same
     * query run on its own: small multibyte, empty, NULL and 16 MiB values,
     * as `Lob`s or inline.
     */
    #[Test]
    #[DataProvider('cursorKindsAndLobModes')]
    public function lob_columns_of_a_cursor_read_like_those_of_a_query(string $kind, LobMode $mode): void
    {
        $connection = $this->connect();
        $options = self::options(values: new ValueMapping(lobs: $mode));
        $query = self::comparable(self::rows($connection, self::ALL_LOBS, [], $options));
        [$cursor] = self::cursorOf($kind, $connection, self::ALL_LOBS, $options);

        self::assertSame(['number', 'clob', 'nClob', 'blob'], array_column($cursor->columns(), 'type'));
        self::assertSame($query, self::comparable(self::drain($cursor)));
        self::assertSame([
            ['ID' => 1, 'TEXT_VALUE' => self::SMALL, 'NATIONAL_VALUE' => self::SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"],
            ['ID' => 2, 'TEXT_VALUE' => '', 'NATIONAL_VALUE' => '', 'BINARY_VALUE' => ''],
            ['ID' => 3, 'TEXT_VALUE' => null, 'NATIONAL_VALUE' => null, 'BINARY_VALUE' => null],
            ['ID' => 4, 'TEXT_VALUE' => md5(self::largeText()), 'NATIONAL_VALUE' => md5(self::largeText()), 'BINARY_VALUE' => md5(self::largeBinary())],
        ], $query);
    }

    /**
     * Values 13 and 15: the LOB columns of a REF CURSOR, a nested `CURSOR()`
     * or an Implicit result cost the Round trips they cost in a query: none
     * in the fetch under `lobs: Locator`, and under `lobs: Inline` what they
     * add to the query (one per LOB over the LOB prefetch size). A LOB
     * within the LOB prefetch size reads with no Round trip.
     */
    #[Test]
    #[DataProvider('cursorKinds')]
    public function lob_columns_of_a_cursor_cost_the_round_trips_they_cost_in_a_query(string $kind): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();
        $locator = self::options(values: new ValueMapping(lobs: LobMode::Locator));
        $inline = self::options(values: new ValueMapping(lobs: LobMode::Inline));

        try {
            $query = static function (DriverOptions $options) use ($connection, $counter, $sid): int {
                self::rows($connection, self::ALL_LOBS, [], $options);

                return $counter->during($sid, static function () use ($connection, $options): void {
                    self::rows($connection, self::ALL_LOBS, [], $options);
                });
            };
            $fetch = static function (string $sql, DriverOptions $options) use ($kind, $connection, $counter, $sid): int {
                [$cursor, $parent] = self::cursorOf($kind, $connection, $sql, $options);

                return $counter->during($sid, static function () use ($cursor): void {
                    self::drain($cursor);
                });
            };

            self::assertSame(
                $fetch('select id from fx_lobs where id <= 4 order by id', $locator),
                $fetch(self::ALL_LOBS, $locator),
                'LobMode::Locator: the fetch costs what it costs without the LOB columns',
            );
            self::assertSame(
                $query($inline) - $query($locator),
                $fetch(self::ALL_LOBS, $inline) - $fetch(self::ALL_LOBS, $locator),
                'LobMode::Inline adds what it adds to the query',
            );

            [$cursor, $parent] = self::cursorOf($kind, $connection, self::ALL_LOBS, $locator);
            $small = $cursor->fetchBatch()[0];
            unset($small['ID']);
            $read = [];
            $trips = $counter->during($sid, static function () use ($small, &$read): void {
                $read = array_map(self::read(...), array_map(self::lob(...), $small));
            });
            self::assertSame(['TEXT_VALUE' => self::SMALL, 'NATIONAL_VALUE' => self::SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"], $read);
            self::assertSame(0, $trips, 'A LOB within the LOB prefetch size reads with no Round trip');
        } finally {
            $counter->close();
        }
    }

    /**
     * Opens the Cursor of the given kind over the query's rows. The second
     * element keeps the parent of a nested Cursor open.
     *
     * @return array{DriverCursor, ?DriverCursor}
     */
    private static function cursorOf(string $kind, DriverConnection $connection, string $query, DriverOptions $options): array
    {
        if ($kind === 'ref') {
            $values = $connection->execute("begin open :o for $query; end;", ['o' => Cursor::out()], $options)->outValues()['o'] ?? [];
            self::assertInstanceOf(DriverCursor::class, $values[0] ?? null);

            return [$values[0], null];
        }
        if ($kind === 'nested') {
            $parent = $connection->execute("select cursor($query) as nested_rows from dual", [], $options)->cursor();
            self::assertNotNull($parent);
            $nested = $parent->fetchBatch()[0]['NESTED_ROWS'] ?? null;
            self::assertInstanceOf(DriverCursor::class, $nested);

            return [$nested, $parent];
        }
        $results = $connection->execute(
            "declare c sys_refcursor; begin open c for $query; dbms_sql.return_result(c); end;",
            [],
            $options,
        )->implicitResults();
        self::assertCount(1, $results);

        return [$results[0], null];
    }

    /**
     * Every remaining row of a DriverCursor.
     *
     * @return list<array<int|string, mixed>>
     */
    private static function drain(DriverCursor $cursor): array
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
    private static function comparable(array $rows): array
    {
        return array_map(static fn (array $row): array => array_map(static function (mixed $value): mixed {
            $value = $value instanceof DriverLob ? self::read($value) : $value;

            return \is_string($value) && \strlen($value) > 1024 ? md5($value) : $value;
        }, $row), $rows);
    }

    /**
     * Every chunk until the end, then '' on every later call.
     */
    private static function read(DriverLob $lob): string
    {
        $contents = '';
        while (($chunk = $lob->readChunk()) !== '') {
            $contents .= $chunk;
        }
        self::assertSame('', $lob->readChunk(), 'after the end');

        return $contents;
    }

    private static function lob(mixed $value): DriverLob
    {
        self::assertInstanceOf(DriverLob::class, $value);

        return $value;
    }

    private static function assertUseAfterClose(DriverLob $lob): void
    {
        foreach (['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)] as $call => $read) {
            try {
                $read();
                self::fail("$call after the Release worked.");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage(), $call);
            }
        }
    }

    /**
     * @param callable(): mixed $action
     */
    private function roundTrips(DriverConnection $connection, callable $action, ?int $sid = null): int
    {
        $sid ??= self::sid($connection);
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
    private static function largeText(): string
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
    private static function largeBinary(): string
    {
        return str_repeat(implode(array_map(\chr(...), range(0, 255))), 65_536);
    }
}
