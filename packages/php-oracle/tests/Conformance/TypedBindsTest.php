<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\TypedBindsTest;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
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
 * Each class with its PL/SQL type: the IN bind and the OUT value it
 * becomes; the OUT bind; the IN OUT bind, how the block changes it, and
 * the result.
 *
 * @return iterable<string, array{string, Bind, mixed, Bind, Bind, string, mixed}>
 */
dataset('classes', function () {
    yield 'Varchar' => ['varchar2', Varchar::in('héllo'), 'héllo', Varchar::out(), Varchar::inOut('a'), "io || 'b'", 'ab'];

    // An unconstrained CHAR parameter takes its length from the bind: the
    // largest buffer, 32767 bytes (16383 UTF-16 code units for NCHAR).
    yield 'Char' => ['char', Char::in('AB'), str_pad('AB', 32767), Char::out(), Char::inOut('x'), "rtrim(io) || 'y'", str_pad('xy', 32767)];
    yield 'NVarchar' => ['nvarchar2', NVarchar::in('çã日😀'), 'çã日😀', NVarchar::out(), NVarchar::inOut('é'), "io || n'日'", 'é日'];
    yield 'NChar' => ['nchar', NChar::in('ç日😀'), 'ç日😀' . str_repeat(' ', 16383 - 4), NChar::out(), NChar::inOut('é'), "rtrim(io) || n'😀'", 'é😀' . str_repeat(' ', 16383 - 3)];
    yield 'Number' => ['number', Number::in('12.30'), '12.3', Number::out(), Number::inOut(41), 'io + 1', 42];
    yield 'Number from a float' => ['number', Number::in(0.1), '0.1', Number::out(), Number::inOut(0.5), 'io * 3', '1.5'];
    yield 'BinaryFloat' => ['binary_float', BinaryFloat::in(0.1), 0.1, BinaryFloat::out(), BinaryFloat::inOut(1.5), 'io * 2', 3.0];
    yield 'BinaryDouble' => ['binary_double', BinaryDouble::in(0.1), 0.1, BinaryDouble::out(), BinaryDouble::inOut(2), 'io / 4', 0.5];
    yield 'BinaryDouble NaN' => ['binary_double', BinaryDouble::in(\NAN), \NAN, BinaryDouble::out(), BinaryDouble::inOut(\INF), '-io', -\INF];
    yield 'PlsInteger' => ['pls_integer', PlsInteger::in(-2147483648), -2147483648, PlsInteger::out(), PlsInteger::inOut(41), 'io + 1', 42];
    yield 'Boolean' => ['boolean', Boolean::in(true), true, Boolean::out(), Boolean::inOut(false), 'not io', true];
    yield 'Raw' => ['raw', Raw::in("\x00\xff\x80"), "\x00\xff\x80", Raw::out(), Raw::inOut("\x01"), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
});

/**
 * @return iterable<string, array{string, Bind, Bind, Bind}>
 */
dataset('nulls', function () {
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
});

/**
 * Each temporal class, as `classes()`. Values as `Temporals::shown()`
 * writes them, in PHP's zone America/Sao_Paulo.
 *
 * @return iterable<string, array{string, Bind, string, Bind, Bind, string, string}>
 */
dataset('temporalClasses', function () {
    $date = new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Kathmandu'));
    $zone = 'America/Sao_Paulo';
    yield 'Date' => ['date', Date::in($date), "+2026-03-01 10:00:00.000000 $zone", Date::out(), Date::inOut($date), 'io + 1', "+2026-03-02 10:00:00.000000 $zone"];
    yield 'Timestamp' => ['timestamp', Timestamp::in($date), "+2026-03-01 10:00:00.123456 $zone", Timestamp::out(), Timestamp::inOut($date), "io + interval '0.5' second", "+2026-03-01 10:00:00.623456 $zone"];
    yield 'TimestampTz' => ['timestamp with time zone', TimestampTz::in($date), '+2026-03-01 10:00:00.123456 +05:45', TimestampTz::out(), TimestampTz::inOut($date), "io + interval '1' hour", '+2026-03-01 11:00:00.123456 +05:45'];

    // 10:00 at +05:45 is 04:15 UTC, 01:15 in São Paulo.
    yield 'TimestampLtz' => ['timestamp with local time zone', TimestampLtz::in($date), "+2026-03-01 01:15:00.123456 $zone", TimestampLtz::out(), TimestampLtz::inOut($date), "io + interval '1' hour", "+2026-03-01 02:15:00.123456 $zone"];
    $ds = new DateInterval('P3DT4H5M6S');
    $ds->f = 0.789;
    yield 'IntervalDs' => ['interval day to second', IntervalDs::in($ds), '+0Y0M3DT4H5M6S.789000', IntervalDs::out(), IntervalDs::inOut($ds), 'io * 2', '+0Y0M6DT8H10M13S.578000'];
    $negative = new DateInterval('PT36H');
    $negative->invert = 1;
    yield 'IntervalDs negative' => ['interval day to second', IntervalDs::in($negative), '-0Y0M1DT12H0M0S.000000', IntervalDs::out(), IntervalDs::inOut($negative), 'io * -1', '+0Y0M1DT12H0M0S.000000'];
    yield 'IntervalYm' => ['interval year to month', IntervalYm::in(new DateInterval('P2Y5M')), '+2Y5M0DT0H0M0S.000000', IntervalYm::out(), IntervalYm::inOut(new DateInterval('P1Y11M')), 'io * 2', '+3Y10M0DT0H0M0S.000000'];
    $months = new DateInterval('P14M');
    $months->invert = 1;
    yield 'IntervalYm negative' => ['interval year to month', IntervalYm::in($months), '-1Y2M0DT0H0M0S.000000', IntervalYm::out(), IntervalYm::inOut($months), 'io', '-1Y2M0DT0H0M0S.000000'];
});

test('each temporal class binds in out and in out through its plsql type', function (string $plsql, Bind $in, string $out, Bind $outBind, Bind $inOut, string $change, string $changed): void {
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
            $this->options(),
        );

        $values = $execution->outValues();
        expect(array_map(
            static fn(array $list): array => array_map(Temporals::shown(...), $list),
            $values,
        ))->toBe(['o' => [$out], 'io' => [$changed]]);
    });
})->with('temporalClasses');

