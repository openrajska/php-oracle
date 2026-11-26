<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ValuesTest;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Support\Temporals;

test('number maps to int or the canonical exact string', function (): void {
    $tiny = '0.' . str_repeat('0', 129) . '1';
    $expected = [
        'ONE' => 1,
        'MINUS_ONE' => -1,
        'ZERO' => 0,
        'INT_MAX' => PHP_INT_MAX,
        'INT_MIN' => PHP_INT_MIN,
        'TWO_POW_63' => '9223372036854775808',
        'BELOW_INT_MIN' => '-9223372036854775809',
        'E30' => '1000000000000000000000000000000',
        'TWELVE_30' => '12.3',
        'HALF' => '0.5',
        'MINUS_HALF' => '-0.5',
        'DIGITS_38' => '1234567890123456789.0123456789012345678',
        'TINY' => $tiny,
        'COUNTED' => 3,
        'SUMMED' => 6,
        'UNCONSTRAINED' => 5,
    ];
    $sql = <<<'SQL'
            select 1 one, -1 minus_one, 0 zero,
                   9223372036854775807 int_max, -9223372036854775808 int_min,
                   9223372036854775808 two_pow_63, -9223372036854775809 below_int_min,
                   1e30 e30, 12.30 twelve_30, 0.5 half, -0.5 minus_half,
                   1234567890123456789.0123456789012345678 digits_38, 1e-130 tiny,
                   (select count(*) from fx_items where id <= 3) counted,
                   (select sum(id) from fx_items where id <= 3) summed,
                   cast(5 as number) unconstrained
              from dual
            SQL;

    $connection = $this->connect();
    expect($this->rows($connection, $sql))->toBe([$expected]);

    $connection->execute("alter session set nls_numeric_characters = ',.'", [], $this->options());
    expect($this->rows($connection, $sql))->toBe([$expected]);
});

test('numbers float maps a non integral number to a float', function (): void {
    $float = $this->options(values: new ValueMapping(numbers: NumberMode::Float));

    expect($this->rows($this->connect(), 'select 12.5 half, 5 five, 0.1 tenth, cast(null as number) nothing from dual', options: $float))->toBe([['HALF' => 12.5, 'FIVE' => 5, 'TENTH' => 0.1, 'NOTHING' => null]]);
});

test('date and timestamp give the wall clock in phps zone', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        $row = $this->rows($this->connect(), <<<'SQL'
                select date '2026-03-01' + 1/24 + 2/1440 + 3/86400 d,
                       cast(timestamp '2026-03-01 10:00:00' as timestamp(0)) t0,
                       cast(timestamp '2026-03-01 10:00:00.123456' as timestamp(6)) t6,
                       cast(timestamp '2026-03-01 10:00:00.123456789' as timestamp(9)) t9,
                       to_date('-4712-01-01 12:00:00', 'SYYYY-MM-DD HH24:MI:SS') bc4712,
                       to_date('-0001-12-31 23:59:59', 'SYYYY-MM-DD HH24:MI:SS') bc1,
                       to_date('9999-12-31 23:59:59', 'YYYY-MM-DD HH24:MI:SS') last,
                       cast(null as date) no_date, cast(null as timestamp) no_timestamp
                  from dual
                SQL)[0];

        expect(array_map(Temporals::shown(...), $row))->toBe([
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
});

test('the wall clock round trips bc years included', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        $connection = $this->connect();
        foreach (['-4712-01-01 12:00:00', '-0001-12-31 23:59:59', '0001-01-01 00:00:00', '9999-12-31 23:59:59'] as $text) {
            $stored = "to_date('$text', 'SYYYY-MM-DD HH24:MI:SS')";
            $fetched = $this->value($connection, "select $stored from dual");
            assert($fetched instanceof DateTimeImmutable);

            foreach ([new In\Date($fetched), new In\Timestamp($fetched)] as $bind) {
                expect($this->value($connection, "select case when :v = $stored then 1 else 0 end from dual", ['v' => $bind]))->toBe(1, $text)
                    ->and(Temporals::shown($this->value($connection, 'select :v from dual', ['v' => $bind])))->toBe(Temporals::shown($fetched), $text);
            }
        }
    });
});

