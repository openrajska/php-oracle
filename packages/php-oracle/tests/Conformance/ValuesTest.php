<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Date;
use Rajska\Oracle\Bind\Timestamp;
use Rajska\Oracle\Bind\TimestampLtz;
use Rajska\Oracle\Bind\TimestampTz;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\ValueMapping;

/**
 * Conformance catalog, Values (#23).
 */
final class ValuesTest extends ConformanceTestCase
{
    /**
     * Values 1: `int` when integral and within 64 bits (decided per value),
     * else the canonical exact string, whatever the NLS settings.
     */
    #[Test]
    public function number_maps_to_int_or_the_canonical_exact_string(): void
    {
        $tiny = '0.'.str_repeat('0', 129).'1';
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
        self::assertSame([$expected], self::rows($connection, $sql));

        $connection->execute("alter session set nls_numeric_characters = ',.'", [], self::options());
        self::assertSame([$expected], self::rows($connection, $sql));
    }

    /**
     * Values 2, query part: `numbers: Float` turns a non-integral NUMBER
     * into a float; an integral one stays an int.
     */
    #[Test]
    public function numbers_float_maps_a_non_integral_number_to_a_float(): void
    {
        $float = self::options(values: new ValueMapping(numbers: NumberMode::Float));

        self::assertSame(
            [['HALF' => 12.5, 'FIVE' => 5, 'TENTH' => 0.1, 'NOTHING' => null]],
            self::rows($this->connect(), 'select 12.5 half, 5 five, 0.1 tenth, cast(null as number) nothing from dual', options: $float),
        );
    }

    /**
     * Values 4, Scope 1: DATE and TIMESTAMP(0 / 6 / 9) give the stored Wall
     * clock in PHP's default zone, truncated to µs. Oracle's 4712 BC and
     * 1 BC are ISO years -4711 and 0.
     */
    #[Test]
    public function date_and_timestamp_give_the_wall_clock_in_phps_zone(): void
    {
        Temporals::inZone('America/Sao_Paulo', function (): void {
            $row = self::rows($this->connect(), <<<'SQL'
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

            self::assertSame([
                'D' => '+2026-03-01 01:02:03.000000 America/Sao_Paulo',
                'T0' => '+2026-03-01 10:00:00.000000 America/Sao_Paulo',
                'T6' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
                'T9' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
                'BC4712' => '-4711-01-01 12:00:00.000000 America/Sao_Paulo',
                'BC1' => '+0000-12-31 23:59:59.000000 America/Sao_Paulo',
                'LAST' => '+9999-12-31 23:59:59.000000 America/Sao_Paulo',
                'NO_DATE' => null,
                'NO_TIMESTAMP' => null,
            ], array_map(Temporals::shown(...), $row));
        });
    }

    /**
     * Values 4: the Wall clock round-trips through `Date::in()` and
     * `Timestamp::in()`, BC years and 9999-12-31 included.
     */
    #[Test]
    public function the_wall_clock_round_trips_bc_years_included(): void
    {
        Temporals::inZone('America/Sao_Paulo', function (): void {
            $connection = $this->connect();
            foreach (['-4712-01-01 12:00:00', '-0001-12-31 23:59:59', '0001-01-01 00:00:00', '9999-12-31 23:59:59'] as $text) {
                $stored = "to_date('$text', 'SYYYY-MM-DD HH24:MI:SS')";
                $fetched = self::value($connection, "select $stored from dual");
                self::assertInstanceOf(\DateTimeImmutable::class, $fetched);

                foreach ([Date::in($fetched), Timestamp::in($fetched)] as $bind) {
                    self::assertSame(1, self::value($connection, "select case when :v = $stored then 1 else 0 end from dual", ['v' => $bind]), $text);
                    self::assertSame(Temporals::shown($fetched), Temporals::shown(self::value($connection, 'select :v from dual', ['v' => $bind])), $text);
                }
            }
        });
    }

    /**
     * Values 5, Scope 1: a TSTZ keeps its own offset; a region-named one
     * gives the correct Instant in UTC.
     */
    #[Test]
    public function tstz_keeps_its_own_offset(): void
    {
        $row = self::rows($this->connect(), <<<'SQL'
            select timestamp '2026-03-01 10:00:00.5 -03:00' minus3,
                   timestamp '2026-03-01 10:00:00.123456789 +05:45' plus545,
                   timestamp '2026-03-01 10:00:00 +00:00' zero,
                   timestamp '2026-07-01 10:00:00 America/New_York' region,
                   cast(null as timestamp with time zone) nothing
              from dual
            SQL)[0];

        self::assertSame([
            'MINUS3' => '+2026-03-01 10:00:00.500000 -03:00',
            'PLUS545' => '+2026-03-01 10:00:00.123456 +05:45',
            'ZERO' => '+2026-03-01 10:00:00.000000 +00:00',
            'REGION' => '+2026-07-01 14:00:00.000000 +00:00',
            'NOTHING' => null,
        ], array_map(Temporals::shown(...), $row));
    }

    /**
     * Values 5: `TimestampTz::in()` stores the object's offset at that
     * Instant, whatever the Session time zone.
     */
    #[Test]
    public function timestamp_tz_in_stores_the_objects_offset(): void
    {
        Temporals::inZone('America/Sao_Paulo', function (): void {
            $connection = $this->connect();
            $format = "'YYYY-MM-DD HH24:MI:SS.FF6 TZH:TZM'";
            $cases = [
                '+2026-03-01 10:00:00.123456 +05:45' => new \DateTimeImmutable('2026-03-01 10:00:00.123456', new \DateTimeZone('Asia/Kathmandu')),
                '+2026-07-01 10:00:00.000000 -04:00' => new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('America/New_York')),
                '+2026-01-01 10:00:00.000000 -05:00' => new \DateTime('2026-01-01 10:00:00', new \DateTimeZone('America/New_York')),
                '+2026-03-01 10:00:00.000000 +00:00' => new \DateTimeImmutable('2026-03-01 10:00:00', new \DateTimeZone('UTC')),
            ];
            foreach ($cases as $expected => $date) {
                $stored = self::value($connection, "select to_char(:v, $format) from dual", ['v' => TimestampTz::in($date)]);
                self::assertSame(ltrim(substr($expected, 1)), $stored, $expected);
                self::assertSame($expected, Temporals::shown(self::value($connection, 'select :v from dual', ['v' => TimestampTz::in($date)])));
            }
        });
    }