test('wall clock types store the wall clock and zoned types the instant', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        $connection = $this->connect();
        $tokyo = new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Tokyo'));

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
            $this->options(),
        );

        expect($execution->outValues())->toBe([
            'd_out' => ['2026-03-01 10:00:00.000000'],
            't_out' => ['2026-03-01 10:00:00.123456'],
            'tz_out' => ['2026-03-01 01:00:00.123456'],
            'ltz_out' => ['2026-03-01 01:00:00.123456'],
        ]);
    });
});

/**
 * @return iterable<string, array{Closure(): Bind, non-empty-string}>
 */
dataset('incompatibleIntervals', function () {
    yield 'IntervalDs with years' => [static fn(): IntervalDs => IntervalDs::in(new DateInterval('P1Y')), 'IntervalDs::in() takes days and smaller units'];
    yield 'IntervalDs with months' => [static fn(): IntervalDs => IntervalDs::inOut(new DateInterval('P1M2D')), 'IntervalDs::in() takes days and smaller units'];
    yield 'IntervalYm with days' => [static fn(): IntervalYm => IntervalYm::in(new DateInterval('P1Y1D')), 'IntervalYm::in() takes years and months only'];
    yield 'IntervalYm with seconds' => [static fn(): IntervalYm => IntervalYm::inOut(new DateInterval('PT1S')), 'IntervalYm::in() takes years and months only'];
    $fraction = new DateInterval('P1Y');
    $fraction->f = 0.5;
    yield 'IntervalYm with a fraction' => [static fn(): IntervalYm => IntervalYm::in($fraction), 'IntervalYm::in() takes years and months only'];
});

test('an incompatible date interval fails when the bind is built', function (Closure $bind, string $message): void {
    /** @var non-empty-string $message */
    try {
        $bind();
        $this->fail('The interval was accepted.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toStartWith($message);
    }
})->with('incompatibleIntervals');

test('each class binds in out and in out through its plsql type', function (string $plsql, Bind $in, mixed $out, Bind $outBind, Bind $inOut, string $change, mixed $changed): void {
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
        $this->options(),
    );

    $values = $execution->outValues();
    expect(array_keys($values))->toBe(['o', 'io']);
    assertSameValue($out, $values['o'][0]);
    assertSameValue($changed, $values['io'][0]);
})->with('classes');

test('each class binds null', function (string $plsql, Bind $in, Bind $out, Bind $inOut): void {
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
        $this->options(),
    );

    expect($execution->outValues())->toBe(['o' => [null], 'io' => [null]]);
})->with('nulls');

test('a rowid binds in out and in out', function (): void {
    $connection = $this->connect();
    $rowid = $this->value($connection, 'select rowidtochar(rowid) from fx_items where id = 1');
    assert(\is_string($rowid));

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
        $this->options(),
    );

    expect($execution->outValues())->toBe(['o' => [$rowid], 'io' => [$rowid], 'name' => ['Item 0001']]);
});

/**
 * Same value and PHP type; NaN equals NaN.
 */