test('tstz keeps its own offset', function (): void {
    $row = $this->rows($this->connect(), <<<'SQL'
            select timestamp '2026-03-01 10:00:00.5 -03:00' minus3,
                   timestamp '2026-03-01 10:00:00.123456789 +05:45' plus545,
                   timestamp '2026-03-01 10:00:00 +00:00' zero,
                   timestamp '2026-07-01 10:00:00 America/New_York' region,
                   cast(null as timestamp with time zone) nothing
              from dual
            SQL)[0];

    expect(array_map(Temporals::shown(...), $row))->toBe([
        'MINUS3' => '+2026-03-01 10:00:00.500000 -03:00',
        'PLUS545' => '+2026-03-01 10:00:00.123456 +05:45',
        'ZERO' => '+2026-03-01 10:00:00.000000 +00:00',
        'REGION' => '+2026-07-01 14:00:00.000000 +00:00',
        'NOTHING' => null,
    ]);
});

test('timestamp tz in stores the objects offset', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        $connection = $this->connect();
        $format = "'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM'";
        $cases = [
            '+2026-03-01 10:00:00.123456 +05:45' => new DateTimeImmutable('2026-03-01 10:00:00.123456', new DateTimeZone('Asia/Kathmandu')),
            '+2026-07-01 10:00:00.000000 -04:00' => new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('America/New_York')),
            '+2026-01-01 10:00:00.000000 -05:00' => new DateTime('2026-01-01 10:00:00', new DateTimeZone('America/New_York')),
            '+2026-03-01 10:00:00.000000 +00:00' => new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC')),
        ];
        foreach ($cases as $expected => $date) {
            $stored = $this->value($connection, "select to_char(:v, $format) from dual", ['v' => new In\TimestampTz($date)]);
            expect($stored)->toBe(ltrim(substr($expected, 1)), $expected)
                ->and(Temporals::shown($this->value($connection, 'select :v from dual', ['v' => new In\TimestampTz($date)])))->toBe($expected);
        }
    });
});

test('a tstz offset with seconds fails before any round trip', function (): void {
    $connection = $this->connect();
    $lmt = new DateTimeImmutable('1890-01-01 00:00:00', new DateTimeZone('America/Sao_Paulo'));
    expect($lmt->getOffset() % 60)->not->toBe(0);

    try {
        $connection->execute('select :v from dual', ['v' => new In\TimestampTz($lmt)], $this->options());
        $this->fail('An offset with seconds was bound.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toContain("Bind 'v'")
            ->and($usageException->getMessage())->toContain('whole minutes');
    }

    // As the Instant (TSLTZ) or the Wall clock, the same date binds.
    expect($this->value($connection, "select to_char(sys_extract_utc(cast(:v as timestamp with time zone)), 'YYYY-MM-DD HH24:MI:SS') from dual", ['v' => new In\TimestampLtz($lmt)]))->toBe('1890-01-01 03:06:28');
});

test('tsltz gives the instant in phps zone', function (): void {
    foreach (['America/Sao_Paulo' => '+2026-03-01 10:00:00.250000 America/Sao_Paulo', 'Asia/Tokyo' => '+2026-03-01 22:00:00.250000 Asia/Tokyo'] as $zone => $expected) {
        Temporals::inZone($zone, function () use ($expected): void {
            $row = $this->rows($this->connect(), <<<'SQL'
                    select cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) instant,
                           cast(null as timestamp with local time zone) nothing
                      from dual
                    SQL)[0];

            expect(array_map(Temporals::shown(...), $row))->toBe(['INSTANT' => $expected, 'NOTHING' => null]);
        });
    }
});

test('the session time zone is phps zone by name', function (): void {
    Temporals::inZone('America/New_York', function (): void {
        $connection = $this->connect();
        expect($this->value($connection, 'select sessiontimezone from dual'))->toBe('America/New_York')
            ->and($this->rows($connection, <<<'SQL'
                    select to_char(from_tz(timestamp '2026-01-15 12:00:00', sessiontimezone), 'TZH:TZM') winter,
                           to_char(from_tz(timestamp '2026-07-15 12:00:00', sessiontimezone), 'TZH:TZM') summer
                      from dual
                    SQL)[0])->toBe(['WINTER' => '-05:00', 'SUMMER' => '-04:00']);
        // CURRENT_TIMESTAMP carries the zone's region name, so its offset
        // is read on the server.
        expect($this->value($connection, "select to_char(current_timestamp, 'TZH:TZM') from dual"))->toBe(new DateTimeImmutable('now')->format('P'));
    });
    Temporals::inZone('UTC', function (): void {
        expect($this->value($this->connect(), 'select sessiontimezone from dual'))->toBe('UTC');
    });
});

