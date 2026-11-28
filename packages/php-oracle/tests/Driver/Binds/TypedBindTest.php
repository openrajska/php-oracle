<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Binds\TypedBindTest;

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
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\LobMode;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table typed_items (id number(10) primary key, name varchar2(100) not null, price number(10, 2) not null)');
    schema()->run("insert into typed_items values (1, 'Item 0001', 1.25)");
    schema()->run("insert into typed_items values (2, 'Item 0002', 2.5)");
    schema()->run('create global temporary table typed_rows (id number(10), name varchar2(4000)) on commit delete rows');
});

/**
 * Each class with its PL/SQL type: the IN bind and the OUT value it
 * becomes; the OUT bind; the IN OUT bind, how the block changes it, and
 * the result.
 *
 * @return iterable<string, array{string, Closure(): Bindable, mixed, Closure(): Bindable, Closure(): Bindable, string, mixed}>
 */
dataset('classes', function () {
    yield 'Varchar' => ['varchar2', fn (): Bindable => new In\Varchar('héllo'), 'héllo', fn (): Bindable => new Out\Varchar(), fn (): Bindable => new InOut\Varchar('a'), "io || 'b'", 'ab'];

    // An unconstrained CHAR parameter takes its length from the bind: the
    // largest buffer, 32767 bytes (16383 UTF-16 code units for NCHAR).
    yield 'Char' => ['char', fn (): Bindable => new In\Char('AB'), str_pad('AB', 32767), fn (): Bindable => new Out\Char(), fn (): Bindable => new InOut\Char('x'), "rtrim(io) || 'y'", str_pad('xy', 32767)];
    yield 'NVarchar' => ['nvarchar2', fn (): Bindable => new In\NVarchar('çã日😀'), 'çã日😀', fn (): Bindable => new Out\NVarchar(), fn (): Bindable => new InOut\NVarchar('é'), "io || n'日'", 'é日'];
    yield 'NChar' => ['nchar', fn (): Bindable => new In\NChar('ç日😀'), 'ç日😀' . str_repeat(' ', 16383 - 4), fn (): Bindable => new Out\NChar(), fn (): Bindable => new InOut\NChar('é'), "rtrim(io) || n'😀'", 'é😀' . str_repeat(' ', 16383 - 3)];
    yield 'Number' => ['number', fn (): Bindable => new In\Number('12.30'), '12.3', fn (): Bindable => new Out\Number(), fn (): Bindable => new InOut\Number(41), 'io + 1', 42];
    yield 'Number from a float' => ['number', fn (): Bindable => new In\Number(0.1), '0.1', fn (): Bindable => new Out\Number(), fn (): Bindable => new InOut\Number(0.5), 'io * 3', '1.5'];
    yield 'BinaryFloat' => ['binary_float', fn (): Bindable => new In\BinaryFloat(0.1), 0.1, fn (): Bindable => new Out\BinaryFloat(), fn (): Bindable => new InOut\BinaryFloat(1.5), 'io * 2', 3.0];
    yield 'BinaryDouble' => ['binary_double', fn (): Bindable => new In\BinaryDouble(0.1), 0.1, fn (): Bindable => new Out\BinaryDouble(), fn (): Bindable => new InOut\BinaryDouble(2), 'io / 4', 0.5];
    yield 'BinaryDouble NaN' => ['binary_double', fn (): Bindable => new In\BinaryDouble(\NAN), \NAN, fn (): Bindable => new Out\BinaryDouble(), fn (): Bindable => new InOut\BinaryDouble(\INF), '-io', -\INF];
    yield 'PlsInteger' => ['pls_integer', fn (): Bindable => new In\PlsInteger(-2147483648), -2147483648, fn (): Bindable => new Out\PlsInteger(), fn (): Bindable => new InOut\PlsInteger(41), 'io + 1', 42];
    yield 'Boolean' => ['boolean', fn (): Bindable => new In\Boolean(true), true, fn (): Bindable => new Out\Boolean(), fn (): Bindable => new InOut\Boolean(false), 'not io', true];
    yield 'Raw' => ['raw', fn (): Bindable => new In\Raw("\x00\xff\x80"), "\x00\xff\x80", fn (): Bindable => new Out\Raw(), fn (): Bindable => new InOut\Raw("\x01"), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
});

/**
 * @return iterable<string, array{string, Closure(): Bindable, Closure(): Bindable, Closure(): Bindable}>
 */
dataset('nulls', function () {
    yield 'Varchar' => ['varchar2', fn (): Bindable => new In\Varchar(null), fn (): Bindable => new Out\Varchar(), fn (): Bindable => new InOut\Varchar(null)];
    yield 'Char' => ['char', fn (): Bindable => new In\Char(null), fn (): Bindable => new Out\Char(), fn (): Bindable => new InOut\Char(null)];
    yield 'NVarchar' => ['nvarchar2', fn (): Bindable => new In\NVarchar(null), fn (): Bindable => new Out\NVarchar(), fn (): Bindable => new InOut\NVarchar(null)];
    yield 'NChar' => ['nchar', fn (): Bindable => new In\NChar(null), fn (): Bindable => new Out\NChar(), fn (): Bindable => new InOut\NChar(null)];
    yield 'Number' => ['number', fn (): Bindable => new In\Number(null), fn (): Bindable => new Out\Number(), fn (): Bindable => new InOut\Number(null)];
    yield 'BinaryFloat' => ['binary_float', fn (): Bindable => new In\BinaryFloat(null), fn (): Bindable => new Out\BinaryFloat(), fn (): Bindable => new InOut\BinaryFloat(null)];
    yield 'BinaryDouble' => ['binary_double', fn (): Bindable => new In\BinaryDouble(null), fn (): Bindable => new Out\BinaryDouble(), fn (): Bindable => new InOut\BinaryDouble(null)];
    yield 'PlsInteger' => ['pls_integer', fn (): Bindable => new In\PlsInteger(null), fn (): Bindable => new Out\PlsInteger(), fn (): Bindable => new InOut\PlsInteger(null)];
    yield 'Boolean' => ['boolean', fn (): Bindable => new In\Boolean(null), fn (): Bindable => new Out\Boolean(), fn (): Bindable => new InOut\Boolean(null)];
    yield 'Raw' => ['raw', fn (): Bindable => new In\Raw(null), fn (): Bindable => new Out\Raw(), fn (): Bindable => new InOut\Raw(null)];
    yield 'Rowid' => ['rowid', fn (): Bindable => new In\Rowid(null), fn (): Bindable => new Out\Rowid(), fn (): Bindable => new InOut\Rowid(null)];
    yield 'Date' => ['date', fn (): Bindable => new In\Date(null), fn (): Bindable => new Out\Date(), fn (): Bindable => new InOut\Date(null)];
    yield 'Timestamp' => ['timestamp', fn (): Bindable => new In\Timestamp(null), fn (): Bindable => new Out\Timestamp(), fn (): Bindable => new InOut\Timestamp(null)];
    yield 'TimestampTz' => ['timestamp with time zone', fn (): Bindable => new In\TimestampTz(null), fn (): Bindable => new Out\TimestampTz(), fn (): Bindable => new InOut\TimestampTz(null)];
    yield 'TimestampLtz' => ['timestamp with local time zone', fn (): Bindable => new In\TimestampLtz(null), fn (): Bindable => new Out\TimestampLtz(), fn (): Bindable => new InOut\TimestampLtz(null)];
    yield 'IntervalDs' => ['interval day to second', fn (): Bindable => new In\IntervalDs(null), fn (): Bindable => new Out\IntervalDs(), fn (): Bindable => new InOut\IntervalDs(null)];
    yield 'IntervalYm' => ['interval year to month', fn (): Bindable => new In\IntervalYm(null), fn (): Bindable => new Out\IntervalYm(), fn (): Bindable => new InOut\IntervalYm(null)];
});

/**
 * Each temporal class, as `classes()`. Values as `temporalText()`
 * writes them, in PHP's zone America/Sao_Paulo.
 *
 * @return iterable<string, array{string, Closure(): Bindable, string, Closure(): Bindable, Closure(): Bindable, string, string}>
 */
dataset('temporalClasses', function () {
    $date = new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Kathmandu'));
    $zone = 'America/Sao_Paulo';
    yield 'Date' => ['date', fn (): Bindable => new In\Date($date), "+2026-03-01 10:00:00.000000 $zone", fn (): Bindable => new Out\Date(), fn (): Bindable => new InOut\Date($date), 'io + 1', "+2026-03-02 10:00:00.000000 $zone"];
    yield 'Timestamp' => ['timestamp', fn (): Bindable => new In\Timestamp($date), "+2026-03-01 10:00:00.123456 $zone", fn (): Bindable => new Out\Timestamp(), fn (): Bindable => new InOut\Timestamp($date), "io + interval '0.5' second", "+2026-03-01 10:00:00.623456 $zone"];
    yield 'TimestampTz' => ['timestamp with time zone', fn (): Bindable => new In\TimestampTz($date), '+2026-03-01 10:00:00.123456 +05:45', fn (): Bindable => new Out\TimestampTz(), fn (): Bindable => new InOut\TimestampTz($date), "io + interval '1' hour", '+2026-03-01 11:00:00.123456 +05:45'];

    // 10:00 at +05:45 is 04:15 UTC, 01:15 in São Paulo.
    yield 'TimestampLtz' => ['timestamp with local time zone', fn (): Bindable => new In\TimestampLtz($date), "+2026-03-01 01:15:00.123456 $zone", fn (): Bindable => new Out\TimestampLtz(), fn (): Bindable => new InOut\TimestampLtz($date), "io + interval '1' hour", "+2026-03-01 02:15:00.123456 $zone"];
    $ds = new DateInterval('P3DT4H5M6S');
    $ds->f = 0.789;
    yield 'IntervalDs' => ['interval day to second', fn (): Bindable => new In\IntervalDs($ds), '+0Y0M3DT4H5M6S.789000', fn (): Bindable => new Out\IntervalDs(), fn (): Bindable => new InOut\IntervalDs($ds), 'io * 2', '+0Y0M6DT8H10M13S.578000'];
    $negative = new DateInterval('PT36H');
    $negative->invert = 1;
    yield 'IntervalDs negative' => ['interval day to second', fn (): Bindable => new In\IntervalDs($negative), '-0Y0M1DT12H0M0S.000000', fn (): Bindable => new Out\IntervalDs(), fn (): Bindable => new InOut\IntervalDs($negative), 'io * -1', '+0Y0M1DT12H0M0S.000000'];
    yield 'IntervalYm' => ['interval year to month', fn (): Bindable => new In\IntervalYm(new DateInterval('P2Y5M')), '+2Y5M0DT0H0M0S.000000', fn (): Bindable => new Out\IntervalYm(), fn (): Bindable => new InOut\IntervalYm(new DateInterval('P1Y11M')), 'io * 2', '+3Y10M0DT0H0M0S.000000'];
    $months = new DateInterval('P14M');
    $months->invert = 1;
    yield 'IntervalYm negative' => ['interval year to month', fn (): Bindable => new In\IntervalYm($months), '-1Y2M0DT0H0M0S.000000', fn (): Bindable => new Out\IntervalYm(), fn (): Bindable => new InOut\IntervalYm($months), 'io', '-1Y2M0DT0H0M0S.000000'];
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

describe('temporal binds', function (): void {
    it('binds each temporal class in, out and in out through its plsql type', function (string $plsql, Bindable $in, string $out, Bindable $outBind, Bindable $inOut, string $change, string $changed): void {
        $connection = $this->connect();

        $values = inTimeZone('America/Sao_Paulo', fn (): array => $connection->execute(
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
        )->outValues());

        expect(array_map(static fn (array $list): array => array_map(temporalText(...), $list), $values))->toBe(['o' => [$out], 'io' => [$changed]]);
    })->with('temporalClasses');

    it('stores the wall clock for wall clock types and the instant for zoned types', function (): void {
        $connection = $this->connect();
        $tokyo = new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Tokyo'));

        $stored = inTimeZone('America/Sao_Paulo', fn (): array => $connection->execute(
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
        )->outValues());

        expect($stored)->toBe([
            'd_out' => ['2026-03-01 10:00:00.000000'],
            't_out' => ['2026-03-01 10:00:00.123456'],
            'tz_out' => ['2026-03-01 01:00:00.123456'],
            'ltz_out' => ['2026-03-01 01:00:00.123456'],
        ]);
    });

    it('refuses an incompatible date interval when the bind is built', function (Closure $bind, string $message): void {
        assert($message !== '');

        expect($bind)->toThrow(function (UsageException $thrown) use ($message): void {
            expect($thrown->getMessage())->toStartWith($message);
        });
    })->with('incompatibleIntervals');
});

describe('scalar binds', function (): void {
    it('binds each class in, out and in out through its plsql type', function (string $plsql, Bindable $in, mixed $out, Bindable $outBind, Bindable $inOut, string $change, mixed $changed): void {
        $connection = $this->connect();

        $values = $connection->execute(
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
        )->outValues();

        expect(array_keys($values))->toBe(['o', 'io'])
            ->and(comparable($values['o'][0]))->toBe(comparable($out))
            ->and(comparable($values['io'][0]))->toBe(comparable($changed));
    })->with('classes');

    it('binds null for each class', function (string $plsql, Bindable $in, Bindable $out, Bindable $inOut): void {
        $connection = $this->connect();

        $values = $connection->execute(
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
        )->outValues();

        expect($values)->toBe(['o' => [null], 'io' => [null]]);
    })->with('nulls');

    it('binds a rowid in, out and in out', function (): void {
        $connection = $this->connect();
        $rowid = $this->value($connection, 'select rowidtochar(rowid) from typed_items where id = 1');
        assert(\is_string($rowid));

        $values = $connection->execute(
            'declare
                   procedure p(i in rowid, o out rowid, io in out rowid) is
                   begin
                     select name into :name from typed_items where rowid = i;
                     o := i;
                     io := io;
                   end;
                 begin
                   p(:i, :o, :io);
                 end;',
            ['i' => new In\Rowid($rowid), 'o' => new Out\Rowid(), 'io' => new InOut\Rowid($rowid), 'name' => new Out\Varchar()],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['o' => [$rowid], 'io' => [$rowid], 'name' => ['Item 0001']]);
    });

    it('keeps a number string exact under any nls', function (): void {
        // Standalone: a pooled Session would keep the ALTER SESSION.
        $connection = $this->connect(standalone: true);
        $connection->execute("alter session set nls_numeric_characters = ',.'", [], $this->options());

        $dump = $this->value($connection, 'select dump(:v) from dual', ['v' => new In\Number('12.30')]);
        $negative = $this->value($connection, 'select :v from dual', ['v' => new In\Number('-.50')]);
        $long = $this->value($connection, 'select :v from dual', ['v' => new In\Number('12345678901234567890.123456789')]);

        expect($dump)->toBe('Typ=2 Len=3: 193,13,31')
            ->and($negative)->toBe('-0.5')
            ->and($long)->toBe('12345678901234567890.123456789');
    });

    it('refuses a varchar of bytes that are not utf8 before any round trip', function (): void {
        $connection = $this->connect();
        $sid = $this->sid($connection);
        $execute = fn (): mixed => $connection->execute('begin :v := :v; end;', ['v' => new In\Varchar("\xff")], $this->options());

        $trips = roundTrips($sid, static fn (): mixed => expect($execute)->toThrow(function (UsageException $thrown): void {
            expect($thrown->getMessage())->toContain("Bind 'v'")
                ->toContain('bind binary data with In\Raw or In\Blob');
        }));

        expect($trips)->toBe(0);
    });
});

describe('returning into', function (): void {
    it('gives the one row of an insert', function (): void {
        $connection = $this->connect();

        $execution = $connection->execute(
            'insert into typed_rows (id, name) values (:id, :name) returning id, name into :rid, :rname',
            ['id' => 7, 'name' => 'seven', 'rid' => new Out\Number(), 'rname' => new Out\Varchar()],
            $this->options(),
        );

        expect($execution->rowCount())->toBe(1)
            ->and($execution->outValues())->toBe(['rid' => [7], 'rname' => ['seven']]);
    });

    it('gives every row of an update, and none when nothing matches', function (): void {
        $connection = $this->connect();

        $two = $connection->execute(
            'update typed_items set price = price where id in (1, 2) returning id, name into :id, :name',
            ['id' => new Out\Number(), 'name' => new Out\Varchar()],
            $this->options(),
        )->outValues();
        $none = $connection->execute(
            'update typed_items set price = price where id < 0 returning id into :id',
            ['id' => new Out\Number()],
            $this->options(),
        )->outValues();

        expect($two['id'])->toEqualCanonicalizing([1, 2])
            ->and($two['name'])->toEqualCanonicalizing(['Item 0001', 'Item 0002'])
            ->and($none)->toBe(['id' => []]);
    });

    it('reads the longest sql varchar2', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            "update typed_items set price = price where id = 1 returning rpad('x', 4000, 'x') into :name",
            ['name' => new Out\Varchar()],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['name' => [str_repeat('x', 4000)]]);
    });
});

describe('varchar sizes', function (): void {
    it('holds 32767 bytes in a varchar out', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            "begin
                   :ascii := rpad('a', 32767, 'a');
                   :multibyte := rpad(unistr('\\00e7'), 16383, unistr('\\00e7'));
                 end;",
            ['ascii' => new Out\Varchar(), 'multibyte' => new Out\Varchar()],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['ascii' => [str_repeat('a', 32767)], 'multibyte' => [str_repeat('ç', 16383)]]);
    });

    it('grows a varchar in out to 32767 bytes', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            "begin
                   :from_null := rpad(nvl(:from_null, 'b'), 32767, 'b');
                   :from_a := rpad(:from_a, 32767, 'b');
                 end;",
            ['from_null' => new InOut\Varchar(null), 'from_a' => new InOut\Varchar('a')],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['from_null' => [str_repeat('b', 32767)], 'from_a' => ['a' . str_repeat('b', 32766)]]);
    });
});

