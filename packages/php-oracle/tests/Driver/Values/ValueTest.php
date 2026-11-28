<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Values\ValueTest;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table value_items (id number(10) primary key)');
    schema()->run('insert into value_items values (1)');
    schema()->run('create table value_iot (id number(10) primary key, label varchar2(10)) organization index');
    schema()->run("insert into value_iot values (1, 'one')");
    schema()->run('create table value_longs (id number(10) primary key, text long)');
    schema()->run("insert into value_longs values (1, 'long text')");
    schema()->run('insert into value_longs values (2, null)');
    schema()->run('create table value_long_raws (id number(10) primary key, bytes long raw)');
    schema()->run("insert into value_long_raws values (1, hextoraw('00FF10'))");
    schema()->run('insert into value_long_raws values (2, null)');
});

describe('numbers', function (): void {
    it('maps a number to int or the canonical exact string', function (): void {
        $connection = $this->connect();

        $row = $this->rows($connection, <<<'SQL'
            select 1 one, -1 minus_one, 0 zero,
                   9223372036854775807 int_max, -9223372036854775808 int_min,
                   9223372036854775808 two_pow_63, -9223372036854775809 below_int_min,
                   1e30 e30, 12.30 twelve_30, 0.5 half, -0.5 minus_half,
                   1234567890123456789.0123456789012345678 digits_38, 1e-130 tiny,
                   (select count(*) from dual connect by level <= 3) counted,
                   (select sum(level) from dual connect by level <= 3) summed,
                   cast(5 as number) unconstrained
              from dual
            SQL)[0];

        expect($row)->toBe([
            'ONE' => 1,
            'MINUS_ONE' => -1,
            'ZERO' => 0,
            'INT_MAX' => \PHP_INT_MAX,
            'INT_MIN' => \PHP_INT_MIN,
            'TWO_POW_63' => '9223372036854775808',
            'BELOW_INT_MIN' => '-9223372036854775809',
            'E30' => '1000000000000000000000000000000',
            'TWELVE_30' => '12.3',
            'HALF' => '0.5',
            'MINUS_HALF' => '-0.5',
            'DIGITS_38' => '1234567890123456789.0123456789012345678',
            'TINY' => '0.' . str_repeat('0', 129) . '1',
            'COUNTED' => 3,
            'SUMMED' => 6,
            'UNCONSTRAINED' => 5,
        ]);
    });

    it('maps a non integral number to a float under the float mapping', function (): void {
        $float = $this->options(values: new ValueMapping(numbers: NumberMode::Float));

        $row = $this->rows($this->connect(), 'select 12.5 half, 5 five, 0.1 tenth, cast(null as number) nothing from dual', options: $float)[0];

        expect($row)->toBe(['HALF' => 12.5, 'FIVE' => 5, 'TENTH' => 0.1, 'NOTHING' => null]);
    });

    it('gives floats for binary float and double columns with nan and infinities', function (): void {
        $connection = $this->connect();

        $row = $this->rows($connection, <<<'SQL'
            select to_binary_float(0.1) bf, to_binary_double(0.1) bd,
                   to_binary_float(-12.5) negative_bf, to_binary_double('1e300') huge_bd,
                   binary_float_nan bf_nan, binary_double_nan bd_nan,
                   binary_float_infinity bf_inf, -binary_double_infinity bd_minus_inf,
                   cast(null as binary_float) no_bf, cast(null as binary_double) no_bd
              from dual
            SQL)[0];

        expect(array_diff_key($row, ['BF_NAN' => true, 'BD_NAN' => true]))->toBe([
            'BF' => 0.1,
            'BD' => 0.1,
            'NEGATIVE_BF' => -12.5,
            'HUGE_BD' => 1e300,
            'BF_INF' => \INF,
            'BD_MINUS_INF' => -\INF,
            'NO_BF' => null,
            'NO_BD' => null,
        ])
            ->and($row['BF_NAN'])->toBeFloat()
            ->toBeNan()
            ->and($row['BD_NAN'])->toBeFloat()
            ->toBeNan();
    });

    it('stores binary float and double binds with nan and infinities', function (float $value, string $text): void {
        $connection = $this->connect();

        $rows = [];
        foreach ([new In\BinaryFloat($value), new In\BinaryDouble($value)] as $bind) {
            $rows[] = $this->rows($connection, 'select to_char(:v) text, :v value from dual', ['v' => $bind])[0];
        }

        foreach ($rows as $row) {
            assert(\is_float($row['VALUE']));
            expect($row['TEXT'])->toBe($text)
                ->and(is_nan($row['VALUE']) ? 'NaN' : $row['VALUE'])->toBe(is_nan($value) ? 'NaN' : $value);
        }
    })->with([
        '1.5' => [1.5, '1.5E+000'],
        'NaN' => [\NAN, 'Nan'],
        'INF' => [\INF, 'Inf'],
        '-INF' => [-\INF, '-Inf'],
    ]);
});