    /**
     * An offset with seconds (local mean time) cannot be a TSTZ offset: it
     * fails before any Round trip.
     */
    #[Test]
    public function a_tstz_offset_with_seconds_fails_before_any_round_trip(): void
    {
        $connection = $this->connect();
        $lmt = new \DateTimeImmutable('1890-01-01 00:00:00', new \DateTimeZone('America/Sao_Paulo'));
        self::assertNotSame(0, $lmt->getOffset() % 60);

        try {
            $connection->execute('select :v from dual', ['v' => TimestampTz::in($lmt)], self::options());
            self::fail('An offset with seconds was bound.');
        } catch (UsageException $e) {
            self::assertStringContainsString("Bind 'v'", $e->getMessage());
            self::assertStringContainsString('whole minutes', $e->getMessage());
        }

        // As the Instant (TSLTZ) or the Wall clock, the same date binds.
        self::assertSame(
            '1890-01-01 03:06:28',
            self::value($connection, "select to_char(sys_extract_utc(cast(:v as timestamp with time zone)), 'YYYY-MM-DD HH24:MI:SS') from dual", ['v' => TimestampLtz::in($lmt)]),
        );
    }

    /**
     * Values 6, Scope 1: a TSLTZ gives the correct Instant in PHP's default
     * zone.
     */
    #[Test]
    public function tsltz_gives_the_instant_in_phps_zone(): void
    {
        foreach (['America/Sao_Paulo' => '+2026-03-01 10:00:00.250000 America/Sao_Paulo', 'Asia/Tokyo' => '+2026-03-01 22:00:00.250000 Asia/Tokyo'] as $zone => $expected) {
            Temporals::inZone($zone, function () use ($expected): void {
                $row = self::rows($this->connect(), <<<'SQL'
                    select cast(timestamp '2026-03-01 13:00:00.25 +00:00' as timestamp with local time zone) instant,
                           cast(null as timestamp with local time zone) nothing
                      from dual
                    SQL)[0];

                self::assertSame(['INSTANT' => $expected, 'NOTHING' => null], array_map(Temporals::shown(...), $row));
            });
        }
    }