describe('char and national text', function (): void {
    it('matches a blank padded char only with a char bind', function (mixed $bind, int $matches): void {
        $connection = $this->connect();

        $count = $this->value($connection, "select count(*) from (select cast('AB' as char(5)) code from dual) where code = :code", ['code' => $bind]);

        expect($count)->toBe($matches);
    })->with([
        'In\Char' => [new In\Char('AB'), 1],
        'a plain string' => ['AB', 0],
        'In\Varchar' => [new In\Varchar('AB'), 0],
    ]);

    it('keeps the padding of a char out', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            "declare c char(5) := 'AB'; n nchar(4) := n'\u{e7}'; begin :c := c; :n := n; end;",
            ['c' => new Out\Char(), 'n' => new Out\NChar()],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['c' => ['AB   '], 'n' => ['ç   ']]);
    });

    it('binds multibyte nvarchar and nchar text in the national character set', function (string $text, string $class): void {
        $connection = $this->connect();
        $bind = ['v' => $class === 'NVarchar' ? new In\NVarchar($text) : new In\NChar($text)];

        $read = $this->value($connection, 'select :v from dual', $bind);
        $dump = $this->value($connection, 'select dump(:v, 1016) from dual', $bind);

        expect($read)->toBe($text)
            ->and($dump)->toBeString()
            ->toContain('CharacterSet=AL16UTF16');
    })->with(['ç', '日本', '😀', 'ç日😀'])->with(['NVarchar', 'NChar']);

    it('carries multibyte text in nvarchar out and in out', function (string $text): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'begin :o := :i; :io := :io || :i; end;',
            ['i' => new In\NVarchar($text), 'o' => new Out\NVarchar(), 'io' => new InOut\NVarchar($text)],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['o' => [$text], 'io' => [$text . $text]]);
    })->with(['ç', '日本', '😀', 'ç日😀']);
});