describe('dates and times', function (): void {
    it('gives the wall clock of date and timestamp in the zone of php', function (): void {
        $row = inTimeZone('America/Sao_Paulo', fn (): array => $this->rows($this->connect(), <<<'SQL'
            select date '2026-03-01' + 1/24 + 2/1440 + 3/86400 d,
                   cast(timestamp '2026-03-01 10:00:00' as timestamp(0)) t0,
                   cast(timestamp '2026-03-01 10:00:00.123456' as timestamp(6)) t6,
                   cast(timestamp '2026-03-01 10:00:00.123456789' as timestamp(9)) t9,
                   to_date('-4712-01-01 12:00:00', 'SYYYY-MM-DD HH24:MI:SS') bc4712,
                   to_date('-0001-12-31 23:59:59', 'SYYYY-MM-DD HH24:MI:SS') bc1,
                   to_date('9999-12-31 23:59:59', 'YYYY-MM-DD HH24:MI:SS') last,
                   cast(null as date) no_date, cast(null as timestamp) no_timestamp
              from dual
            SQL)[0]);

        expect(array_map(temporalText(...), $row))->toBe([
            'D' => '+2026-03-01 01:02:03.000000 America/Sao_Paulo',
            'T0' => '+2026-03-01 10:00:00.000000 America/Sao_Paulo',
            'T6' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
            'T9' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
            'BC4712' => '-4711-01-01 12:00:00.000000 America/Sao_Paulo',
            'BC1' => '+0000-12-31 23:59:59.000000 America/Sao_Paulo',
            'LAST' => '+9999-12-31 23:59:59.000000 America/Sao_Paulo',
            'NO_DATE' => null,
            'NO_TIMESTAMP' => null,
        ]);
    });

    it('binds a fetched wall clock back unchanged with bc years', function (string $text, string $bind): void {
        $stored = "to_date('$text', 'SYYYY-MM-DD HH24:MI:SS')";

        [$fetched, $equal, $readBack] = inTimeZone('America/Sao_Paulo', function () use ($stored, $bind): array {
            $connection = $this->connect();
            $fetched = $this->value($connection, "select $stored from dual");
            assert($fetched instanceof DateTimeImmutable);
            $value = $bind === 'Date' ? new In\Date($fetched) : new In\Timestamp($fetched);

            return [
                $fetched,
                $this->value($connection, "select case when :v = $stored then 1 else 0 end from dual", ['v' => $value]),
                $this->value($connection, 'select :v from dual', ['v' => $value]),
            ];
        });

        expect($equal)->toBe(1)
            ->and(temporalText($readBack))->toBe(temporalText($fetched));
    })->with(['-4712-01-01 12:00:00', '-0001-12-31 23:59:59', '0001-01-01 00:00:00', '9999-12-31 23:59:59'])->with(['Date', 'Timestamp']);

    it('keeps the offset of a timestamp with time zone', function (): void {
        $connection = $this->connect();

        $row = $this->rows($connection, <<<'SQL'
            select timestamp '2026-03-01 10:00:00.5 -03:00' minus3,
                   timestamp '2026-03-01 10:00:00.123456789 +05:45' plus545,
                   timestamp '2026-03-01 10:00:00 +00:00' zero,
                   timestamp '2026-07-01 10:00:00 America/New_York' region,
                   cast(null as timestamp with time zone) nothing
              from dual
            SQL)[0];

        expect(array_map(temporalText(...), $row))->toBe([
            'MINUS3' => '+2026-03-01 10:00:00.500000 -03:00',
            'PLUS545' => '+2026-03-01 10:00:00.123456 +05:45',
            'ZERO' => '+2026-03-01 10:00:00.000000 +00:00',
            'REGION' => '+2026-07-01 14:00:00.000000 +00:00',
            'NOTHING' => null,
        ]);
    });

    it('stores the offset of the bound object', function (DateTimeInterface $date, string $expected): void {
        [$stored, $readBack] = inTimeZone('America/Sao_Paulo', function () use ($date): array {
            $connection = $this->connect();
            $bind = ['v' => new In\TimestampTz($date)];

            return [
                $this->value($connection, "select to_char(:v, 'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM') from dual", $bind),
                $this->value($connection, 'select :v from dual', $bind),
            ];
        });

        expect($stored)->toBe(substr($expected, 1))
            ->and(temporalText($readBack))->toBe($expected);
    })->with([
        'Asia/Kathmandu' => [new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Kathmandu')), '+2026-03-01 10:00:00.123456 +05:45'],
        'America/New_York in summer' => [new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('America/New_York')), '+2026-07-01 10:00:00.000000 -04:00'],
        'a mutable DateTime' => [new DateTime('2026-01-01 10:00:00', new DateTimeZone('America/New_York')), '+2026-01-01 10:00:00.000000 -05:00'],
        'UTC' => [new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC')), '+2026-03-01 10:00:00.000000 +00:00'],
    ]);

    it('fails before any round trip for an offset with seconds', function (): void {
        $connection = $this->connect();
        $sid = $this->sid($connection);
        $localMeanTime = new DateTimeImmutable('1890-01-01 00:00:00', new DateTimeZone('America/Sao_Paulo'));

        $trips = roundTrips($sid, fn (): mixed => expect(fn (): mixed => $connection->execute('select :v from dual', ['v' => new In\TimestampTz($localMeanTime)], $this->options()))
            ->toThrow(function (UsageException $usageException): void {
                expect($usageException->getMessage())->toContain("Bind 'v'")->toContain('whole minutes');
            }));

        expect($trips)->toBe(0);
    });

    it('binds a date whose offset has seconds as an instant', function (): void {
        $connection = $this->connect();
        $localMeanTime = new DateTimeImmutable('1890-01-01 00:00:00', new DateTimeZone('America/Sao_Paulo'));

        $utc = $this->value(
            $connection,
            "select to_char(sys_extract_utc(cast(:v as timestamp with time zone)), 'YYYY-MM-DD HH24:MI:SS') from dual",
            ['v' => new In\TimestampLtz($localMeanTime)],
        );

        expect($utc)->toBe('1890-01-01 03:06:28');
    });

    it('gives a timestamp with local time zone as the instant in the zone of php', function (string $zone, string $expected): void {
        $row = inTimeZone($zone, fn (): array => $this->rows($this->connect(), <<<'SQL'
            select cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) instant,
                   cast(null as timestamp with local time zone) nothing
              from dual
            SQL)[0]);

        expect(array_map(temporalText(...), $row))->toBe(['INSTANT' => $expected, 'NOTHING' => null]);
    })->with([
        'America/Sao_Paulo' => ['America/Sao_Paulo', '+2026-03-01 10:00:00.250000 America/Sao_Paulo'],
        'Asia/Tokyo' => ['Asia/Tokyo', '+2026-03-01 22:00:00.250000 Asia/Tokyo'],
    ]);

    it('names the zone of php as the session time zone', function (string $zone): void {
        $sessionZone = inTimeZone($zone, fn (): mixed => $this->value($this->connect(), 'select sessiontimezone from dual'));

        expect($sessionZone)->toBe($zone);
    })->with(['America/New_York', 'UTC']);

    it('gives intervals as date intervals', function (): void {
        $connection = $this->connect();

        $row = $this->rows($connection, <<<'SQL'
            select interval '3 04:05:06.789' day to second ds,
                   interval '-3 04:05:06.123456789' day(2) to second(9) negative_ds,
                   interval '2-5' year to month ym,
                   interval '-2-5' year to month negative_ym,
                   cast(null as interval day to second) no_ds,
                   cast(null as interval year to month) no_ym
              from dual
            SQL)[0];

        expect(array_map(temporalText(...), $row))->toBe([
            'DS' => '+0Y0M3DT4H5M6S.789000',
            'NEGATIVE_DS' => '-0Y0M3DT4H5M6S.123456',
            'YM' => '+2Y5M0DT0H0M0S.000000',
            'NEGATIVE_YM' => '-2Y5M0DT0H0M0S.000000',
            'NO_DS' => null,
            'NO_YM' => null,
        ]);
    });

    it('gives nls independent iso text under the string mapping', function (string $nls): void {
        $row = inTimeZone('America/Sao_Paulo', function () use ($nls): array {
            // Standalone: a pooled Session would keep the ALTER SESSION.
            $connection = $this->connect(standalone: true);
            if ($nls !== '') {
                $connection->execute("alter session set $nls", [], $this->options());
            }

            return $this->rows($connection, <<<'SQL'
                select date '2026-03-01' + 1/24 d,
                       cast(timestamp '2026-03-01 10:00:00' as timestamp(0)) t0,
                       cast(timestamp '2026-03-01 10:00:00.123456789' as timestamp(9)) t9,
                       timestamp '2026-03-01 10:00:00.5 +05:45' tz,
                       cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) ltz,
                       to_date('-4712-01-01', 'SYYYY-MM-DD') bc,
                       interval '1 02:03:04' day to second ds,
                       cast(null as date) nothing
                  from dual
                SQL, options: $this->options(values: new ValueMapping(temporals: TemporalMode::String)))[0];
        });

        expect(array_map(temporalText(...), $row))->toBe([
            'D' => '2026-03-01 01:00:00',
            'T0' => '2026-03-01 10:00:00.000000000',
            'T9' => '2026-03-01 10:00:00.123456789',
            'TZ' => '2026-03-01 10:00:00.500000000 +05:45',
            'LTZ' => '2026-03-01 10:00:00.250000000 -03:00',
            'BC' => '-4711-01-01 00:00:00',
            'DS' => '+0Y0M1DT2H3M4S.000000',
            'NOTHING' => null,
        ]);
    })->with([
        'default NLS' => [''],
        'unusual NLS' => ["nls_date_format = 'DD/MON/RR HH:MI' nls_timestamp_format = 'DD.MM.YYYY FF3' nls_timestamp_tz_format = 'TZR DD.MM.YYYY' nls_numeric_characters = ',.' nls_date_language = 'GERMAN'"],
    ]);
});