function assertSameValue(mixed $expected, mixed $actual): void
{
    if (\is_float($expected) && is_nan($expected)) {
        expect($actual)->toBeFloat()
            ->toBeNan();

        return;
    }
    expect($actual)->toBe($expected);
}

test('insert returning into gives the one row', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        'insert into fx_bind_rows (id, name) values (:id, :name) returning id, name into :rid, :rname',
        ['id' => 7, 'name' => 'seven', 'rid' => Number::out(), 'rname' => Varchar::out()],
        $this->options(),
    );

    expect($execution->rowCount())->toBe(1)
        ->and($execution->outValues())->toBe(['rid' => [7], 'rname' => ['seven']]);
});

test('update returning into gives every row', function (): void {
    $connection = $this->connect();

    $two = $connection->execute(
        'update fx_items set price = price where id in (1, 2) returning id, name into :id, :name',
        ['id' => Number::out(), 'name' => Varchar::out()],
        $this->options(),
    )->outValues();
    $none = $connection->execute(
        'update fx_items set price = price where id < 0 returning id into :id',
        ['id' => Number::out()],
        $this->options(),
    )->outValues();

    expect($two['id'])->toEqualCanonicalizing([1, 2])
        ->and($two['name'])->toEqualCanonicalizing(['Item 0001', 'Item 0002'])
        ->and($none)->toBe(['id' => []]);
});

test('returning reads the longest sql varchar2', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        "update fx_items set price = price where id = 1 returning rpad('x', 4000, 'x') into :name",
        ['name' => Varchar::out()],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['name' => [str_repeat('x', 4000)]]);
});

test('varchar2 binds over 4000 bytes work in sql on a standard database', function (): void {
    $connection = $this->connect();
    try {
        $connection->execute("select cast('x' as varchar2(4001)) from dual", [], $this->options());
        $this->fail('The database has max_string_size = EXTENDED; this case needs STANDARD.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(910);
    }

    foreach ([4000, 4001, 32767] as $bytes) {
        $text = str_repeat('y', $bytes);
        foreach (['inferred' => $text, 'typed' => Varchar::in($text)] as $kind => $bind) {
            expect($this->value($connection, 'select length(:v) from dual', ['v' => $bind]))->toBe($bytes, "$kind $bytes")
                ->and($this->value($connection, 'select :v from dual', ['v' => $bind]))->toBe($text, "$kind $bytes");
        }
    }

    $returned = $connection->execute(
        'update fx_items set price = price where id = 1 returning name || :v into :o',
        ['v' => str_repeat('y', 8000), 'o' => Varchar::out()],
        $this->options(),
    )->outValues();
    expect($returned)->toBe(['o' => ['Item 0001' . str_repeat('y', 8000)]]);

    try {
        $connection->execute('insert into fx_bind_rows (name) values (:v)', ['v' => Varchar::in(str_repeat('y', 4001))], $this->options());
        $this->fail('A 4001-byte value went into a 4000-byte column.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(12899);
    }
});

test('a varchar out holds 32767 bytes', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        "begin
               :ascii := rpad('a', 32767, 'a');
               :multibyte := rpad(unistr('\\00e7'), 16383, unistr('\\00e7'));
             end;",
        ['ascii' => Varchar::out(), 'multibyte' => Varchar::out()],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['ascii' => [str_repeat('a', 32767)], 'multibyte' => [str_repeat('ç', 16383)]]);
});

test('a varchar in out grows to 32767 bytes', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        "begin
               :from_null := rpad(nvl(:from_null, 'b'), 32767, 'b');
               :from_a := rpad(:from_a, 32767, 'b');
             end;",
        ['from_null' => Varchar::inOut(null), 'from_a' => Varchar::inOut('a')],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['from_null' => [str_repeat('b', 32767)], 'from_a' => ['a' . str_repeat('b', 32766)]]);
});

test('a char matches a blank padded char', function (): void {
    $connection = $this->connect();
    $sql = "select count(*) from (select cast('AB' as char(5)) code from dual) where code = :code";

    expect($this->value($connection, $sql, ['code' => Char::in('AB')]))->toBe(1)
        ->and($this->value($connection, $sql, ['code' => 'AB']))->toBe(0)
        ->and($this->value($connection, $sql, ['code' => Varchar::in('AB')]))->toBe(0)
        ->and($this->value($connection, 'select dump(:code) from dual', ['code' => Char::in('AB')]))->toBe('Typ=96 Len=2: 65,66');
});

test('a char out keeps the padding it has', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        "declare c char(5) := 'AB'; n nchar(4) := n'\u{e7}'; begin :c := c; :n := n; end;",
        ['c' => Char::out(), 'n' => NChar::out()],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['c' => ['AB   '], 'n' => ['ç   ']]);
});

