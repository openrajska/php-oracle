<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\BinaryDouble;
use Rajska\Oracle\Bind\BinaryFloat;
use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Boolean;
use Rajska\Oracle\Bind\Char;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\Date;
use Rajska\Oracle\Bind\IntervalDs;
use Rajska\Oracle\Bind\IntervalYm;
use Rajska\Oracle\Bind\NChar;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\NVarchar;
use Rajska\Oracle\Bind\PlsInteger;
use Rajska\Oracle\Bind\Raw;
use Rajska\Oracle\Bind\Rowid;
use Rajska\Oracle\Bind\Timestamp;
use Rajska\Oracle\Bind\TimestampLtz;
use Rajska\Oracle\Bind\TimestampTz;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\LobMode;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\ValueMapping;

/**
 * Conformance catalog, Binds (#22) and Scope (#17): typed scalar binds IN,
 * OUT and IN OUT. Every entry of `outValues()` is the list of values the
 * bind produced: one for a PL/SQL OUT, one per returned row for RETURNING.
 */
final class TypedBindsTest extends ConformanceTestCase
{
    /**
     * Each class with its PL/SQL type: the IN bind and the OUT value it
     * becomes; the OUT bind; the IN OUT bind, how the block changes it, and
     * the result.
     *
     * @return iterable<string, array{string, Bind, mixed, Bind, Bind, string, mixed}>
     */
    public static function classes(): iterable
    {
        yield 'Varchar' => ['varchar2', Varchar::in('héllo'), 'héllo', Varchar::out(), Varchar::inOut('a'), "io || 'b'", 'ab'];
        // An unconstrained CHAR parameter takes its length from the bind: the
        // largest buffer, 32767 bytes (16383 UTF-16 code units for NCHAR).
        yield 'Char' => ['char', Char::in('AB'), str_pad('AB', 32767), Char::out(), Char::inOut('x'), "rtrim(io) || 'y'", str_pad('xy', 32767)];
        yield 'NVarchar' => ['nvarchar2', NVarchar::in('çã日😀'), 'çã日😀', NVarchar::out(), NVarchar::inOut('é'), "io || n'日'", 'é日'];
        yield 'NChar' => ['nchar', NChar::in('ç日😀'), 'ç日😀'.str_repeat(' ', 16383 - 4), NChar::out(), NChar::inOut('é'), "rtrim(io) || n'😀'", 'é😀'.str_repeat(' ', 16383 - 3)];
        yield 'Number' => ['number', Number::in('12.30'), '12.3', Number::out(), Number::inOut(41), 'io + 1', 42];
        yield 'Number from a float' => ['number', Number::in(0.1), '0.1', Number::out(), Number::inOut(0.5), 'io * 3', '1.5'];
        yield 'BinaryFloat' => ['binary_float', BinaryFloat::in(0.1), 0.1, BinaryFloat::out(), BinaryFloat::inOut(1.5), 'io * 2', 3.0];
        yield 'BinaryDouble' => ['binary_double', BinaryDouble::in(0.1), 0.1, BinaryDouble::out(), BinaryDouble::inOut(2), 'io / 4', 0.5];
        yield 'BinaryDouble NaN' => ['binary_double', BinaryDouble::in(\NAN), \NAN, BinaryDouble::out(), BinaryDouble::inOut(\INF), '-io', -\INF];
        yield 'PlsInteger' => ['pls_integer', PlsInteger::in(-2147483648), -2147483648, PlsInteger::out(), PlsInteger::inOut(41), 'io + 1', 42];
        yield 'Boolean' => ['boolean', Boolean::in(true), true, Boolean::out(), Boolean::inOut(false), 'not io', true];
        yield 'Raw' => ['raw', Raw::in("\x00\xff\x80"), "\x00\xff\x80", Raw::out(), Raw::inOut("\x01"), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
    }

    /**
     * Each class with its PL/SQL type: a NULL IN bind, an OUT bind and a NULL
     * IN OUT bind.
     *
     * @return iterable<string, array{string, Bind, Bind, Bind}>
     */
    public static function nulls(): iterable
    {
        yield 'Varchar' => ['varchar2', Varchar::in(null), Varchar::out(), Varchar::inOut(null)];
        yield 'Char' => ['char', Char::in(null), Char::out(), Char::inOut(null)];
        yield 'NVarchar' => ['nvarchar2', NVarchar::in(null), NVarchar::out(), NVarchar::inOut(null)];
        yield 'NChar' => ['nchar', NChar::in(null), NChar::out(), NChar::inOut(null)];
        yield 'Number' => ['number', Number::in(null), Number::out(), Number::inOut(null)];
        yield 'BinaryFloat' => ['binary_float', BinaryFloat::in(null), BinaryFloat::out(), BinaryFloat::inOut(null)];
        yield 'BinaryDouble' => ['binary_double', BinaryDouble::in(null), BinaryDouble::out(), BinaryDouble::inOut(null)];
        yield 'PlsInteger' => ['pls_integer', PlsInteger::in(null), PlsInteger::out(), PlsInteger::inOut(null)];
        yield 'Boolean' => ['boolean', Boolean::in(null), Boolean::out(), Boolean::inOut(null)];
        yield 'Raw' => ['raw', Raw::in(null), Raw::out(), Raw::inOut(null)];
        yield 'Rowid' => ['rowid', Rowid::in(null), Rowid::out(), Rowid::inOut(null)];
        yield 'Date' => ['date', Date::in(null), Date::out(), Date::inOut(null)];
        yield 'Timestamp' => ['timestamp', Timestamp::in(null), Timestamp::out(), Timestamp::inOut(null)];
        yield 'TimestampTz' => ['timestamp with time zone', TimestampTz::in(null), TimestampTz::out(), TimestampTz::inOut(null)];
        yield 'TimestampLtz' => ['timestamp with local time zone', TimestampLtz::in(null), TimestampLtz::out(), TimestampLtz::inOut(null)];
        yield 'IntervalDs' => ['interval day to second', IntervalDs::in(null), IntervalDs::out(), IntervalDs::inOut(null)];
        yield 'IntervalYm' => ['interval year to month', IntervalYm::in(null), IntervalYm::out(), IntervalYm::inOut(null)];
    }

    /**
     * Each temporal class with its PL/SQL type, as `classes()`: the IN bind
     * and the OUT value it becomes, the OUT bind, the IN OUT bind, how the
     * block changes it, and the result. Values as `Temporals::shown()`
     * writes them, in PHP's zone America/Sao_Paulo.
     *
     * @return iterable<string, array{string, Bind, string, Bind, Bind, string, string}>
     */
    public static function temporalClasses(): iterable
    {
        $date = new \DateTimeImmutable('2026-03-01 10:00:00.123456', new \DateTimeZone('Asia/Kathmandu'));
        $zone = 'America/Sao_Paulo';
        yield 'Date' => ['date', Date::in($date), "+2026-03-01 10:00:00.000000 $zone", Date::out(), Date::inOut($date), 'io + 1', "+2026-03-02 10:00:00.000000 $zone"];
        yield 'Timestamp' => ['timestamp', Timestamp::in($date), "+2026-03-01 10:00:00.123456 $zone", Timestamp::out(), Timestamp::inOut($date), "io + interval '0.5' second", "+2026-03-01 10:00:00.623456 $zone"];
        yield 'TimestampTz' => ['timestamp with time zone', TimestampTz::in($date), '+2026-03-01 10:00:00.123456 +05:45', TimestampTz::out(), TimestampTz::inOut($date), "io + interval '1' hour", '+2026-03-01 11:00:00.123456 +05:45'];
        // 10:00 at +05:45 is 04:15 UTC, 01:15 in São Paulo.
        yield 'TimestampLtz' => ['timestamp with local time zone', TimestampLtz::in($date), "+2026-03-01 01:15:00.123456 $zone", TimestampLtz::out(), TimestampLtz::inOut($date), "io + interval '1' hour", "+2026-03-01 02:15:00.123456 $zone"];
        $ds = new \DateInterval('P3DT4H5M6S');
        $ds->f = 0.789;
        yield 'IntervalDs' => ['interval day to second', IntervalDs::in($ds), '+0Y0M3DT4H5M6S.789000', IntervalDs::out(), IntervalDs::inOut($ds), 'io * 2', '+0Y0M6DT8H10M13S.578000'];
        $negative = new \DateInterval('PT36H');
        $negative->invert = 1;
        yield 'IntervalDs negative' => ['interval day to second', IntervalDs::in($negative), '-0Y0M1DT12H0M0S.000000', IntervalDs::out(), IntervalDs::inOut($negative), 'io * -1', '+0Y0M1DT12H0M0S.000000'];
        yield 'IntervalYm' => ['interval year to month', IntervalYm::in(new \DateInterval('P2Y5M')), '+2Y5M0DT0H0M0S.000000', IntervalYm::out(), IntervalYm::inOut(new \DateInterval('P1Y11M')), 'io * 2', '+3Y10M0DT0H0M0S.000000'];
        $months = new \DateInterval('P14M');
        $months->invert = 1;
        yield 'IntervalYm negative' => ['interval year to month', IntervalYm::in($months), '-1Y2M0DT0H0M0S.000000', IntervalYm::out(), IntervalYm::inOut($months), 'io', '-1Y2M0DT0H0M0S.000000'];
    }

    /**
     * Binds 4, Scope 2: the temporal classes IN, OUT and IN OUT through a
     * PL/SQL parameter of their type.
     */
    #[Test]
    #[DataProvider('temporalClasses')]
    public function each_temporal_class_binds_in_out_and_in_out_through_its_plsql_type(
        string $plsql,
        Bind $in,
        string $out,
        Bind $outBind,
        Bind $inOut,
        string $change,
        string $changed,
    ): void {
        Temporals::inZone('America/Sao_Paulo', function () use ($plsql, $in, $out, $outBind, $inOut, $change, $changed): void {
            $execution = $this->connect()->execute(
                "declare
                   procedure p(i in $plsql, o out $plsql, io in out $plsql) is
                   begin
                     o := i;
                     io := $change;
                   end;
                 begin
                   p(:i, :o, :io);
                 end;",
                ['i' => $in, 'o' => $outBind, 'io' => $inOut],
                self::options(),
            );

            $values = $execution->outValues();
            self::assertSame(['o' => [$out], 'io' => [$changed]], array_map(
                static fn (array $list): array => array_map(Temporals::shown(...), $list),
                $values,
            ));
        });
    }

    /**
     * Binds 10: with the Session zone (São Paulo) other than the object's
     * (Tokyo), `Date` and `Timestamp` store the Wall clock and `Date` drops
     * the fraction, while TSTZ and TSLTZ store the Instant.
     */
    #[Test]
    public function wall_clock_types_store_the_wall_clock_and_zoned_types_the_instant(): void
    {
        Temporals::inZone('America/Sao_Paulo', function (): void {
            $connection = $this->connect();
            $tokyo = new \DateTimeImmutable('2026-03-01 10:00:00.123456', new \DateTimeZone('Asia/Tokyo'));

            $execution = $connection->execute(
                "declare
                   d date := :d;
                   t timestamp(9) := :t;
                   tz timestamp(9) with time zone := :tz;
                   ltz timestamp(9) with local time zone := :ltz;
                   f varchar2(40) := 'YYYY-MM-DD HH24:MI:SS.FF6';
                 begin
                   :d_out := to_char(cast(d as timestamp), f);
                   :t_out := to_char(t, f);
                   :tz_out := to_char(sys_extract_utc(tz), f);
                   :ltz_out := to_char(sys_extract_utc(ltz), f);
                 end;",
                [
                    'd' => Date::in($tokyo), 't' => Timestamp::in($tokyo),
                    'tz' => TimestampTz::in($tokyo), 'ltz' => TimestampLtz::in($tokyo),
                    'd_out' => Varchar::out(), 't_out' => Varchar::out(),
                    'tz_out' => Varchar::out(), 'ltz_out' => Varchar::out(),
                ],
                self::options(),
            );

            self::assertSame([
                'd_out' => ['2026-03-01 10:00:00.000000'],
                't_out' => ['2026-03-01 10:00:00.123456'],
                'tz_out' => ['2026-03-01 01:00:00.123456'],
                'ltz_out' => ['2026-03-01 01:00:00.123456'],
            ], $execution->outValues());
        });
    }

    /**
     * Binds 11: `IntervalDs` rejects years and months, `IntervalYm` days and
     * smaller units, when the bind is built: before any Round trip.
     *
     * @return iterable<string, array{\Closure(): Bind, non-empty-string}>
     */
    public static function incompatibleIntervals(): iterable
    {
        yield 'IntervalDs with years' => [static fn () => IntervalDs::in(new \DateInterval('P1Y')), 'IntervalDs::in() takes days and smaller units'];
        yield 'IntervalDs with months' => [static fn () => IntervalDs::inOut(new \DateInterval('P1M2D')), 'IntervalDs::in() takes days and smaller units'];
        yield 'IntervalYm with days' => [static fn () => IntervalYm::in(new \DateInterval('P1Y1D')), 'IntervalYm::in() takes years and months only'];
        yield 'IntervalYm with seconds' => [static fn () => IntervalYm::inOut(new \DateInterval('PT1S')), 'IntervalYm::in() takes years and months only'];
        $fraction = new \DateInterval('P1Y');
        $fraction->f = 0.5;
        yield 'IntervalYm with a fraction' => [static fn () => IntervalYm::in($fraction), 'IntervalYm::in() takes years and months only'];
    }

    /**
     * @param \Closure(): Bind $bind
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('incompatibleIntervals')]
    public function an_incompatible_date_interval_fails_when_the_bind_is_built(\Closure $bind, string $message): void
    {
        try {
            $bind();
            self::fail('The interval was accepted.');
        } catch (UsageException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }
    }

    /**
     * Binds 4, Scope 2: IN, OUT and IN OUT through a PL/SQL parameter of the
     * class's type.
     */
    #[Test]
    #[DataProvider('classes')]
    public function each_class_binds_in_out_and_in_out_through_its_plsql_type(
        string $plsql,
        Bind $in,
        mixed $out,
        Bind $outBind,
        Bind $inOut,
        string $change,
        mixed $changed,
    ): void {
        $connection = $this->connect();

        $execution = $connection->execute(
            "declare
               procedure p(i in $plsql, o out $plsql, io in out $plsql) is
               begin
                 o := i;
                 io := $change;
               end;
             begin
               p(:i, :o, :io);
             end;",
            ['i' => $in, 'o' => $outBind, 'io' => $inOut],
            self::options(),
        );

        $values = $execution->outValues();
        self::assertSame(['o', 'io'], array_keys($values));
        self::assertSameValue($out, $values['o'][0]);
        self::assertSameValue($changed, $values['io'][0]);
    }

    /**
     * Binds 4, Scope 2: a typed NULL, IN and IN OUT, gives NULL back.
     */
    #[Test]
    #[DataProvider('nulls')]
    public function each_class_binds_null(string $plsql, Bind $in, Bind $out, Bind $inOut): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
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
        );