test('intervals give date intervals', function (): void {
    $row = $this->rows($this->connect(), <<<'SQL'
            select interval '3 04:05:06.789' day to second ds,
                   interval '-3 04:05:06.123456789' day(2) to second(9) negative_ds,
                   interval '2-5' year to month ym,
                   interval '-2-5' year to month negative_ym,
                   cast(null as interval day to second) no_ds,
                   cast(null as interval year to month) no_ym
              from dual
            SQL)[0];

    expect(array_map(Temporals::shown(...), $row))->toBe([
        'DS' => '+0Y0M3DT4H5M6S.789000',
        'NEGATIVE_DS' => '-0Y0M3DT4H5M6S.123456',
        'YM' => '+2Y5M0DT0H0M0S.000000',
        'NEGATIVE_YM' => '-2Y5M0DT0H0M0S.000000',
        'NO_DS' => null,
        'NO_YM' => null,
    ]);
});

test('temporals string gives nls independent iso text', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        // Standalone: a pooled Session would keep the ALTER SESSION.
        $connection = $this->connect(standalone: true);
        $sql = <<<'SQL'
                select date '2026-03-01' + 1/24 d,
                       cast(timestamp '2026-03-01 10:00:00' as timestamp(0)) t0,
                       cast(timestamp '2026-03-01 10:00:00.123456789' as timestamp(9)) t9,
                       timestamp '2026-03-01 10:00:00.5 +05:45' tz,
                       cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) ltz,
                       to_date('-4712-01-01', 'SYYYY-MM-DD') bc,
                       interval '1 02:03:04' day to second ds,
                       cast(null as date) nothing
                  from dual
                SQL;
        $string = $this->options(values: new ValueMapping(temporals: TemporalMode::String));
        $expected = [
            'D' => '2026-03-01 01:00:00',
            'T0' => '2026-03-01 10:00:00.000000000',
            'T9' => '2026-03-01 10:00:00.123456789',
            'TZ' => '2026-03-01 10:00:00.500000000 +05:45',
            'LTZ' => '2026-03-01 10:00:00.250000000 -03:00',
            'BC' => '-4711-01-01 00:00:00',
            'DS' => '+0Y0M1DT2H3M4S.000000',
            'NOTHING' => null,
        ];

        expect(array_map(Temporals::shown(...), $this->rows($connection, $sql, options: $string)[0]))->toBe($expected);

        $connection->execute(
            "alter session set nls_date_format = 'DD/MON/RR HH:MI' nls_timestamp_format = 'DD.MM.YYYY FF3' nls_timestamp_tz_format = 'TZR DD.MM.YYYY' nls_numeric_characters = ',.' nls_date_language = 'GERMAN'",
            [],
            $this->options(),
        );
        expect(array_map(Temporals::shown(...), $this->rows($connection, $sql, options: $string)[0]))->toBe($expected)
            ->and(Temporals::shown($this->rows($connection, $sql)[0]['T9']))->toBe('+2026-03-01 10:00:00.123456 America/Sao_Paulo');
    });
});

test('binary float and double give floats nan and infinities included', function (): void {
    $row = $this->rows($this->connect(), <<<'SQL'
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
        'BF_INF' => INF,
        'BD_MINUS_INF' => -INF,
        'NO_BF' => null,
        'NO_BD' => null,
    ])
        ->and($row['BF_NAN'])->toBeFloat()
        ->toBeNan()
        ->and($row['BD_NAN'])->toBeFloat()
        ->toBeNan();
});

test('nan and infinities round trip', function (): void {
    $connection = $this->connect();
    foreach (['Nan' => NAN, 'Inf' => INF, '-Inf' => -INF] as $stored => $value) {
        foreach ([new In\BinaryFloat($value), new In\BinaryDouble($value)] as $bind) {
            $row = $this->rows($connection, 'select :v value, to_char(:v) text from dual', ['v' => $bind])[0];

            expect($row['TEXT'])->toBe($stored, $bind::class);
            assert(\is_float($row['VALUE']));
            expect(is_nan($row['VALUE']) ? 'NAN' : (string) $row['VALUE'])->toBe(is_nan($value) ? 'NAN' : (string) $value, $bind::class);
        }
    }
    expect($this->value($connection, 'select :v from dual', ['v' => new In\BinaryFloat(0.1)]))->toBe(0.1)
        ->and($this->value($connection, 'select :v from dual', ['v' => new In\BinaryDouble(0.1)]))->toBe(0.1);
});