describe('other types', function (): void {
    it('maps char raw and boolean columns as stated', function (): void {
        $connection = $this->connect();

        $row = $this->rows($connection, <<<'SQL'
            select cast('ab' as char(5)) ch, cast(unistr('\00e7b') as nchar(4)) nch,
                   hextoraw('00FF10') bytes, true yes, false no,
                   cast(null as char(1)) no_ch, cast(null as nchar(1)) no_nch,
                   cast(null as raw(1)) no_bytes, cast(null as boolean) no_flag
              from dual
            SQL)[0];

        expect($row)->toBe([
            'CH' => 'ab   ',
            'NCH' => 'çb  ',
            'BYTES' => "\x00\xff\x10",
            'YES' => true,
            'NO' => false,
            'NO_CH' => null,
            'NO_NCH' => null,
            'NO_BYTES' => null,
            'NO_FLAG' => null,
        ]);
    });

    it('maps long and long raw columns to text and bytes', function (): void {
        $connection = $this->connect();

        $texts = $this->rows($connection, 'select id, text from value_longs order by id');
        $bytes = $this->rows($connection, 'select id, bytes from value_long_raws order by id');

        expect($texts)->toBe([['ID' => 1, 'TEXT' => 'long text'], ['ID' => 2, 'TEXT' => null]])
            ->and($bytes)->toBe([['ID' => 1, 'BYTES' => "\x00\xff\x10"], ['ID' => 2, 'BYTES' => null]]);
    });

    it('reads a rowid as its text and binds it back to find its row', function (string $table, string $column, mixed $expected): void {
        $connection = $this->connect();
        $row = $this->rows($connection, "select rowid rid, cast(rowid as varchar2(4000)) rid_text, cast(null as rowid) no_rid from $table")[0];
        assert(\is_string($row['RID']));

        $found = $this->value($connection, "select $column from $table where rowid = :r", ['r' => new In\Rowid($row['RID'])]);

        expect($row['RID'])->toBe($row['RID_TEXT'])
            ->and($row['NO_RID'])->toBeNull()
            ->and($found)->toBe($expected);
    })->with([
        'ROWID' => ['value_items', 'id', 1],
        // An index-organized table's ROWID is a UROWID.
        'UROWID' => ['value_iot', 'label', 'one'],
    ]);

    it('gives identical values under unusual nls settings', function (): void {
        $sql = <<<'SQL'
            select 12.5 num, 1e-5 tiny, 123456789 whole, to_binary_float(1.5) bf, to_binary_double(0.1) bd,
                   date '2026-03-01' + 1/24 d,
                   cast(timestamp '2026-03-01 10:00:00.123456789' as timestamp(9)) ts,
                   timestamp '2026-03-01 10:00:00.5 +05:45' tz,
                   cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) ltz,
                   interval '1 02:03:04.5' day to second ds, interval '-2-5' year to month ym,
                   cast('ab' as char(3)) ch, hextoraw('0A') bytes, true flag
              from dual
            SQL;
        $float = $this->options(values: new ValueMapping(numbers: NumberMode::Float));

        [$before, $after] = inTimeZone('America/Sao_Paulo', function () use ($sql, $float): array {
            // Standalone: a pooled Session would keep the ALTER SESSION.
            $connection = $this->connect(standalone: true);
            $read = fn (Options | null $options = null): array => array_map(temporalText(...), $this->rows($connection, $sql, [], $options)[0]);
            $before = [$read(), $read($float)];
            $connection->execute(<<<'SQL'
                alter session set nls_numeric_characters = ',.' nls_date_format = 'DD/MON/RR HH:MI'
                    nls_timestamp_format = 'DD.MM.YYYY FF3' nls_timestamp_tz_format = 'TZR DD.MM.YYYY'
                    nls_date_language = 'GERMAN' nls_territory = 'GERMANY' nls_language = 'GERMAN'
                SQL, [], $this->options());

            return [$before, [$read(), $read($float)]];
        });

        expect($after)->toBe($before)
            ->and($before[0])->toBe([
                'NUM' => '12.5', 'TINY' => '0.00001', 'WHOLE' => 123456789, 'BF' => 1.5, 'BD' => 0.1,
                'D' => '+2026-03-01 01:00:00.000000 America/Sao_Paulo',
                'TS' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
                'TZ' => '+2026-03-01 10:00:00.500000 +05:45',
                'LTZ' => '+2026-03-01 10:00:00.250000 America/Sao_Paulo',
                'DS' => '+0Y0M1DT2H3M4S.500000',
                'YM' => '-2Y5M0DT0H0M0S.000000',
                'CH' => 'ab ', 'BYTES' => "\x0a", 'FLAG' => true,
            ])
            ->and($before[1]['NUM'])->toBe(12.5);
    });
});