test('nvarchar and nchar round trip multibyte text', function (): void {
    $connection = $this->connect();

    foreach (['ç', '日本', '😀', 'ç日😀'] as $text) {
        foreach ([NVarchar::in($text), NChar::in($text)] as $bind) {
            $name = $bind::class;
            expect($this->value($connection, 'select :v from dual', ['v' => $bind]))->toBe($text, $name);
            $dump = $this->value($connection, 'select dump(:v, 1016) from dual', ['v' => $bind]);
            expect($dump)->toBeString()
                ->toContain('CharacterSet=AL16UTF16');
        }

        $execution = $connection->execute(
            'begin :o := :i; :io := :io || :i; end;',
            ['i' => NVarchar::in($text), 'o' => NVarchar::out(), 'io' => NVarchar::inOut($text)],
            $this->options(),
        );
        expect($execution->outValues())->toBe(['o' => [$text], 'io' => [$text . $text]]);
    }
});

test('a number string is exact under any nls', function (): void {
    // Standalone: a pooled Session would keep the ALTER SESSION.
    $connection = $this->connect(standalone: true);
    $connection->execute("alter session set nls_numeric_characters = ',.'", [], $this->options());

    expect($this->value($connection, 'select dump(:v) from dual', ['v' => Number::in('12.30')]))->toBe('Typ=2 Len=3: 193,13,31')
        ->and($this->value($connection, 'select case when :v = 12.3 then 1 else 0 end from dual', ['v' => Number::in('12.30')]))->toBe(1)
        ->and($this->value($connection, 'select :v from dual', ['v' => Number::in('-.50')]))->toBe('-0.5')
        ->and($this->value($connection, 'select :v from dual', ['v' => Number::in('12345678901234567890.123456789')]))->toBe('12345678901234567890.123456789');
});

test('out value keys keep the users case', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        'begin :TOTAL := 1; :name := 2; :Mixed := 3; end;',
        ['total' => Number::out(), 'NAME' => Number::out(), 'mIxEd' => Number::inOut(0)],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['total' => [1], 'NAME' => [2], 'mIxEd' => [3]]);
});

test('out values follow the column mapping under every value mapping', function (): void {
    Temporals::inZone('America/Sao_Paulo', outValuesFollowTheColumnMapping(...));
});

function outValuesFollowTheColumnMapping(): void
{
    $connection = test()->connect();

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
                        'd_io' => Date::inOut(new DateTimeImmutable('2026-03-01 10:00:00.5')),
                        'ltz_io' => TimestampLtz::inOut(new DateTimeImmutable('2026-03-01 13:00:00', new DateTimeZone('UTC'))),
                    ],
                    new DriverOptions(true, null, 100, $values, RowShape::Assoc, KeyCase::Native),
                );
                $float = $numbers === NumberMode::Float;
                $string = $temporals === TemporalMode::String;
                $outValues = $execution->outValues();
                $lob = $outValues['lob'][0];
                unset($outValues['lob']);
                if ($lobs === LobMode::Inline) {
                    expect($lob)->toBe('a', $lobs->value);
                } else {
                    assert($lob instanceof DriverLob);
                    expect($lob->readChunk())->toBe('a');
                }
                $temporal = [];
                foreach (['d', 'ts', 'tz', 'ltz', 'd_io', 'ltz_io', 'ds', 'ym'] as $name) {
                    $temporal[$name] = array_map(Temporals::shown(...), $outValues[$name]);
                    unset($outValues[$name]);
                }

                expect($temporal)->toBe($string ? [
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
                ], "$numbers->value, $temporals->value, $lobs->value")
                    ->and($outValues)->toBe([
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
                    ], "$numbers->value, $temporals->value, $lobs->value");
            }
        }
    }
}

/**
 * @return iterable<string, array{Bind, string}>
 */
dataset('typedMistakes', function () {
    yield 'bytes that are not UTF-8' => [Varchar::in("\xff"), 'bind binary data with Raw::in() or Blob::in()'];

    // Text or bytes over 32767 are a LONG bind: UnsupportedException,
    // in ScopeTest.
});

test('a typed bind mistake fails before any round trip', function (Bind $bind, string $fix): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();

    try {
        $roundTrips = $counter->during($sid, function () use ($connection, $bind, $fix): void {
            try {
                $connection->execute('begin :v := :v; end;', ['v' => $bind], $this->options());
                $this->fail('The bind mistake ran.');
            } catch (UsageException $usageException) {
                expect($usageException->getMessage())->toContain("Bind 'v'")
                    ->and($usageException->getMessage())->toContain($fix);
            }
        });
    } finally {
        $counter->close();
    }

    expect($roundTrips)->toBe(0);
})->with('typedMistakes');