test('char raw rowid boolean and long map as stated', function (): void {
    $connection = $this->connect();
    $row = $this->rows($connection, <<<'SQL'
            select cast('ab' as char(5)) ch, cast(unistr('\00e7b') as nchar(4)) nch,
                   hextoraw('00FF10') bytes, true yes, false no,
                   i.rowid rid, cast(i.rowid as varchar2(18)) rid_text,
                   cast(null as char(1)) no_ch, cast(null as nchar(1)) no_nch,
                   cast(null as raw(1)) no_bytes, cast(null as boolean) no_flag,
                   cast(null as rowid) no_rid, cast(null as urowid) no_urid
              from fx_items i
             where i.id = 1
            SQL)[0];
    assert(\is_string($row['RID']));
    expect($row['RID'])->toHaveLength(18)
        ->and($row)->toBe([
            'CH' => 'ab   ',
            'NCH' => 'çb  ',
            'BYTES' => "\x00\xff\x10",
            'YES' => true,
            'NO' => false,
            'RID' => $row['RID_TEXT'],
            'RID_TEXT' => $row['RID'],
            'NO_CH' => null,
            'NO_NCH' => null,
            'NO_BYTES' => null,
            'NO_FLAG' => null,
            'NO_RID' => null,
            'NO_URID' => null,
        ])
        ->and($this->value($connection, 'select id from fx_items where rowid = :r', ['r' => new In\Rowid($row['RID'])]))->toBe(1);

    // An index-organized table's ROWID is a UROWID: its text, which
    // finds the row again.
    $urowid = $this->rows($connection, 'select rowid urid, cast(rowid as varchar2(4000)) urid_text from fx_iot')[0];
    assert(\is_string($urowid['URID']));
    expect($urowid['URID'])->toBe($urowid['URID_TEXT'])
        ->and($this->value($connection, 'select label from fx_iot where rowid = :r', ['r' => new In\Rowid($urowid['URID'])]))->toBe('one')
        ->and($this->rows($connection, 'select id, text from fx_long_rows order by id'))->toBe([['ID' => 1, 'TEXT' => 'long text'], ['ID' => 2, 'TEXT' => null]])
        ->and($this->rows($connection, 'select id, bytes from fx_long_raw_rows order by id'))->toBe([['ID' => 1, 'BYTES' => "\x00\xff\x10"], ['ID' => 2, 'BYTES' => null]]);
});

test('values are identical under unusual nls settings', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        // Standalone: a pooled Session would keep the ALTER SESSION.
        $connection = $this->connect(standalone: true);
        $sql = <<<'SQL'
                select 12.5 num, 1e-5 tiny, 123456789 whole, to_binary_float(1.5) bf, to_binary_double(0.1) bd,
                       date '2026-03-01' + 1/24 d,
                       cast(timestamp '2026-03-01 10:00:00.123456789' as timestamp(9)) ts,
                       timestamp '2026-03-01 10:00:00.5 +05:45' tz,
                       cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) ltz,
                       interval '1 02:03:04.5' day to second ds, interval '-2-5' year to month ym,
                       cast('ab' as char(3)) ch, hextoraw('0A') bytes, true flag,
                       to_char(:n) number_text
                  from dual
                SQL;
        $binds = ['n' => new In\Number('12.30')];
        $float = $this->options(values: new ValueMapping(numbers: NumberMode::Float));
        $read = fn (Options | null $options = null): array => array_map(
            Temporals::shown(...),
            $this->rows($connection, $sql, $binds, $options)[0],
        );
        $before = [$read(), $read($float)];
        expect($before[0])->toBe([
            'NUM' => '12.5', 'TINY' => '0.00001', 'WHOLE' => 123456789, 'BF' => 1.5, 'BD' => 0.1,
            'D' => '+2026-03-01 01:00:00.000000 America/Sao_Paulo',
            'TS' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
            'TZ' => '+2026-03-01 10:00:00.500000 +05:45',
            'LTZ' => '+2026-03-01 10:00:00.250000 America/Sao_Paulo',
            'DS' => '+0Y0M1DT2H3M4S.500000',
            'YM' => '-2Y5M0DT0H0M0S.000000',
            'CH' => 'ab ', 'BYTES' => "\x0a", 'FLAG' => true,
            'NUMBER_TEXT' => '12.3',
        ])
            ->and($before[1]['NUM'])->toBe(12.5);

        $connection->execute(
            <<<'SQL'
                    alter session set nls_numeric_characters = ',.' nls_date_format = 'DD/MON/RR HH:MI'
                        nls_timestamp_format = 'DD.MM.YYYY FF3' nls_timestamp_tz_format = 'TZR DD.MM.YYYY'
                        nls_date_language = 'GERMAN' nls_territory = 'GERMANY' nls_language = 'GERMAN'
                    SQL,
            [],
            $this->options(),
        );

        // Only the server's own TO_CHAR follows the NLS settings.
        $after = [$read(), $read($float)];
        expect($after[0]['NUMBER_TEXT'])->toBe('12,3');
        $after[0]['NUMBER_TEXT'] = $after[1]['NUMBER_TEXT'] = '12.3';
        expect($after)->toBe($before);
    });
});
