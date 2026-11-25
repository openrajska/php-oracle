<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Blob;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\NClob;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Stringable;

/**
 * Binds for the LOB classes, written into a temporary LOB in chunks (C10);
 * fixtures in fixtures/sql/05_lob.sql.
 */
final class LobBindsTest extends ConformanceTestCase
{
    /** `héllo 日本 😀`: 1-, 2-, 3- and 4-byte characters. */
    private const string TEXT = "h\u{e9}llo \u{65e5}\u{672c} \u{1f600}";

    /** U+1F600, a supplemental character: 4 bytes in UTF-8. */
    private const string GRIN = "\u{1f600}";

    /** The chunk a temporary LOB is written in, in bytes. */
    private const int CHUNK = 1 << 20;

    /**
     * Each class with its PL/SQL type: the IN bind and what it reads back
     * as; the IN OUT bind, how the block changes it, and the result.
     *
     * @return iterable<string, array{string, Bind, string, Bind, string, string}>
     */
    public static function classes(): iterable
    {
        yield 'Clob' => ['clob', Clob::in(self::TEXT), self::TEXT, Clob::inOut('a'), "io || 'b'", 'ab'];
        yield 'NClob' => ['nclob', NClob::in(self::TEXT), self::TEXT, NClob::inOut('é'), "io || n'日'", 'é日'];
        yield 'Blob' => ['blob', Blob::in("\x00\xff\x80"), "\x00\xff\x80", Blob::inOut("\x01"), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
        yield 'Clob from a stream' => ['clob', Clob::in(self::stream(self::TEXT)), self::TEXT, Clob::inOut(self::stream('a')), "io || 'b'", 'ab'];
        yield 'Blob from a stream' => ['blob', Blob::in(self::stream("\x00\xff\x80")), "\x00\xff\x80", Blob::inOut(self::stream("\x01")), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
        yield 'Clob unchanged' => ['clob', Clob::in(''), '', Clob::inOut('kept'), 'io', 'kept'];
        yield 'Blob unchanged' => ['blob', Blob::in(''), '', Blob::inOut("\xff"), 'io', "\xff"];
    }

    /** An empty string is an empty LOB, not NULL. */
    #[Test]
    #[DataProvider('classes')]
    public function each_lob_class_binds_in_out_and_in_out_through_its_plsql_type(
        string $plsql,
        Bind $in,
        string $out,
        Bind $inOut,
        string $change,
        string $changed,
    ): void {
        $outBind = match ($plsql) {
            'clob' => Clob::out(),
            'nclob' => NClob::out(),
            default => Blob::out(),
        };

        $values = $this->connect()->execute(
            "declare
               procedure p(i in $plsql, o out $plsql, io in out $plsql) is
               begin
                 o := i;
                 io := $change;
               end;
             begin
               p(:i, :o, :io);
               :i_null := case when :i is null then 1 else 0 end;
             end;",
            ['i' => $in, 'o' => $outBind, 'io' => $inOut, 'i_null' => Number::out()],
            self::options(),
        )->outValues();

        self::assertSame(['o', 'io', 'i_null'], array_keys($values));
        self::assertSame($out, self::read($values['o'][0]));
        self::assertSame($changed, self::read($values['io'][0]));
        self::assertSame([0], $values['i_null'], 'Not NULL');
    }

    /**
     * @return iterable<string, array{string, Bind, Bind, Bind}>
     */
    public static function nulls(): iterable
    {
        yield 'Clob' => ['clob', Clob::in(null), Clob::out(), Clob::inOut(null)];
        yield 'NClob' => ['nclob', NClob::in(null), NClob::out(), NClob::inOut(null)];
        yield 'Blob' => ['blob', Blob::in(null), Blob::out(), Blob::inOut(null)];
    }

    #[Test]
    #[DataProvider('nulls')]
    public function each_lob_class_binds_null(string $plsql, Bind $in, Bind $out, Bind $inOut): void
    {
        $values = $this->connect()->execute(
            "declare
               procedure p(i in $plsql, o out $plsql, io in out $plsql) is
               begin
                 o := i;
               end;
             begin
               p(:i, :o, :io);
             end;",
            ['i' => $in, 'o' => $out, 'io' => $inOut],
            self::options(),
        )->outValues();

        self::assertSame(['o' => [null], 'io' => [null]], $values);
    }

    /**
     * The server compares them with fx_lobs row 4. The text has multibyte
     * characters across chunk boundaries (C10).
     */
    #[Test]
    public function a_16_mib_string_and_stream_go_through_sql_and_plsql_exactly(): void
    {
        $connection = $this->connect();
        $text = $this->largeText();
        $binary = $this->largeBinary();
        $sources = [
            'string' => static fn(): array => [$text, $binary],
            'stream' => static fn(): array => [self::stream($text), self::stream($binary)],
        ];

        foreach ($sources as $source => $values) {
            [$t, $b] = $values();
            $connection->execute(
                "insert into fx_lobs (id, label, text_value, binary_value) values (100, 'written', :t, :b)",
                ['t' => Clob::in($t), 'b' => Blob::in($b)],
                self::options(autocommit: false),
            );
            self::assertSame(
                ['T' => 0, 'B' => 0, 'N' => 0],
                self::rows($connection, <<<'SQL'
                    select dbms_lob.compare(w.text_value, r.text_value) as t,
                           dbms_lob.compare(w.binary_value, r.binary_value) as b,
                           dbms_lob.compare(to_nclob(w.text_value), to_nclob(r.text_value)) as n
                      from fx_lobs w, fx_lobs r
                     where w.id = 100 and r.id = 4
                    SQL, [], self::options(autocommit: false))[0],
                "$source, through SQL",
            );
            [$row] = self::rows($connection, 'select text_value, binary_value from fx_lobs where id = 100', [], self::options(autocommit: false));
            self::assertSame(md5($text), md5(self::read($row['TEXT_VALUE'])), "$source, read back");
            self::assertSame(md5($binary), md5(self::read($row['BINARY_VALUE'])), "$source, read back");
            $connection->rollback();

            [$t, $b] = $values();
            [$n] = $values();
            $plsql = $connection->execute(
                <<<'SQL'
                    declare
                      procedure check_lobs(p_text in clob, p_national in nclob, p_binary in blob) is
                        l_row fx_lobs%rowtype;
                      begin
                        select * into l_row from fx_lobs where id = 4;
                        :t := dbms_lob.compare(p_text, l_row.text_value);
                        :n := dbms_lob.compare(p_national, to_nclob(l_row.text_value));
                        :b := dbms_lob.compare(p_binary, l_row.binary_value);
                        :t_length := fx_lob.text_length(p_text);
                      end;
                    begin
                      check_lobs(:text, :national, :binary);
                    end;
                    SQL,
                [
                    'text' => Clob::in($t), 'national' => NClob::in($n), 'binary' => Blob::in($b),
                    't' => Number::out(), 'n' => Number::out(), 'b' => Number::out(), 't_length' => Number::out(),
                ],
                self::options(),
            )->outValues();
            self::assertSame(['t' => [0], 'n' => [0], 'b' => [0], 't_length' => [16 * 1024 * 1024]], $plsql, "$source, into PL/SQL");
        }
    }

    /** A chunk of text ends on a character boundary (C10). */
    #[Test]
    public function a_supplemental_character_survives_at_every_chunk_boundary(): void
    {
        $connection = $this->connect();
        foreach (['', 'a', 'ab', 'abc'] as $prefix) {
            $text = $prefix . str_repeat(self::GRIN, intdiv(self::CHUNK, 4) + 10);
            foreach (['string' => $text, 'stream' => self::stream($text)] as $source => $value) {
                foreach (['Clob' => Clob::in($value), 'NClob' => NClob::in(\is_string($value) ? $value : self::stream($text))] as $class => $bind) {
                    $out = $connection->execute(
                        'begin :o := :i; :units := dbms_lob.getlength(:i); end;',
                        ['i' => $bind, 'o' => $class === 'Clob' ? Clob::out() : NClob::out(), 'units' => Number::out()],
                        self::options(),
                    )->outValues();
                    $what = "prefix '$prefix', $source, $class";
                    self::assertSame([\strlen($prefix) + 2 * (intdiv(self::CHUNK, 4) + 10)], $out['units'], $what);
                    self::assertSame(md5($text), md5(self::read($out['o'][0])), $what);
                }
            }
        }
    }

    #[Test]
    public function a_large_in_out_lob_round_trips(): void
    {
        $connection = $this->connect();
        $text = str_repeat("caf\u{e9} \u{65e5}\u{672c} ", 80_000);
        $binary = random_bytes(self::CHUNK + 7);

        $values = $connection->execute(
            "begin :changed := :changed || 'end'; :bytes := :bytes; end;",
            ['changed' => Clob::inOut($text), 'bytes' => Blob::inOut(self::stream($binary))],
            self::options(),
        )->outValues();

        self::assertSame(md5($text . 'end'), md5(self::read($values['changed'][0])));
        self::assertSame(md5($binary), md5(self::read($values['bytes'][0])));
    }

    /**
     * What an execute gives back may be the temporary LOB of its bind (an
     * OUT parameter set to the IN one, a `select :c`).
     */
    #[Test]
    public function a_lob_given_back_from_a_lob_bind_stays_readable_while_held(): void
    {
        $connection = $this->connect();
        $out = $connection->execute('begin :o := :i; end;', ['i' => Clob::in('kept'), 'o' => Clob::out()], self::options())->outValues();
        $cursor = $connection->execute('select :b as b from dual', ['b' => Blob::in("\x01\x02")], self::options())->cursor();
        self::assertNotNull($cursor);
        $connection->execute('begin :n := 1; end;', ['n' => Number::out()], self::options());

        $rows = $cursor->fetchBatch();
        self::value($connection, 'select 1 from dual');

        self::assertSame('kept', self::read($out['o'][0]));
        self::assertSame("\x01\x02", self::read($rows[0]['B'] ?? null));
    }

    #[Test]
    public function a_stream_is_read_from_its_position_to_its_end(): void
    {
        $stream = self::stream('skip:kept');
        fseek($stream, 5);

        $values = $this->connect()->execute(
            'begin :o := :i; :empty := dbms_lob.getlength(:e); end;',
            ['i' => Clob::in($stream), 'o' => Clob::out(), 'e' => Blob::in(self::stream('')), 'empty' => Number::out()],
            self::options(),
        )->outValues();

        self::assertSame('kept', self::read($values['o'][0]));
        self::assertSame([0], $values['empty']);
        self::assertTrue(feof($stream));
    }

    /** A string fails before any Round trip, a stream when it gets there. */
    #[Test]
    public function text_that_is_not_utf8_fails_naming_blob(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();
        try {
            $trips = $counter->during($sid, function () use ($connection): void {
                $this->assertUsage(
                    $connection,
                    ['v' => Clob::in("ok \xff")],
                    "Bind 'v': the string is not valid UTF-8; bind binary data with Raw::in() or Blob::in().",
                );
            });
            self::assertSame(0, $trips, 'Fails before any Round trip');
        } finally {
            $counter->close();
        }

        $this->assertUsage(
            $connection,
            ['v' => NClob::in(self::stream(str_repeat('a', 10) . "\xff"))],
            "Bind 'v': the stream is not valid UTF-8 at byte 10; bind binary data with Blob::in().",
        );
        $this->assertUsage(
            $connection,
            ['v' => Clob::in(self::stream("ends inside \xe6\x97"))],
            "Bind 'v': the stream is not valid UTF-8 at byte 12; bind binary data with Blob::in().",
        );
        self::assertSame(1, self::value($connection, 'select 1 from dual'), 'The Connection still works');
    }

    /**
     * @return iterable<string, array{Closure(): Bind, non-empty-string}>
     */
    public static function wrongValues(): iterable
    {
        yield 'an int' => [static fn(): Clob => Clob::in(5), 'Clob::in() takes a string, an open stream resource or null, but was given int.']; // @phpstan-ignore argument.type (the mistake is the point)
        yield 'an array' => [static fn(): NClob => NClob::inOut([]), 'NClob::in() takes a string, an open stream resource or null, but was given array.']; // @phpstan-ignore argument.type (the mistake is the point)
        yield 'a closed stream' => [static function (): Bind {
            $stream = self::stream('x');
            fclose($stream);

            return Blob::in($stream);
        }, 'Blob::in() takes a string, an open stream resource or null, but was given resource (closed).'];
        yield 'a Stringable' => [static fn(): Clob => Clob::in(new class implements Stringable { // @phpstan-ignore argument.type (the mistake is the point)
            public function __toString(): string
            {
                return 'x';
            }
        }), 'Clob::in() takes a string, an open stream resource or null, but was given Stringable@anonymous.'];
    }

    /**
     * @param Closure(): Bind $bind
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('wrongValues')]
    public function a_value_that_is_not_a_string_or_a_stream_fails_when_the_bind_is_built(Closure $bind, string $message): void
    {
        try {
            $bind();
            self::fail('The value was accepted.');
        } catch (UsageException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /** #73. */
    #[Test]
    public function a_lob_written_by_sql_comes_back_from_returning(): void
    {
        $connection = $this->connect();
        $options = self::options(autocommit: false);
        $sql = 'update fx_lobs set text_value = :v, binary_value = :w where id = :id returning text_value, binary_value into :t, :b';

        $one = $connection->execute(
            $sql,
            ['v' => Clob::in(str_repeat('x', 100)), 'w' => Blob::in("\x00\xFF"), 'id' => 1, 't' => Clob::out(), 'b' => Blob::out()],
            $options,
        )->outValues();
        self::assertSame([str_repeat('x', 100)], array_map(self::read(...), $one['t']));
        self::assertSame(["\x00\xFF"], array_map(self::read(...), $one['b']));

        $many = $connection->executeMany($sql, [
            ['v' => Clob::in('one'), 'w' => Blob::in("\x01"), 'id' => 1, 't' => Clob::out(), 'b' => Blob::out()],
            ['v' => Clob::in('two'), 'w' => Blob::in("\x02"), 'id' => 2, 't' => Clob::out(), 'b' => Blob::out()],
        ], $options)->outValues();
        self::assertSame([['one'], ['two']], array_map(static fn(array $list): array => array_map(self::read(...), $list), $many['t']));
        self::assertSame([["\x01"], ["\x02"]], array_map(static fn(array $list): array => array_map(self::read(...), $list), $many['b']));
        $connection->rollback();
    }

    /**
     * #40: the temporary LOBs written for binds are freed on the Session's
     * next Round trip, or at the latest by the Release.
     */
    #[Test]
    public function temporary_lobs_of_binds_do_not_pile_up_or_reach_the_next_owner(): void
    {
        $pool = ['maxConnections' => 1] + self::ownPool();
        $count = 'select nvl(sum(t.cache_lobs + t.nocache_lobs + t.abstract_lobs), 0) as n from v$temporary_lobs t join v$session s on s.sid = t.sid where s.audsid = userenv(\'sessionid\')';
        $connection = $this->connect(...$pool);
        for ($i = 0; $i < 200; ++$i) {
            $connection->execute(
                'begin :n := dbms_lob.getlength(:c) + dbms_lob.getlength(:b) + length(:long); end;',
                ['c' => Clob::in("text $i"), 'b' => Blob::in(self::stream("bytes $i")), 'long' => str_repeat('x', 40_000), 'n' => Number::out()],
                self::options(),
            );
        }
        self::assertLessThanOrEqual(3, self::value($connection, $count), 'Temporary LOBs left on the Session');
        $held = $connection->execute('begin :o := :i; end;', ['i' => Clob::in('held'), 'o' => Clob::out()], self::options())->outValues();
        $connection->close();
        self::assertCount(1, $held['o']);

        $next = $this->connect(...$pool);
        self::assertSame(0, self::value($next, $count), 'Temporary LOBs the next owner finds');
    }

    /**
     * @param array<string, mixed> $binds
     */
    private function assertUsage(DriverConnection $connection, array $binds, string $message): void
    {
        try {
            $connection->execute('begin :v := :v; end;', $binds, self::options());
            self::fail('The bind was accepted.');
        } catch (UsageException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return resource
     */
    private static function stream(string $contents)
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private static function read(mixed $lob): string
    {
        self::assertInstanceOf(DriverLob::class, $lob);
        $contents = '';
        while (($chunk = $lob->readChunk()) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * fx_lobs row 4's CLOB: 262,144 lines of 64 characters (README).
     */
    private function largeText(): string
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
    private function largeBinary(): string
    {
        return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 65_536);
    }
}