describe('out values', function (): void {
    it('keeps the case the user wrote in out value keys', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'begin :TOTAL := 1; :name := 2; :Mixed := 3; end;',
            ['total' => new Out\Number(), 'NAME' => new Out\Number(), 'mIxEd' => new InOut\Number(0)],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['total' => [1], 'NAME' => [2], 'mIxEd' => [3]]);
    });

    it('follows the column mapping', function (NumberMode $numbers, TemporalMode $temporals, LobMode $lobs): void {
        $connection = $this->connect();
        $options = new Options(true, null, 100, new ValueMapping($numbers, $temporals, $lobs), RowShape::Assoc, KeyCase::Native);

        $values = inTimeZone('America/Sao_Paulo', fn (): array => $connection->execute(
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
                // These travel as TIMESTAMP and TSTZ, and come back as the type
                // declared.
                'd_io' => new InOut\Date(new DateTimeImmutable('2026-03-01 10:00:00.5')),
                'ltz_io' => new InOut\TimestampLtz(new DateTimeImmutable('2026-03-01 13:00:00', new DateTimeZone('UTC'))),
            ],
            $options,
        )->outValues());

        $float = $numbers === NumberMode::Float;
        $lob = $values['lob'][0];
        $lobText = $lob instanceof Locator ? $lob->readChunk() : $lob;

        expect($lob instanceof Locator)->toBe($lobs === LobMode::Locator)
            ->and($lobText)->toBe('a')
            ->and(array_map(static fn (array $list): array => array_map(temporalText(...), $list), array_intersect_key($values, array_flip(['d', 'ts', 'tz', 'ltz', 'd_io', 'ltz_io', 'ds', 'ym']))))->toBe($temporals === TemporalMode::String ? [
                'd' => ['2026-03-01 01:00:00'],
                'ts' => ['2026-03-01 10:00:00.123456789'],
                'tz' => ['2026-03-01 10:00:00.500000000 +05:45'],
                'ltz' => ['2026-03-01 10:00:00.000000000 -03:00'],
                'ds' => ['+0Y0M1DT2H3M4S.500000'],
                'ym' => ['+1Y2M0DT0H0M0S.000000'],
                'd_io' => ['2026-03-02 10:00:00'],
                'ltz_io' => ['2026-03-01 10:00:00.000000000 -03:00'],
            ] : [
                'd' => ['+2026-03-01 01:00:00.000000 America/Sao_Paulo'],
                'ts' => ['+2026-03-01 10:00:00.123456 America/Sao_Paulo'],
                'tz' => ['+2026-03-01 10:00:00.500000 +05:45'],
                'ltz' => ['+2026-03-01 10:00:00.000000 America/Sao_Paulo'],
                'ds' => ['+0Y0M1DT2H3M4S.500000'],
                'ym' => ['+1Y2M0DT0H0M0S.000000'],
                'd_io' => ['+2026-03-02 10:00:00.000000 America/Sao_Paulo'],
                'ltz_io' => ['+2026-03-01 10:00:00.000000 America/Sao_Paulo'],
            ])
            ->and(array_diff_key($values, array_flip(['lob', 'd', 'ts', 'tz', 'ltz', 'd_io', 'ltz_io', 'ds', 'ym'])))->toBe([
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
            ]);
    })->with(NumberMode::cases())->with(TemporalMode::cases())->with(LobMode::cases());
});

/**
 * A value as the tests compare it: NaN, which equals nothing, as text.
 */
function comparable(mixed $value): mixed
{
    return \is_float($value) && is_nan($value) ? 'NaN' : $value;
}
