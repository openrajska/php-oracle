<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Blob;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\NClob;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob;
use Rajska\Oracle\LobMode;
use Rajska\Oracle\LobType;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;

/**
 * CLOB, NCLOB and BLOB values read as a `Lob` (`contents()`, `stream()`),
 * or inline as strings under `LobMode::Inline` (fixtures/sql/05_lob.sql,
 * fx_call.f_clob / f_nclob / f_blob).
 */
final class LobsTest extends TestCase
{
    /** `Ação 日本`. */
    private const string TEXT = "A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    /** `Hello, LOB! Ação 日本`. */
    private const string SMALL = 'Hello, LOB! ' . self::TEXT;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function a_lob_column_reads_its_contents(): void
    {
        $row = $this->connection->select('select id, text_value, binary_value from fx_lobs where id = 1')->first();
        self::assertNotNull($row);

        self::assertSame(1, $row['ID']);
        $text = self::lob($row['TEXT_VALUE']);
        self::assertSame(LobType::Clob, $text->type());
        self::assertSame(19, $text->size());
        self::assertSame(self::SMALL, $text->contents());
        $binary = self::lob($row['BINARY_VALUE']);
        self::assertSame(LobType::Blob, $binary->type());
        self::assertSame("\xde\xad\xbe\xef", $binary->contents());
    }

    /**
     * One pass, like a Cursor: what one read took, the next does not see.
     */
    #[Test]
    public function contents_and_stream_go_on_from_where_the_last_read_stopped(): void
    {
        $lob = self::lob($this->connection->select('select text_value from fx_lobs where id = 1')->value());

        self::assertSame(self::SMALL, $lob->contents());
        self::assertSame('', $lob->contents());
        self::assertSame('', stream_get_contents($lob->stream()));
    }

    #[Test]
    public function stream_reads_the_16_mib_clob_and_blob_exactly(): void
    {
        $row = $this->connection->select('select text_value, binary_value from fx_lobs where id = 4')->first();
        self::assertNotNull($row);

        $text = self::lob($row['TEXT_VALUE'])->stream();
        $hash = hash_init('md5');
        $lines = 0;
        while (($line = fgets($text)) !== false) {
            hash_update($hash, $line);
            ++$lines;
        }
        self::assertTrue(feof($text));
        fclose($text);
        self::assertSame(262_144, $lines);
        self::assertSame(md5(self::largeText()), hash_final($hash));

        $binary = self::lob($row['BINARY_VALUE'])->stream();
        self::assertSame(md5(self::largeBinary()), md5((string) stream_get_contents($binary)));
    }

    /**
     * Values 13: a 100 MiB LOB streams with flat memory (resident set size,
     * so the Extension's memory counts too).
     */
    #[Test]
    #[Group('slow')]
    public function a_100_mib_lob_streams_with_flat_memory(): void
    {
        $lob = $this->connection->run(<<<'SQL'
            declare
              l blob;
              chunk raw(16384);
            begin
              for k in 0 .. 63 loop
                chunk := utl_raw.concat(chunk, hextoraw(
                  '000102030405060708090A0B0C0D0E0F101112131415161718191A1B1C1D1E1F'
                  || '202122232425262728292A2B2C2D2E2F303132333435363738393A3B3C3D3E3F'
                  || '404142434445464748494A4B4C4D4E4F505152535455565758595A5B5C5D5E5F'
                  || '606162636465666768696A6B6C6D6E6F707172737475767778797A7B7C7D7E7F'
                  || '808182838485868788898A8B8C8D8E8F909192939495969798999A9B9C9D9E9F'
                  || 'A0A1A2A3A4A5A6A7A8A9AAABACADAEAFB0B1B2B3B4B5B6B7B8B9BABBBCBDBEBF'
                  || 'C0C1C2C3C4C5C6C7C8C9CACBCCCDCECFD0D1D2D3D4D5D6D7D8D9DADBDCDDDEDF'
                  || 'E0E1E2E3E4E5E6E7E8E9EAEBECEDEEEFF0F1F2F3F4F5F6F7F8F9FAFBFCFDFEFF'));
              end loop;
              dbms_lob.createtemporary(l, true);
              for i in 1 .. 6400 loop
                dbms_lob.writeappend(l, 16384, chunk);
              end loop;
              :l := l;
            end;
            SQL, ['l' => Blob::out()])->out('l')->lob();
        self::assertNotNull($lob);
        self::assertSame(100 * 1024 * 1024, $lob->size());

        $expected = hash_init('md5');
        $mebibyte = self::largeBinary(1);
        for ($i = 0; $i < 100; ++$i) {
            hash_update($expected, $mebibyte);
        }
        unset($mebibyte);

        $stream = $lob->stream();
        $hash = hash_init('md5');
        $start = self::residentSetSize();
        $peak = $start;
        $bytes = 0;
        while (!feof($stream)) {
            $data = (string) fread($stream, 1024 * 1024);
            hash_update($hash, $data);
            $bytes += \strlen($data);
            $peak = max($peak, self::residentSetSize());
        }
        fclose($stream);

        self::assertSame(100 * 1024 * 1024, $bytes);
        self::assertSame(hash_final($expected), hash_final($hash));
        self::assertLessThan(32 * 1024 * 1024, $peak - $start, 'Resident set growth while streaming 100 MiB');
    }

    #[Test]
    public function an_empty_lob_is_a_lob_of_size_0_and_null_is_null(): void
    {
        $rows = $this->connection->select('select text_value, binary_value from fx_lobs where id in (2, 3) order by id')->all();

        foreach (['TEXT_VALUE', 'BINARY_VALUE'] as $column) {
            $lob = self::lob($rows[0][$column]);
            self::assertSame(0, $lob->size());
            self::assertSame('', $lob->contents());
            self::assertSame('', stream_get_contents(self::lob($rows[0][$column])->stream()));
            self::assertNull($rows[1][$column]);
        }
    }