        self::assertSame(['o' => [null], 'io' => [null]], $execution->outValues());
    }

    /**
     * Binds 4, Scope 2: ROWID.
     */
    #[Test]
    public function a_rowid_binds_in_out_and_in_out(): void
    {
        $connection = $this->connect();
        $rowid = self::value($connection, 'select rowidtochar(rowid) from fx_items where id = 1');
        self::assertIsString($rowid);

        $execution = $connection->execute(
            'declare
               procedure p(i in rowid, o out rowid, io in out rowid) is
               begin
                 select name into :name from fx_items where rowid = i;
                 o := i;
                 io := io;
               end;
             begin
               p(:i, :o, :io);
             end;',
            ['i' => Rowid::in($rowid), 'o' => Rowid::out(), 'io' => Rowid::inOut($rowid), 'name' => Varchar::out()],
            self::options(),
        );

        self::assertSame(['o' => [$rowid], 'io' => [$rowid], 'name' => ['Item 0001']], $execution->outValues());
    }

    /**
     * Same value and PHP type; NaN equals NaN.
     */
    private static function assertSameValue(mixed $expected, mixed $actual): void
    {
        if (\is_float($expected) && is_nan($expected)) {
            self::assertIsFloat($actual);
            self::assertNan($actual);

            return;
        }
        self::assertSame($expected, $actual);
    }

    /**
     * Scope 4: one row.
     */
    #[Test]
    public function insert_returning_into_gives_the_one_row(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            'insert into fx_bind_rows (id, name) values (:id, :name) returning id, name into :rid, :rname',
            ['id' => 7, 'name' => 'seven', 'rid' => Number::out(), 'rname' => Varchar::out()],
            self::options(),
        );

        self::assertSame(1, $execution->rowCount());
        self::assertSame(['rid' => [7], 'rname' => ['seven']], $execution->outValues());
    }

    /**
     * Scope 4: several rows, and none.
     */
    #[Test]
    public function update_returning_into_gives_every_row(): void
    {
        $connection = $this->connect();

        $two = $connection->execute(
            'update fx_items set price = price where id in (1, 2) returning id, name into :id, :name',
            ['id' => Number::out(), 'name' => Varchar::out()],
            self::options(),
        )->outValues();
        $none = $connection->execute(
            'update fx_items set price = price where id < 0 returning id into :id',
            ['id' => Number::out()],
            self::options(),
        )->outValues();

        self::assertEqualsCanonicalizing([1, 2], $two['id']);
        self::assertEqualsCanonicalizing(['Item 0001', 'Item 0002'], $two['name']);
        self::assertSame(['id' => []], $none);
    }

    /**
     * Risk: text RETURNING on a STANDARD max_string_size database, whose
     * longest VARCHAR2 in SQL is 4000 bytes.
     */
    #[Test]
    public function returning_reads_the_longest_sql_varchar2(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            "update fx_items set price = price where id = 1 returning rpad('x', 4000, 'x') into :name",
            ['name' => Varchar::out()],
            self::options(),
        );

        self::assertSame(['name' => [str_repeat('x', 4000)]], $execution->outValues());
    }

    /**
     * Risk resolved: VARCHAR2 binds of 4000 to 32767 bytes in SQL on a
     * STANDARD max_string_size database. The server takes them (the crate
     * sends them as long binds), a column of 4000 bytes rejects them with
     * ORA-12899, and a RETURNING buffer of 32767 bytes reads past 4000.
     */
    #[Test]
    public function varchar2_binds_over_4000_bytes_work_in_sql_on_a_standard_database(): void
    {
        $connection = $this->connect();
        try {
            $connection->execute("select cast('x' as varchar2(4001)) from dual", [], self::options());
            self::fail('The database has max_string_size = EXTENDED; this case needs STANDARD.');
        } catch (QueryException $e) {
            self::assertSame(910, $e->getCode());
        }

        foreach ([4000, 4001, 32767] as $bytes) {
            $text = str_repeat('y', $bytes);
            foreach (['inferred' => $text, 'typed' => Varchar::in($text)] as $kind => $bind) {
                self::assertSame($bytes, self::value($connection, 'select length(:v) from dual', ['v' => $bind]), "$kind $bytes");
                self::assertSame($text, self::value($connection, 'select :v from dual', ['v' => $bind]), "$kind $bytes");
            }
        }

        $returned = $connection->execute(
            'update fx_items set price = price where id = 1 returning name || :v into :o',
            ['v' => str_repeat('y', 8000), 'o' => Varchar::out()],
            self::options(),
        )->outValues();
        self::assertSame(['o' => ['Item 0001'.str_repeat('y', 8000)]], $returned);

        try {
            $connection->execute('insert into fx_bind_rows (name) values (:v)', ['v' => Varchar::in(str_repeat('y', 4001))], self::options());
            self::fail('A 4001-byte value went into a 4000-byte column.');
        } catch (QueryException $e) {
            self::assertSame(12899, $e->getCode());
        }
    }

    /**
     * Binds 5: the largest OUT, in bytes and in 2-byte characters.
     */
    #[Test]
    public function a_varchar_out_holds_32767_bytes(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            "begin
               :ascii := rpad('a', 32767, 'a');
               :multibyte := rpad(unistr('\\00e7'), 16383, unistr('\\00e7'));
             end;",
            ['ascii' => Varchar::out(), 'multibyte' => Varchar::out()],
            self::options(),
        );

        self::assertSame(
            ['ascii' => [str_repeat('a', 32767)], 'multibyte' => [str_repeat('ç', 16383)]],
            $execution->outValues(),
        );
    }

    /**
     * Binds 5: an IN OUT grows to the largest buffer from NULL and from 'a'.
     */
    #[Test]
    public function a_varchar_in_out_grows_to_32767_bytes(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            "begin
               :from_null := rpad(nvl(:from_null, 'b'), 32767, 'b');
               :from_a := rpad(:from_a, 32767, 'b');
             end;",
            ['from_null' => Varchar::inOut(null), 'from_a' => Varchar::inOut('a')],
            self::options(),
        );

        self::assertSame(
            ['from_null' => [str_repeat('b', 32767)], 'from_a' => ['a'.str_repeat('b', 32766)]],
            $execution->outValues(),
        );
    }

    /**
     * Binds 6: CHAR compares blank-padded, VARCHAR2 does not.
     */
    #[Test]
    public function a_char_matches_a_blank_padded_char(): void
    {
        $connection = $this->connect();
        $sql = "select count(*) from (select cast('AB' as char(5)) code from dual) where code = :code";

        self::assertSame(1, self::value($connection, $sql, ['code' => Char::in('AB')]));
        self::assertSame(0, self::value($connection, $sql, ['code' => 'AB']));
        self::assertSame(0, self::value($connection, $sql, ['code' => Varchar::in('AB')]));
        self::assertSame('Typ=96 Len=2: 65,66', self::value($connection, 'select dump(:code) from dual', ['code' => Char::in('AB')]));
    }

    /**
     * A Char OUT read back from a CHAR(5) keeps exactly its own padding, as
     * a CHAR column does.
     */
    #[Test]
    public function a_char_out_keeps_the_padding_it_has(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            "declare c char(5) := 'AB'; n nchar(4) := n'\u{e7}'; begin :c := c; :n := n; end;",
            ['c' => Char::out(), 'n' => NChar::out()],
            self::options(),
        );

        self::assertSame(['c' => ['AB   '], 'n' => ['ç   ']], $execution->outValues());
    }

    /**
     * Binds 7: 2-, 3- and 4-byte UTF-8 round-trip through the national
     * character set.
     */
    #[Test]
    public function nvarchar_and_nchar_round_trip_multibyte_text(): void
    {
        $connection = $this->connect();

        foreach (['ç', '日本', '😀', 'ç日😀'] as $text) {
            foreach ([NVarchar::in($text), NChar::in($text)] as $bind) {
                $name = $bind::class;
                self::assertSame($text, self::value($connection, 'select :v from dual', ['v' => $bind]), $name);
                $dump = self::value($connection, 'select dump(:v, 1016) from dual', ['v' => $bind]);
                self::assertIsString($dump);
                self::assertStringContainsString('CharacterSet=AL16UTF16', $dump, $name);
            }

            $execution = $connection->execute(
                'begin :o := :i; :io := :io || :i; end;',
                ['i' => NVarchar::in($text), 'o' => NVarchar::out(), 'io' => NVarchar::inOut($text)],
                self::options(),
            );
            self::assertSame(['o' => [$text], 'io' => [$text.$text]], $execution->outValues());
        }
    }

    /**
     * Binds 9: a decimal string is exact whatever the Session's NLS.
     */
    #[Test]
    public function a_number_string_is_exact_under_any_nls(): void
    {
        // Standalone: a pooled Session would keep the ALTER SESSION.
        $connection = $this->connect(standalone: true);
        $connection->execute("alter session set nls_numeric_characters = ',.'", [], self::options());

        self::assertSame('Typ=2 Len=3: 193,13,31', self::value($connection, 'select dump(:v) from dual', ['v' => Number::in('12.30')]));
        self::assertSame(1, self::value($connection, 'select case when :v = 12.3 then 1 else 0 end from dual', ['v' => Number::in('12.30')]));
        self::assertSame('-0.5', self::value($connection, 'select :v from dual', ['v' => Number::in('-.50')]));
        self::assertSame('12345678901234567890.123456789', self::value($connection, 'select :v from dual', ['v' => Number::in('12345678901234567890.123456789')]));
    }

    /**
     * Binds 15: keys are the names as the user wrote them, whatever the SQL
     * wrote.
     */
    #[Test]
    public function out_value_keys_keep_the_users_case(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            'begin :TOTAL := 1; :name := 2; :Mixed := 3; end;',
            ['total' => Number::out(), 'NAME' => Number::out(), 'mIxEd' => Number::inOut(0)],
            self::options(),
        );

        self::assertSame(['total' => [1], 'NAME' => [2], 'mIxEd' => [3]], $execution->outValues());
    }

    /**
     * Values 16: OUT and IN OUT values follow the column mapping under every
     * ValueMapping. Only `numbers` and `temporals` change these types.
     */
    #[Test]
    public function out_values_follow_the_column_mapping_under_every_value_mapping(): void
    {
        Temporals::inZone('America/Sao_Paulo', $this->outValuesFollowTheColumnMapping(...));
    }

    private function outValuesFollowTheColumnMapping(): void
    {
        $connection = $this->connect();

        foreach (NumberMode::cases() as $numbers) {
            foreach (TemporalMode::cases() as $temporals) {
                foreach (LobMode::cases() as $lobs) {
                    $values = new ValueMapping($numbers, $temporals, $lobs);
                    $execution = $connection->execute(
                        "begin
                           :fraction := 12.5; :integral := 5; :huge := 1e30; :pls := 7;
                           :bf := 0.1f; :bd := 0.1d; :flag := true; :bytes := hextoraw('00ff');
                           :text := 'a'; :io := :io + 0.25;
                           :d := date '2026-03-01' + 1/24; :ts := timestamp '2026-03-01 10:00:00.123456789';
                           :tz := timestamp '2026-03-01 10:00:00.5 +05:45';
                           :ltz := cast(timestamp '2026-03-01 13:00:00 +00:00' as timestamp with local time zone);
                           :d_io := :d_io + 1; :ltz_io := :ltz_io;
                           :ds := interval '1 02:03:04.5' day to second; :ym := interval '1-2' year to month;
                           :lob := to_clob('a');
                         end;",
                        [
                            'fraction' => Number::out(), 'integral' => Number::out(), 'huge' => Number::out(),
                            'pls' => PlsInteger::out(), 'bf' => BinaryFloat::out(), 'bd' => BinaryDouble::out(),
                            'flag' => Boolean::out(), 'bytes' => Raw::out(), 'text' => Varchar::out(),
                            'io' => Number::inOut(1),
                            'd' => Date::out(), 'ts' => Timestamp::out(), 'tz' => TimestampTz::out(),
                            'ltz' => TimestampLtz::out(), 'ds' => IntervalDs::out(), 'ym' => IntervalYm::out(),
                            'lob' => Clob::out(),
                            // These travel as TIMESTAMP and TSTZ, and come back
                            // as the type declared.
                            'd_io' => Date::inOut(new \DateTimeImmutable('2026-03-01 10:00:00.5')),
                            'ltz_io' => TimestampLtz::inOut(new \DateTimeImmutable('2026-03-01 13:00:00', new \DateTimeZone('UTC'))),
                        ],
                        new DriverOptions(true, null, 100, $values, RowShape::Assoc, KeyCase::Native),
                    );
                    $float = $numbers === NumberMode::Float;
                    $string = $temporals === TemporalMode::String;
                    $outValues = $execution->outValues();
                    $lob = $outValues['lob'][0];
                    unset($outValues['lob']);
                    if ($lobs === LobMode::Inline) {
                        self::assertSame('a', $lob, $lobs->value);
                    } else {
                        self::assertInstanceOf(DriverLob::class, $lob, $lobs->value);
                        self::assertSame('a', $lob->readChunk());
                    }
                    $temporal = [];
                    foreach (['d', 'ts', 'tz', 'ltz', 'd_io', 'ltz_io', 'ds', 'ym'] as $name) {
                        $temporal[$name] = array_map(Temporals::shown(...), $outValues[$name]);
                        unset($outValues[$name]);
                    }

                    self::assertSame($string ? [
                        'd' => ['2026-03-01 01:00:00'],
                        'ts' => ['2026-03-01 10:00:00.123456789'],
                        'tz' => ['2026-03-01 10:00:00.500000000 +05:45'],
                        'ltz' => ['2026-03-01 10:00:00.000000000 -03:00'],
                        'd_io' => ['2026-03-02 10:00:00'],
                        'ltz_io' => ['2026-03-01 10:00:00.000000000 -03:00'],
                        'ds' => ['+0Y0M1DT2H3M4S.500000'],
                        'ym' => ['+1Y2M0DT0H0M0S.000000'],
                    ] : [
                        'd' => ['+2026-03-01 01:00:00.000000 America/Sao_Paulo'],
                        'ts' => ['+2026-03-01 10:00:00.123456 America/Sao_Paulo'],
                        'tz' => ['+2026-03-01 10:00:00.500000 +05:45'],
                        'ltz' => ['+2026-03-01 10:00:00.000000 America/Sao_Paulo'],
                        'd_io' => ['+2026-03-02 10:00:00.000000 America/Sao_Paulo'],
                        'ltz_io' => ['+2026-03-01 10:00:00.000000 America/Sao_Paulo'],
                        'ds' => ['+0Y0M1DT2H3M4S.500000'],
                        'ym' => ['+1Y2M0DT0H0M0S.000000'],
                    ], $temporal, "$numbers->value, $temporals->value, $lobs->value");

                    self::assertSame([
                        'fraction' => [$float ? 12.5 : '12.5'],
                        'integral' => [5],
                        'huge' => ['1000000000000000000000000000000'],
                        'pls' => [7],
                        'bf' => [0.1],
                        'bd' => [0.1],
                        'flag' => [true],
                        'bytes' => ["\x00\xff"],
                        'text' => ['a'],
                        'io' => [$float ? 1.25 : '1.25'],
                    ], $outValues, "$numbers->value, $temporals->value, $lobs->value");
                }
            }
        }
    }

    /**
     * A typed bind's value is checked before any Round trip, naming the fix.
     *
     * @return iterable<string, array{Bind, string}>
     */
    public static function typedMistakes(): iterable
    {
        yield 'bytes that are not UTF-8' => [Varchar::in("\xff"), 'bind binary data with Raw::in() or Blob::in()'];
        yield 'text over 32767 bytes' => [Varchar::in(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
        yield 'national text over 32767 bytes' => [NVarchar::in(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
        yield 'bytes over 32767' => [Raw::in(str_repeat("\x00", 32768)), 'over 32767; bind them with Blob::in()'];
    }

    #[Test]
    #[DataProvider('typedMistakes')]
    public function a_typed_bind_mistake_fails_before_any_round_trip(Bind $bind, string $fix): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();

        try {
            $roundTrips = $counter->during($sid, function () use ($connection, $bind, $fix): void {
                try {
                    $connection->execute('begin :v := :v; end;', ['v' => $bind], self::options());
                    self::fail('The bind mistake ran.');
                } catch (UsageException $e) {
                    self::assertStringContainsString("Bind 'v'", $e->getMessage());
                    self::assertStringContainsString($fix, $e->getMessage());
                }
            });
        } finally {
            $counter->close();
        }

        self::assertSame(0, $roundTrips);
    }
}