    /**
     * Values 7: the Session time zone is PHP's default zone by name, so
     * CURRENT_TIMESTAMP and FROM_TZ follow its daylight saving time.
     */
    #[Test]
    public function the_session_time_zone_is_phps_zone_by_name(): void
    {
        Temporals::inZone('America/New_York', function (): void {
            $connection = $this->connect();
            self::assertSame('America/New_York', self::value($connection, 'select sessiontimezone from dual'));
            self::assertSame(
                ['WINTER' => '-05:00', 'SUMMER' => '-04:00'],
                self::rows($connection, <<<'SQL'
                    select to_char(from_tz(timestamp '2026-01-15 12:00:00', sessiontimezone), 'TZH:TZM') winter,
                           to_char(from_tz(timestamp '2026-07-15 12:00:00', sessiontimezone), 'TZH:TZM') summer
                      from dual
                    SQL)[0],
            );
            // CURRENT_TIMESTAMP carries the zone's region name, so its offset
            // is read on the server.
            self::assertSame(
                (new \DateTimeImmutable('now'))->format('P'),
                self::value($connection, "select to_char(current_timestamp, 'TZH:TZM') from dual"),
            );
        });
        Temporals::inZone('UTC', function (): void {
            self::assertSame('UTC', self::value($this->connect(), 'select sessiontimezone from dual'));
        });
    }

    /**
     * Values 9, Scope 1: INTERVALs, negatives included, give `DateInterval`
     * with the right fields (µs) and `invert`.
     */
    #[Test]
    public function intervals_give_date_intervals(): void
    {
        $row = self::rows($this->connect(), <<<'SQL'
            select interval '3 04:05:06.789' day to second ds,
                   interval '-3 04:05:06.123456789' day(2) to second(9) negative_ds,
                   interval '2-5' year to month ym,
                   interval '-2-5' year to month negative_ym,
                   cast(null as interval day to second) no_ds,
                   cast(null as interval year to month) no_ym
              from dual
            SQL)[0];

        self::assertSame([
            'DS' => '+0Y0M3DT4H5M6S.789000',
            'NEGATIVE_DS' => '-0Y0M3DT4H5M6S.123456',
            'YM' => '+2Y5M0DT0H0M0S.000000',
            'NEGATIVE_YM' => '-2Y5M0DT0H0M0S.000000',
            'NO_DS' => null,
            'NO_YM' => null,
        ], array_map(Temporals::shown(...), $row));
    }

    /**
     * Values 8, Values 17: `temporals: String` gives the exact ISO text,
     * whatever the NLS settings. INTERVALs stay objects.
     */
    #[Test]
    public function temporals_string_gives_nls_independent_iso_text(): void
    {
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
            $string = self::options(values: new ValueMapping(temporals: TemporalMode::String));
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

            self::assertSame($expected, array_map(Temporals::shown(...), self::rows($connection, $sql, options: $string)[0]));

            $connection->execute(
                "alter session set nls_date_format = 'DD/MON/RR HH:MI' nls_timestamp_format = 'DD.MM.YYYY FF3' nls_timestamp_tz_format = 'TZR DD.MM.YYYY' nls_numeric_characters = ',.' nls_date_language = 'GERMAN'",
                [],
                self::options(),
            );
            self::assertSame($expected, array_map(Temporals::shown(...), self::rows($connection, $sql, options: $string)[0]));
            self::assertSame('+2026-03-01 10:00:00.123456 America/Sao_Paulo', Temporals::shown(self::rows($connection, $sql)[0]['T9']));
        });
    }
}