    /**
     * After the Release every call fails, a stream's read included, with no
     * PHP warning.
     */
    #[Test]
    public function after_close_a_lob_and_its_stream_fail(): void
    {
        $row = $this->connection->select('select text_value, binary_value from fx_lobs where id = 4')->first();
        self::assertNotNull($row);
        $lob = self::lob($row['TEXT_VALUE']);
        $stream = self::lob($row['BINARY_VALUE'])->stream();
        self::assertSame(8192, \strlen((string) fread($stream, 8192)));
        $this->connection->close();

        foreach ([
            'type()' => $lob->type(...),
            'size()' => $lob->size(...),
            'contents()' => $lob->contents(...),
            'stream()' => $lob->stream(...),
            'reading the stream' => static fn () => stream_get_contents($stream),
        ] as $call => $read) {
            try {
                $read();
                self::fail("$call after close() worked.");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage(), $call);
            }
        }
    }

    #[Test]
    public function out_value_lob_reads_a_lob_out_bind(): void
    {
        $execution = $this->connection->run(
            "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102')); :none := null; end;",
            ['c' => Clob::out(), 'n' => NClob::out(), 'b' => Blob::out(), 'none' => Clob::out()],
        );

        self::assertSame('text', $execution->out('c')->lob()?->contents());
        $national = $execution->out('n')->lob();
        self::assertNotNull($national);
        self::assertSame([LobType::NClob, "\u{65e5}\u{672c}"], [$national->type(), $national->contents()]);
        self::assertSame("\x01\x02", $execution->out('b')->lob()?->contents());
        self::assertNull($execution->out('none')->lob());

        try {
            $execution->out('c')->string();
            self::fail('string() read a Lob.');
        } catch (UsageException $e) {
            self::assertStringEndsWith('not a string. Read it with lob().', $e->getMessage());
        }
    }

    /**
     * Values 15: under `lobs: Inline` a LOB is a string, `EMPTY_*()` gives
     * `''`, and `lob()` names the getter to use.
     */
    #[Test]
    public function inline_lobs_are_strings(): void
    {
        $inline = new ExecuteOptions(values: new ValueMapping(lobs: LobMode::Inline));

        self::assertSame(
            [
                ['TEXT_VALUE' => self::SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"],
                ['TEXT_VALUE' => '', 'BINARY_VALUE' => ''],
                ['TEXT_VALUE' => null, 'BINARY_VALUE' => null],
            ],
            $this->connection->select('select text_value, binary_value from fx_lobs where id <= 3 order by id', [], $inline)->all(),
        );

        $execution = $this->connection->run('begin :c := to_clob(\'text\'); end;', ['c' => Clob::out()], $inline);
        self::assertSame('text', $execution->out('c')->string());
        try {
            $execution->out('c')->lob();
            self::fail('lob() read a string.');
        } catch (UsageException $e) {
            self::assertStringEndsWith('Under ValueMapping(lobs: LobMode::Inline), a LOB arrives as a string: read it with string().', $e->getMessage());
        }
    }

    #[Test]
    public function the_lob_terminals_of_a_function_call(): void
    {
        $clob = $this->connection->function('fx_call.f_clob')->clob();
        self::assertInstanceOf(Lob::class, $clob);
        self::assertSame(self::TEXT, $clob->contents());
        $nclob = $this->connection->function('fx_call.f_nclob')->nclob();
        self::assertInstanceOf(Lob::class, $nclob);
        self::assertSame([LobType::NClob, self::TEXT], [$nclob->type(), $nclob->contents()]);
        $blob = $this->connection->function('fx_call.f_blob')->blob();
        self::assertInstanceOf(Lob::class, $blob);
        self::assertSame("\xde\xad\xbe\xef", $blob->contents());
        self::assertNull($this->connection->function('fx_call.f_clob', ['p_null' => true])->clob());
        $empty = $this->connection->function('fx_call.f_blob', ['p_empty' => true])->blob();
        self::assertInstanceOf(Lob::class, $empty);
        self::assertSame(0, $empty->size());

        $inline = new ExecuteOptions(values: new ValueMapping(lobs: LobMode::Inline));
        self::assertSame(self::TEXT, $this->connection->function('fx_call.f_clob', [], $inline)->clob());
        self::assertSame(self::TEXT, $this->connection->function('fx_call.f_nclob', [], $inline)->nclob());
        self::assertSame("\xde\xad\xbe\xef", $this->connection->function('fx_call.f_blob', [], $inline)->blob());
        self::assertSame('', $this->connection->function('fx_call.f_nclob', ['p_empty' => true], $inline)->nclob());
        self::assertNull($this->connection->function('fx_call.f_blob', ['p_null' => true], $inline)->blob());
    }

    private static function lob(mixed $value): Lob
    {
        self::assertInstanceOf(Lob::class, $value);

        return $value;
    }

    /**
     * This process's resident set size, in bytes (Linux).
     */
    private static function residentSetSize(): int
    {
        $status = (string) file_get_contents('/proc/self/status');
        if (preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $match) !== 1) {
            self::fail('No VmRSS in /proc/self/status.');
        }

        return (int) $match[1] * 1024;
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
     * Bytes where the one at offset k is k mod 256 (fx_lobs row 4's BLOB is
     * 16 MiB of them).
     */
    private static function largeBinary(int $mebibytes = 16): string
    {
        return str_repeat(implode(array_map(\chr(...), range(0, 255))), 4096 * $mebibytes);
    }
}
