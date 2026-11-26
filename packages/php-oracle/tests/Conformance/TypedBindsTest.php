<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\TypedBindsTest;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Rajska\Oracle\Binds\Bindable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\LobMode;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;

/**
 * Each class with its PL/SQL type: the IN bind and the OUT value it
 * becomes; the OUT bind; the IN OUT bind, how the block changes it, and
 * the result.
 *
 * @return iterable<string, array{string, Bindable, mixed, Bindable, Bindable, string, mixed}>
 */
dataset('classes', function () {
    yield 'Varchar' => ['varchar2', new In\Varchar('héllo'), 'héllo', new Out\Varchar(), new InOut\Varchar('a'), "io || 'b'", 'ab'];

    // An unconstrained CHAR parameter takes its length from the bind: the
    // largest buffer, 32767 bytes (16383 UTF-16 code units for NCHAR).
    yield 'Char' => ['char', new In\Char('AB'), str_pad('AB', 32767), new Out\Char(), new InOut\Char('x'), "rtrim(io) || 'y'", str_pad('xy', 32767)];
    yield 'NVarchar' => ['nvarchar2', new In\NVarchar('çã日😀'), 'çã日😀', new Out\NVarchar(), new InOut\NVarchar('é'), "io || n'日'", 'é日'];
    yield 'NChar' => ['nchar', new In\NChar('ç日😀'), 'ç日😀' . str_repeat(' ', 16383 - 4), new Out\NChar(), new InOut\NChar('é'), "rtrim(io) || n'😀'", 'é😀' . str_repeat(' ', 16383 - 3)];
    yield 'Number' => ['number', new In\Number('12.30'), '12.3', new Out\Number(), new InOut\Number(41), 'io + 1', 42];
    yield 'Number from a float' => ['number', new In\Number(0.1), '0.1', new Out\Number(), new InOut\Number(0.5), 'io * 3', '1.5'];
    yield 'BinaryFloat' => ['binary_float', new In\BinaryFloat(0.1), 0.1, new Out\BinaryFloat(), new InOut\BinaryFloat(1.5), 'io * 2', 3.0];
    yield 'BinaryDouble' => ['binary_double', new In\BinaryDouble(0.1), 0.1, new Out\BinaryDouble(), new InOut\BinaryDouble(2), 'io / 4', 0.5];
    yield 'BinaryDouble NaN' => ['binary_double', new In\BinaryDouble(\NAN), \NAN, new Out\BinaryDouble(), new InOut\BinaryDouble(\INF), '-io', -\INF];
    yield 'PlsInteger' => ['pls_integer', new In\PlsInteger(-2147483648), -2147483648, new Out\PlsInteger(), new InOut\PlsInteger(41), 'io + 1', 42];
    yield 'Boolean' => ['boolean', new In\Boolean(true), true, new Out\Boolean(), new InOut\Boolean(false), 'not io', true];
    yield 'Raw' => ['raw', new In\Raw("\x00\xff\x80"), "\x00\xff\x80", new Out\Raw(), new InOut\Raw("\x01"), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
});

/**
 * @return iterable<string, array{string, Bindable, Bindable, Bindable}>
 */
dataset('nulls', function () {
    yield 'Varchar' => ['varchar2', new In\Varchar(null), new Out\Varchar(), new InOut\Varchar(null)];
    yield 'Char' => ['char', new In\Char(null), new Out\Char(), new InOut\Char(null)];
    yield 'NVarchar' => ['nvarchar2', new In\NVarchar(null), new Out\NVarchar(), new InOut\NVarchar(null)];
    yield 'NChar' => ['nchar', new In\NChar(null), new Out\NChar(), new InOut\NChar(null)];
    yield 'Number' => ['number', new In\Number(null), new Out\Number(), new InOut\Number(null)];
    yield 'BinaryFloat' => ['binary_float', new In\BinaryFloat(null), new Out\BinaryFloat(), new InOut\BinaryFloat(null)];
    yield 'BinaryDouble' => ['binary_double', new In\BinaryDouble(null), new Out\BinaryDouble(), new InOut\BinaryDouble(null)];
    yield 'PlsInteger' => ['pls_integer', new In\PlsInteger(null), new Out\PlsInteger(), new InOut\PlsInteger(null)];
    yield 'Boolean' => ['boolean', new In\Boolean(null), new Out\Boolean(), new InOut\Boolean(null)];
    yield 'Raw' => ['raw', new In\Raw(null), new Out\Raw(), new InOut\Raw(null)];
    yield 'Rowid' => ['rowid', new In\Rowid(null), new Out\Rowid(), new InOut\Rowid(null)];
    yield 'Date' => ['date', new In\Date(null), new Out\Date(), new InOut\Date(null)];
    yield 'Timestamp' => ['timestamp', new In\Timestamp(null), new Out\Timestamp(), new InOut\Timestamp(null)];
    yield 'TimestampTz' => ['timestamp with time zone', new In\TimestampTz(null), new Out\TimestampTz(), new InOut\TimestampTz(null)];
    yield 'TimestampLtz' => ['timestamp with local time zone', new In\TimestampLtz(null), new Out\TimestampLtz(), new InOut\TimestampLtz(null)];
    yield 'IntervalDs' => ['interval day to second', new In\IntervalDs(null), new Out\IntervalDs(), new InOut\IntervalDs(null)];
    yield 'IntervalYm' => ['interval year to month', new In\IntervalYm(null), new Out\IntervalYm(), new InOut\IntervalYm(null)];
});

/**
 * Each temporal class, as `classes()`. Values as `Temporals::shown()`
 * writes them, in PHP's zone America/Sao_Paulo.
 *
 * @return iterable<string, array{string, Bindable, string, Bindable, Bindable, string, string}>
 */
dataset('temporalClasses', function () {
    $date = new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Kathmandu'));
    $zone = 'America/Sao_Paulo';
    yield 'Date' => ['date', new In\Date($date), "+2026-03-01 10:00:00.000000 $zone", new Out\Date(), new InOut\Date($date), 'io + 1', "+2026-03-02 10:00:00.000000 $zone"];
    yield 'Timestamp' => ['timestamp', new In\Timestamp($date), "+2026-03-01 10:00:00.123456 $zone", new Out\Timestamp(), new InOut\Timestamp($date), "io + interval '0.5' second", "+2026-03-01 10:00:00.623456 $zone"];
    yield 'TimestampTz' => ['timestamp with time zone', new In\TimestampTz($date), '+2026-03-01 10:00:00.123456 +05:45', new Out\TimestampTz(), new InOut\TimestampTz($date), "io + interval '1' hour", '+2026-03-01 11:00:00.123456 +05:45'];

    // 10:00 at +05:45 is 04:15 UTC, 01:15 in São Paulo.
    yield 'TimestampLtz' => ['timestamp with local time zone', new In\TimestampLtz($date), "+2026-03-01 01:15:00.123456 $zone", new Out\TimestampLtz(), new InOut\TimestampLtz($date), "io + interval '1' hour", "+2026-03-01 02:15:00.123456 $zone"];
    $ds = new DateInterval('P3DT4H5M6S');
    $ds->f = 0.789;
    yield 'IntervalDs' => ['interval day to second', new In\IntervalDs($ds), '+0Y0M3DT4H5M6S.789000', new Out\IntervalDs(), new InOut\IntervalDs($ds), 'io * 2', '+0Y0M6DT8H10M13S.578000'];
    $negative = new DateInterval('PT36H');
    $negative->invert = 1;
    yield 'IntervalDs negative' => ['interval day to second', new In\IntervalDs($negative), '-0Y0M1DT12H0M0S.000000', new Out\IntervalDs(), new InOut\IntervalDs($negative), 'io * -1', '+0Y0M1DT12H0M0S.000000'];
    yield 'IntervalYm' => ['interval year to month', new In\IntervalYm(new DateInterval('P2Y5M')), '+2Y5M0DT0H0M0S.000000', new Out\IntervalYm(), new InOut\IntervalYm(new DateInterval('P1Y11M')), 'io * 2', '+3Y10M0DT0H0M0S.000000'];
    $months = new DateInterval('P14M');
    $months->invert = 1;
    yield 'IntervalYm negative' => ['interval year to month', new In\IntervalYm($months), '-1Y2M0DT0H0M0S.000000', new Out\IntervalYm(), new InOut\IntervalYm($months), 'io', '-1Y2M0DT0H0M0S.000000'];
});

test('each temporal class binds in out and in out through its plsql type', function (string $plsql, Bindable $in, string $out, Bindable $outBind, Bindable $inOut, string $change, string $changed): void {
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
            static fn (array $list): array => array_map(Temporals::shown(...), $list),
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
                'd' => new In\Date($tokyo), 't' => new In\Timestamp($tokyo),
                'tz' => new In\TimestampTz($tokyo), 'ltz' => new In\TimestampLtz($tokyo),
                'd_out' => new Out\Varchar(), 't_out' => new Out\Varchar(),
                'tz_out' => new Out\Varchar(), 'ltz_out' => new Out\Varchar(),
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
 * @return iterable<string, array{Closure(): Bindable, non-empty-string}>
 */
dataset('incompatibleIntervals', function () {
    yield 'IntervalDs with years' => [static fn (): In\IntervalDs => new In\IntervalDs(new DateInterval('P1Y')), 'An INTERVAL DAY TO SECOND bind takes days and smaller units'];
    yield 'IntervalDs with months' => [static fn (): InOut\IntervalDs => new InOut\IntervalDs(new DateInterval('P1M2D')), 'An INTERVAL DAY TO SECOND bind takes days and smaller units'];
    yield 'IntervalYm with days' => [static fn (): In\IntervalYm => new In\IntervalYm(new DateInterval('P1Y1D')), 'An INTERVAL YEAR TO MONTH bind takes years and months only'];
    yield 'IntervalYm with seconds' => [static fn (): InOut\IntervalYm => new InOut\IntervalYm(new DateInterval('PT1S')), 'An INTERVAL YEAR TO MONTH bind takes years and months only'];
    $fraction = new DateInterval('P1Y');
    $fraction->f = 0.5;
    yield 'IntervalYm with a fraction' => [static fn (): In\IntervalYm => new In\IntervalYm($fraction), 'An INTERVAL YEAR TO MONTH bind takes years and months only'];
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

test('each class binds in out and in out through its plsql type', function (string $plsql, Bindable $in, mixed $out, Bindable $outBind, Bindable $inOut, string $change, mixed $changed): void {
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

test('each class binds null', function (string $plsql, Bindable $in, Bindable $out, Bindable $inOut): void {
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
        ['i' => new In\Rowid($rowid), 'o' => new Out\Rowid(), 'io' => new InOut\Rowid($rowid), 'name' => new Out\Varchar()],
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
        ['id' => 7, 'name' => 'seven', 'rid' => new Out\Number(), 'rname' => new Out\Varchar()],
        $this->options(),
    );

    expect($execution->rowCount())->toBe(1)
        ->and($execution->outValues())->toBe(['rid' => [7], 'rname' => ['seven']]);
});

test('update returning into gives every row', function (): void {
    $connection = $this->connect();

    $two = $connection->execute(
        'update fx_items set price = price where id in (1, 2) returning id, name into :id, :name',
        ['id' => new Out\Number(), 'name' => new Out\Varchar()],
        $this->options(),
    )->outValues();
    $none = $connection->execute(
        'update fx_items set price = price where id < 0 returning id into :id',
        ['id' => new Out\Number()],
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
        ['name' => new Out\Varchar()],
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
        foreach (['inferred' => $text, 'typed' => new In\Varchar($text)] as $kind => $bind) {
            expect($this->value($connection, 'select length(:v) from dual', ['v' => $bind]))->toBe($bytes, "$kind $bytes")
                ->and($this->value($connection, 'select :v from dual', ['v' => $bind]))->toBe($text, "$kind $bytes");
        }
    }

    $returned = $connection->execute(
        'update fx_items set price = price where id = 1 returning name || :v into :o',
        ['v' => str_repeat('y', 8000), 'o' => new Out\Varchar()],
        $this->options(),
    )->outValues();
    expect($returned)->toBe(['o' => ['Item 0001' . str_repeat('y', 8000)]]);

    try {
        $connection->execute('insert into fx_bind_rows (name) values (:v)', ['v' => new In\Varchar(str_repeat('y', 4001))], $this->options());
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
        ['ascii' => new Out\Varchar(), 'multibyte' => new Out\Varchar()],
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
        ['from_null' => new InOut\Varchar(null), 'from_a' => new InOut\Varchar('a')],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['from_null' => [str_repeat('b', 32767)], 'from_a' => ['a' . str_repeat('b', 32766)]]);
});

test('a char matches a blank padded char', function (): void {
    $connection = $this->connect();
    $sql = "select count(*) from (select cast('AB' as char(5)) code from dual) where code = :code";

    expect($this->value($connection, $sql, ['code' => new In\Char('AB')]))->toBe(1)
        ->and($this->value($connection, $sql, ['code' => 'AB']))->toBe(0)
        ->and($this->value($connection, $sql, ['code' => new In\Varchar('AB')]))->toBe(0)
        ->and($this->value($connection, 'select dump(:code) from dual', ['code' => new In\Char('AB')]))->toBe('Typ=96 Len=2: 65,66');
});

test('a char out keeps the padding it has', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        "declare c char(5) := 'AB'; n nchar(4) := n'\u{e7}'; begin :c := c; :n := n; end;",
        ['c' => new Out\Char(), 'n' => new Out\NChar()],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['c' => ['AB   '], 'n' => ['ç   ']]);
});

test('nvarchar and nchar round trip multibyte text', function (): void {
    $connection = $this->connect();

    foreach (['ç', '日本', '😀', 'ç日😀'] as $text) {
        foreach ([new In\NVarchar($text), new In\NChar($text)] as $bind) {
            $name = $bind::class;
            expect($this->value($connection, 'select :v from dual', ['v' => $bind]))->toBe($text, $name);
            $dump = $this->value($connection, 'select dump(:v, 1016) from dual', ['v' => $bind]);
            expect($dump)->toBeString()
                ->toContain('CharacterSet=AL16UTF16');
        }

        $execution = $connection->execute(
            'begin :o := :i; :io := :io || :i; end;',
            ['i' => new In\NVarchar($text), 'o' => new Out\NVarchar(), 'io' => new InOut\NVarchar($text)],
            $this->options(),
        );
        expect($execution->outValues())->toBe(['o' => [$text], 'io' => [$text . $text]]);
    }
});

test('a number string is exact under any nls', function (): void {
    // Standalone: a pooled Session would keep the ALTER SESSION.
    $connection = $this->connect(standalone: true);
    $connection->execute("alter session set nls_numeric_characters = ',.'", [], $this->options());

    expect($this->value($connection, 'select dump(:v) from dual', ['v' => new In\Number('12.30')]))->toBe('Typ=2 Len=3: 193,13,31')
        ->and($this->value($connection, 'select case when :v = 12.3 then 1 else 0 end from dual', ['v' => new In\Number('12.30')]))->toBe(1)
        ->and($this->value($connection, 'select :v from dual', ['v' => new In\Number('-.50')]))->toBe('-0.5')
        ->and($this->value($connection, 'select :v from dual', ['v' => new In\Number('12345678901234567890.123456789')]))->toBe('12345678901234567890.123456789');
});

test('out value keys keep the users case', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        'begin :TOTAL := 1; :name := 2; :Mixed := 3; end;',
        ['total' => new Out\Number(), 'NAME' => new Out\Number(), 'mIxEd' => new InOut\Number(0)],
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
                        'fraction' => new Out\Number(), 'integral' => new Out\Number(), 'huge' => new Out\Number(),
                        'pls' => new Out\PlsInteger(), 'bf' => new Out\BinaryFloat(), 'bd' => new Out\BinaryDouble(),
                        'flag' => new Out\Boolean(), 'bytes' => new Out\Raw(), 'text' => new Out\Varchar(),
                        'io' => new InOut\Number(1),
                        'd' => new Out\Date(), 'ts' => new Out\Timestamp(), 'tz' => new Out\TimestampTz(),
                        'ltz' => new Out\TimestampLtz(), 'ds' => new Out\IntervalDs(), 'ym' => new Out\IntervalYm(),
                        'lob' => new Out\Clob(),
                        // These travel as TIMESTAMP and TSTZ, and come back
                        // as the type declared.
                        'd_io' => new InOut\Date(new DateTimeImmutable('2026-03-01 10:00:00.5')),
                        'ltz_io' => new InOut\TimestampLtz(new DateTimeImmutable('2026-03-01 13:00:00', new DateTimeZone('UTC'))),
                    ],
                    new Options(true, null, 100, $values, RowShape::Assoc, KeyCase::Native),
                );
                $float = $numbers === NumberMode::Float;
                $string = $temporals === TemporalMode::String;
                $outValues = $execution->outValues();
                $lob = $outValues['lob'][0];
                unset($outValues['lob']);
                if ($lobs === LobMode::Inline) {
                    expect($lob)->toBe('a', $lobs->value);
                } else {
                    assert($lob instanceof Locator);
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
 * @return iterable<string, array{Bindable, string}>
 */
dataset('typedMistakes', function () {
    yield 'bytes that are not UTF-8' => [new In\Varchar("\xff"), 'bind binary data with In\Raw or In\Blob'];

    // Text or bytes over 32767 are a LONG bind: UnsupportedException,
    // in ScopeTest.
});

test('a typed bind mistake fails before any round trip', function (Bindable $bind, string $fix): void {
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
