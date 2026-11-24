//! Temporal values both ways (spec #32 §7, §8): DATE, TIMESTAMP, TIMESTAMP
//! WITH TIME ZONE (TSTZ), TIMESTAMP WITH LOCAL TIME ZONE (TSLTZ) and the
//! INTERVALs, with no NLS setting involved.
//!
//! | Oracle          | PHP                                                  |
//! |-----------------|------------------------------------------------------|
//! | DATE, TIMESTAMP | the stored Wall clock in PHP's default zone          |
//! | TSTZ            | the Instant with its own numeric offset              |
//! | TSLTZ           | the Instant in PHP's default zone                    |
//! | INTERVAL DS, YM | `DateInterval` (µs; `invert` for negatives)          |
//!
//! Fractions are truncated to µs. Oracle has no year 0: its year -1 (1 BC)
//! is PHP's (ISO 8601's) year 0, and 4712 BC is -4711.
//!
//! Under `TemporalMode::String` the four timestamp types become ISO text at
//! full precision instead: `2026-03-01 10:00:00` for a DATE, nine fractional
//! digits for the others, and ` -03:00` after a TSTZ or TSLTZ. INTERVALs stay
//! objects.
//!
//! The crate gives TSTZ and TSLTZ values as UTC fields (with the offset of a
//! TSTZ beside them, C7). A TSTZ stored with a region name comes back with
//! offset 0: the correct Instant, in UTC. A TSLTZ with a named `DBTIMEZONE`
//! fails in the crate (`NotImplemented`, mapped to `unsupported`).

use chrono::{Datelike, Duration, NaiveDate, NaiveDateTime, Timelike};
use ext_php_rs::convert::{IntoZval, IntoZvalDyn};
use ext_php_rs::types::{ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, Function};
use oracledb::{
    DB_TYPE_DATE, DB_TYPE_INTERVAL_DS, DB_TYPE_INTERVAL_YM, DB_TYPE_TIMESTAMP,
    DB_TYPE_TIMESTAMP_LTZ, DB_TYPE_TIMESTAMP_TZ, DbType, OracleIntervalDS, OracleIntervalYM,
    OracleTimestamp, ToDbValue,
};

use crate::error::Error;
use crate::options::TemporalMode;

/// The four timestamp types, each with its own mapping.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Temporal {
    Date,
    Timestamp,
    TimestampTz,
    TimestampLtz,
}

impl Temporal {
    /// The timestamp type a value of `db_type` maps as, if any.
    pub fn of(db_type: &DbType) -> Option<Self> {
        [
            (DB_TYPE_DATE, Self::Date),
            (DB_TYPE_TIMESTAMP, Self::Timestamp),
            (DB_TYPE_TIMESTAMP_TZ, Self::TimestampTz),
            (DB_TYPE_TIMESTAMP_LTZ, Self::TimestampLtz),
        ]
        .into_iter()
        .find(|(candidate, _)| db_type == *candidate)
        .map(|(_, temporal)| temporal)
    }

    /// The timestamp type a typed bind declares (`Type`'s backing value).
    pub fn of_bind(type_name: &str) -> Option<Self> {
        match type_name {
            "date" => Some(Self::Date),
            "timestamp" => Some(Self::Timestamp),
            "timestampTz" => Some(Self::TimestampTz),
            "timestampLtz" => Some(Self::TimestampLtz),
            _ => None,
        }
    }

    /// The SQL name, for messages.
    fn sql_name(self) -> &'static str {
        match self {
            Self::Date => "DATE",
            Self::Timestamp => "TIMESTAMP",
            Self::TimestampTz => "TIMESTAMP WITH TIME ZONE",
            Self::TimestampLtz => "TIMESTAMP WITH LOCAL TIME ZONE",
        }
    }

    /// A value as `TemporalMode::String` writes it, for messages.
    fn example(self) -> &'static str {
        match self {
            Self::Date => "2026-03-01 10:00:00",
            Self::Timestamp => "2026-03-01 10:00:00.123456789",
            Self::TimestampTz | Self::TimestampLtz => "2026-03-01 10:00:00.123456789 -03:00",
        }
    }

    fn db_type(self) -> &'static DbType {
        match self {
            Self::Date => DB_TYPE_DATE,
            Self::Timestamp => DB_TYPE_TIMESTAMP,
            Self::TimestampTz => DB_TYPE_TIMESTAMP_TZ,
            Self::TimestampLtz => DB_TYPE_TIMESTAMP_LTZ,
        }
    }
}

/// The largest TSTZ offset the crate sends: ±15:59.
const MAX_OFFSET_SECONDS: i32 = 15 * 3600 + 59 * 60;

// ---------------------------------------------------------------------------
// From Oracle to PHP
// ---------------------------------------------------------------------------

/// A timestamp value as PHP sees it, mapped as `temporal` under `mode`.
pub fn timestamp_value(
    value: OracleTimestamp,
    temporal: Temporal,
    mode: TemporalMode,
) -> Result<Zval, Error> {
    let fields = Fields::of(value, temporal)?;
    match (temporal, mode) {
        (Temporal::Date | Temporal::Timestamp, TemporalMode::Object) => {
            parse("!X-m-d H:i:s.u", &fields.text(Precision::Micro))
        }
        (Temporal::TimestampTz, TemporalMode::Object) => parse(
            "!X-m-d H:i:s.u P",
            &format!("{} {}", fields.text(Precision::Micro), fields.offset()),
        ),
        (Temporal::TimestampLtz, TemporalMode::Object) => in_default_zone(&fields),
        (Temporal::Date, TemporalMode::String) => string(&fields.text(Precision::Seconds)),
        (Temporal::Timestamp, TemporalMode::String) => string(&fields.text(Precision::Nano)),
        (Temporal::TimestampTz, TemporalMode::String) => string(&format!(
            "{} {}",
            fields.text(Precision::Nano),
            fields.offset()
        )),
        (Temporal::TimestampLtz, TemporalMode::String) => {
            // The zone's offset at that Instant comes from PHP's zone rules.
            let object = in_default_zone(&fields)?;
            let text = call_method(&object, "format", vec![&"x-m-d H:i:s|P"])?;
            let text = text.str().unwrap_or_default();
            let (clock, offset) = text.split_once('|').unwrap_or((text, ""));
            string(&format!("{clock}.{:09} {offset}", fields.nanoseconds))
        }
    }
}

/// An INTERVAL DAY TO SECOND as a `DateInterval`.
pub fn interval_ds(value: OracleIntervalDS) -> Result<Zval, Error> {
    let negative = value.days() < 0
        || value.hours() < 0
        || value.minutes() < 0
        || value.seconds() < 0
        || value.nanoseconds() < 0;
    date_interval(
        &format!(
            "P{}DT{}H{}M{}S",
            value.days().unsigned_abs(),
            value.hours().unsigned_abs(),
            value.minutes().unsigned_abs(),
            value.seconds().unsigned_abs(),
        ),
        // Truncated to µs, as DateInterval holds them.
        f64::from(value.nanoseconds().unsigned_abs() / 1000) / 1e6,
        negative,
    )
}

/// An INTERVAL YEAR TO MONTH as a `DateInterval`.
pub fn interval_ym(value: OracleIntervalYM) -> Result<Zval, Error> {
    date_interval(
        &format!(
            "P{}Y{}M",
            value.years().unsigned_abs(),
            value.months().unsigned_abs()
        ),
        0.0,
        value.years() < 0 || value.months() < 0,
    )
}

/// How many fractional digits the ISO text has.
#[derive(Clone, Copy)]
enum Precision {
    Seconds,
    Micro,
    Nano,
}

/// The date and time to show, in PHP's (ISO 8601) year numbering: the Wall
/// clock for DATE and TIMESTAMP, the local time at its offset for TSTZ, and
/// UTC for TSLTZ.
struct Fields {
    clock: NaiveDateTime,
    nanoseconds: u32,
    /// TSTZ only: its offset, in minutes.
    offset_minutes: i32,
}

impl Fields {
    fn of(value: OracleTimestamp, temporal: Temporal) -> Result<Self, Error> {
        let year = i32::from(value.year());
        // Oracle's year -1 is 1 BC, ISO's year 0.
        let year = if year < 0 { year + 1 } else { year };
        let nanoseconds = if temporal == Temporal::Date {
            0
        } else {
            value.nanoseconds()
        };
        let clock = NaiveDate::from_ymd_opt(year, value.month().into(), value.day().into())
            .and_then(|date| {
                date.and_hms_opt(
                    value.hour().into(),
                    value.minute().into(),
                    value.second().into(),
                )
            })
            .ok_or_else(|| {
                Error::unsupported(format!("The server sent an invalid date: {value:?}."))
            })?;
        let offset_minutes = if temporal == Temporal::TimestampTz {
            i32::from(value.tz_hour_offset()) * 60 + i32::from(value.tz_minute_offset())
        } else {
            0
        };
        Ok(Self {
            clock: clock + Duration::minutes(offset_minutes.into()),
            nanoseconds,
            offset_minutes,
        })
    }

    fn text(&self, precision: Precision) -> String {
        let c = &self.clock;
        let clock = format!(
            "{}-{:02}-{:02} {:02}:{:02}:{:02}",
            year_text(c.year()),
            c.month(),
            c.day(),
            c.hour(),
            c.minute(),
            c.second()
        );
        match precision {
            Precision::Seconds => clock,
            Precision::Micro => format!("{clock}.{:06}", self.nanoseconds / 1000),
            Precision::Nano => format!("{clock}.{:09}", self.nanoseconds),
        }
    }

    /// `+05:45`, `-03:00`, `+00:00`.
    fn offset(&self) -> String {
        let sign = if self.offset_minutes < 0 { '-' } else { '+' };
        let minutes = self.offset_minutes.unsigned_abs();
        format!("{sign}{:02}:{:02}", minutes / 60, minutes % 60)
    }
}

/// At least four digits, `-` before a year BC (ISO 8601's numbering).
fn year_text(year: i32) -> String {
    if year < 0 {
        format!("-{:04}", year.unsigned_abs())
    } else {
        format!("{year:04}")
    }
}

/// The UTC Instant of `fields` as a `DateTimeImmutable` in PHP's default
/// zone, as it is now.
fn in_default_zone(fields: &Fields) -> Result<Zval, Error> {
    let utc = parse(
        "!X-m-d H:i:s.u P",
        &format!("{} +00:00", fields.text(Precision::Micro)),
    )?;
    let name = call("date_default_timezone_get", vec![])?;
    let zone = call("timezone_open", vec![&name])?;
    call_method(&utc, "setTimezone", vec![&zone])
}

/// `date_create_immutable_from_format($format, $text)`, in PHP's default
/// zone unless the text has an offset.
fn parse(format: &str, text: &str) -> Result<Zval, Error> {
    let value = call("date_create_immutable_from_format", vec![&format, &text])?;
    if value.object().is_none() {
        return Err(Error::unsupported(format!(
            "The temporal value {text} cannot be a DateTimeImmutable."
        )));
    }
    Ok(value)
}

/// `new DateInterval($spec)`, with µs and `invert` set.
fn date_interval(spec: &str, fraction: f64, invert: bool) -> Result<Zval, Error> {
    let class = ClassEntry::try_find("DateInterval")
        .ok_or_else(|| Error::unsupported("The DateInterval class is missing."))?;
    let mut interval = ZendObject::new(class);
    interval
        .try_call_method("__construct", vec![&spec])
        .map_err(php_error)?;
    if ExecutorGlobals::has_exception() {
        return Err(Error::php_exception());
    }
    if fraction != 0.0 {
        interval.set_property("f", fraction).map_err(php_error)?;
    }
    if invert {
        interval.set_property("invert", 1_i64).map_err(php_error)?;
    }
    interval.into_zval(false).map_err(php_error)
}

fn string(text: &str) -> Result<Zval, Error> {
    let mut zval = Zval::new();
    zval.set_string(text, false).map_err(php_error)?;
    Ok(zval)
}

/// Calls a PHP function; an exception it throws goes to the caller as is.
fn call(name: &str, args: Vec<&dyn IntoZvalDyn>) -> Result<Zval, Error> {
    let function = Function::try_from_function(name)
        .ok_or_else(|| Error::unsupported(format!("The PHP function {name}() is missing.")))?;
    let value = function.try_call(args).map_err(php_error)?;
    if ExecutorGlobals::has_exception() {
        return Err(Error::php_exception());
    }
    Ok(value)
}

/// Calls a method of the object in `object`.
fn call_method(object: &Zval, name: &str, args: Vec<&dyn IntoZvalDyn>) -> Result<Zval, Error> {
    let value = object
        .object()
        .ok_or_else(|| Error::unsupported(format!("{name}() needs an object.")))?
        .try_call_method(name, args)
        .map_err(php_error)?;
    if ExecutorGlobals::has_exception() {
        return Err(Error::php_exception());
    }
    Ok(value)
}

fn php_error(e: ext_php_rs::error::Error) -> Error {
    Error::unsupported(e.to_string())
}

// ---------------------------------------------------------------------------
// From PHP to Oracle
// ---------------------------------------------------------------------------

/// A `Date`, `Timestamp`, `TimestampTz` or `TimestampLtz` bind's value: NULL,
/// or a `DateTimeInterface` (see [`timestamp_of`]).
///
/// DATE and TIMESTAMP both go as TIMESTAMP, the only type the crate sends a
/// Wall clock as; TSLTZ goes as a TSTZ at UTC, the only way the crate sends
/// an Instant; the server converts. NULL (and an OUT bind) goes as the
/// declared type itself.
pub fn timestamp_bind(
    name: &str,
    value: &Zval,
    temporal: Temporal,
) -> Result<Box<dyn ToDbValue>, Error> {
    Ok(
        match timestamp_of(&format!("Bind '{name}'"), value, temporal, false)? {
            Some(timestamp) => Box::new(timestamp),
            None => Box::new(temporal.db_type()),
        },
    )
}

/// A timestamp value from PHP, stored as `temporal`; `None` for NULL.
/// `what` starts every message ("Bind 'x'").
///
/// - DATE and TIMESTAMP store the object's Wall clock, its zone ignored
///   (DATE drops the fraction).
/// - TSTZ stores the Instant with the object's offset at that Instant. An
///   offset in seconds (a zone's local mean time, before about 1900) or
///   beyond ±15:59 cannot be stored and fails before any Round trip.
/// - TSLTZ stores the Instant, as UTC fields.
///
/// With `text`, the ISO text `TemporalMode::String` gives is read too, so
/// such a value binds back unchanged.
pub fn timestamp_of(
    what: &str,
    value: &Zval,
    temporal: Temporal,
    text: bool,
) -> Result<Option<OracleTimestamp>, Error> {
    if value.is_null() {
        return Ok(None);
    }
    let expected = if text {
        "a DateTimeInterface, ISO text or null"
    } else {
        "a DateTimeInterface or null"
    };
    let (clock, nanoseconds, offset) = if text && let Some(iso) = value.str() {
        read_iso(iso, temporal).ok_or_else(|| {
            Error::usage(format!(
                "{what}: the text is not a {} as TemporalMode::String writes it, such as '{}'.",
                temporal.sql_name(),
                temporal.example()
            ))
        })?
    } else {
        let object = value
            .object()
            .filter(|object| {
                ClassEntry::try_find("DateTimeInterface").is_some_and(|ce| object.instance_of(ce))
            })
            .ok_or_else(|| Error::usage(format!("{what}: the value must be {expected}.")))?;
        let text = object
            .try_call_method("format", vec![&"X-m-d H:i:s.u Z"])
            .map_err(php_error)?;
        if ExecutorGlobals::has_exception() {
            return Err(Error::php_exception());
        }
        let (clock, microseconds, offset) = text
            .str()
            .and_then(read_format)
            .ok_or_else(|| Error::usage(format!("{what}: the date cannot be read.")))?;
        (clock, microseconds * 1000, offset)
    };
    let timestamp = match temporal {
        Temporal::Date => oracle_timestamp(what, clock, 0, false)?,
        Temporal::Timestamp => oracle_timestamp(what, clock, nanoseconds, false)?,
        Temporal::TimestampTz | Temporal::TimestampLtz => {
            let utc = clock - Duration::seconds(offset.into());
            let timestamp = oracle_timestamp(what, utc, nanoseconds, true)?;
            let offset = if temporal == Temporal::TimestampTz {
                offset
            } else {
                0
            };
            if offset % 60 != 0 || offset.abs() > MAX_OFFSET_SECONDS {
                return Err(Error::usage(format!(
                    "{what}: the date's UTC offset of {offset} seconds cannot be stored in a TIMESTAMP WITH TIME ZONE, which holds whole minutes up to ±15:59; convert the date to another zone (such as UTC) first."
                )));
            }
            #[allow(clippy::cast_possible_truncation)] // within ±15 h, ±59 min
            timestamp
                .with_tz_offset((offset / 3600) as i8, (offset % 3600 / 60) as i8)
                .map_err(|e| Error::usage(format!("{what}: {e}.")))?
        }
    };
    Ok(Some(timestamp))
}

/// The ISO text of `TemporalMode::String` → the clock, its nanoseconds and
/// its UTC offset in seconds: `2026-03-01 10:00:00` (DATE), nine fractional
/// digits for the others, and ` -03:00` after a TSTZ or TSLTZ. A year BC
/// starts with `-`.
fn read_iso(text: &str, temporal: Temporal) -> Option<(NaiveDateTime, u32, i32)> {
    let (negative, text) = match text.strip_prefix('-') {
        Some(rest) => (true, rest),
        None => (false, text),
    };
    let mut parts = text.split(' ');
    let (date, time) = (parts.next()?, parts.next()?);
    let offset = parts.next();
    if parts.next().is_some() {
        return None;
    }
    let mut date = date.split('-');
    let year: i32 = date.next()?.parse().ok()?;
    let year = if negative { -year } else { year };
    let (month, day) = (date.next()?.parse().ok()?, date.next()?.parse().ok()?);
    if date.next().is_some() {
        return None;
    }
    let (time, nanoseconds) = match time.split_once('.') {
        Some((time, fraction))
            if temporal != Temporal::Date
                && (1..=9).contains(&fraction.len())
                && fraction.bytes().all(|b| b.is_ascii_digit()) =>
        {
            (time, format!("{fraction:0<9}").parse().ok()?)
        }
        Some(_) => return None,
        None => (time, 0),
    };
    let mut time = time.split(':');
    let clock = NaiveDate::from_ymd_opt(year, month, day)?.and_hms_opt(
        time.next()?.parse().ok()?,
        time.next()?.parse().ok()?,
        time.next()?.parse().ok()?,
    )?;
    if time.next().is_some() {
        return None;
    }
    let offset = match (offset, temporal) {
        (None, Temporal::Date | Temporal::Timestamp) => 0,
        (Some(offset), Temporal::TimestampTz | Temporal::TimestampLtz) => {
            let (sign, offset) = offset.split_at_checked(1)?;
            let (hours, minutes) = offset.split_once(':')?;
            let seconds = hours.parse::<i32>().ok()? * 3600 + minutes.parse::<i32>().ok()? * 60;
            match sign {
                "+" => seconds,
                "-" => -seconds,
                _ => return None,
            }
        }
        _ => return None,
    };
    Some((clock, nanoseconds, offset))
}

/// `X-m-d H:i:s.u Z` → the clock, its µs and its UTC offset in seconds.
fn read_format(text: &str) -> Option<(NaiveDateTime, u32, i32)> {
    let mut parts = text.split(' ');
    let (date, time, offset) = (parts.next()?, parts.next()?, parts.next()?);
    // The year has a sign: '+2026', '-4711'.
    let (sign, date) = date.split_at(1);
    let mut date = date.split('-');
    let year: i32 = date.next()?.parse().ok()?;
    let year = if sign == "-" { -year } else { year };
    let (month, day) = (date.next()?.parse().ok()?, date.next()?.parse().ok()?);
    let (time, microseconds) = time.split_once('.')?;
    let mut time = time.split(':');
    let clock = NaiveDate::from_ymd_opt(year, month, day)?.and_hms_opt(
        time.next()?.parse().ok()?,
        time.next()?.parse().ok()?,
        time.next()?.parse().ok()?,
    )?;
    Some((clock, microseconds.parse().ok()?, offset.parse().ok()?))
}

/// An Oracle timestamp of `clock` (ISO year numbering), within Oracle's
/// years 4712 BC to 9999.
fn oracle_timestamp(
    what: &str,
    clock: NaiveDateTime,
    nanoseconds: u32,
    utc: bool,
) -> Result<OracleTimestamp, Error> {
    let year = clock.year();
    if !(-4711..=9999).contains(&year) {
        return Err(Error::usage(format!(
            "{what}: the year {year} is outside Oracle's range, -4711 (4712 BC) to 9999."
        )));
    }
    // ISO's year 0 is Oracle's -1 (1 BC).
    #[allow(clippy::cast_possible_truncation)] // checked above
    let year = (if year <= 0 { year - 1 } else { year }) as i16;
    #[allow(clippy::cast_possible_truncation)] // calendar fields
    let fields = (
        year,
        clock.month() as u8,
        clock.day() as u8,
        clock.hour() as u8,
        clock.minute() as u8,
        clock.second() as u8,
        nanoseconds,
    );
    let (y, mo, d, h, mi, s, ns) = fields;
    Ok(if utc {
        OracleTimestamp::new_utc_timestamp(y, mo, d, h, mi, s, ns)
    } else {
        OracleTimestamp::new_timestamp(y, mo, d, h, mi, s, ns)
    })
}

/// An `IntervalDs` bind's value: NULL, or a `DateInterval` (see
/// [`interval_ds_of`]).
pub fn interval_ds_bind(name: &str, value: &Zval) -> Result<Box<dyn ToDbValue>, Error> {
    Ok(match interval_ds_of(&format!("Bind '{name}'"), value)? {
        Some(interval) => Box::new(interval),
        None => Box::new(DB_TYPE_INTERVAL_DS),
    })
}

/// An INTERVAL DAY TO SECOND from PHP: NULL, or a `DateInterval` with no
/// years or months. Its fields are added up, so `PT36H` is 1 day 12 hours.
pub fn interval_ds_of(what: &str, value: &Zval) -> Result<Option<OracleIntervalDS>, Error> {
    let Some(interval) = interval_fields(what, value)? else {
        return Ok(None);
    };
    if interval.years != 0 || interval.months != 0 {
        return Err(Error::usage(format!(
            "{what}: an IntervalDs holds days and smaller units, but the DateInterval has years or months; bind those with IntervalYm::in()."
        )));
    }
    let seconds =
        ((interval.days * 24 + interval.hours) * 60 + interval.minutes) * 60 + interval.seconds;
    let total = i128::from(seconds) * 1_000_000 + i128::from(interval.microseconds);
    let total = if interval.invert { -total } else { total };
    let day = 86_400 * 1_000_000_i128;
    let days = i32::try_from(total / day).map_err(|_| {
        Error::usage(format!(
            "{what}: the interval has too many days for an INTERVAL DAY TO SECOND."
        ))
    })?;
    let rest = total % day;
    #[allow(clippy::cast_possible_truncation)] // each below its unit's limit
    let value = OracleIntervalDS::new(
        days,
        (rest / 3_600_000_000) as i8,
        (rest % 3_600_000_000 / 60_000_000) as i8,
        (rest % 60_000_000 / 1_000_000) as i8,
        (rest % 1_000_000 * 1000) as i32,
    );
    Ok(Some(value))
}

/// An `IntervalYm` bind's value: NULL, or a `DateInterval` (see
/// [`interval_ym_of`]).
pub fn interval_ym_bind(name: &str, value: &Zval) -> Result<Box<dyn ToDbValue>, Error> {
    Ok(match interval_ym_of(&format!("Bind '{name}'"), value)? {
        Some(interval) => Box::new(interval),
        None => Box::new(DB_TYPE_INTERVAL_YM),
    })
}

/// An INTERVAL YEAR TO MONTH from PHP: NULL, or a `DateInterval` of years
/// and months only.
pub fn interval_ym_of(what: &str, value: &Zval) -> Result<Option<OracleIntervalYM>, Error> {
    let Some(interval) = interval_fields(what, value)? else {
        return Ok(None);
    };
    if interval.days != 0
        || interval.hours != 0
        || interval.minutes != 0
        || interval.seconds != 0
        || interval.microseconds != 0
    {
        return Err(Error::usage(format!(
            "{what}: an IntervalYm holds years and months only, but the DateInterval has days or smaller units; bind those with IntervalDs::in()."
        )));
    }
    let months = interval.years * 12 + interval.months;
    let months = if interval.invert { -months } else { months };
    let years = i32::try_from(months / 12).map_err(|_| {
        Error::usage(format!(
            "{what}: the interval has too many years for an INTERVAL YEAR TO MONTH."
        ))
    })?;
    #[allow(clippy::cast_possible_truncation)] // below 12
    let value = OracleIntervalYM::new(years, (months % 12) as i8);
    Ok(Some(value))
}

struct Interval {
    years: i64,
    months: i64,
    days: i64,
    hours: i64,
    minutes: i64,
    seconds: i64,
    microseconds: i64,
    invert: bool,
}

/// A `DateInterval`'s fields, or `None` for NULL.
fn interval_fields(what: &str, value: &Zval) -> Result<Option<Interval>, Error> {
    if value.is_null() {
        return Ok(None);
    }
    let object = value
        .object()
        .filter(|object| {
            ClassEntry::try_find("DateInterval").is_some_and(|ce| object.instance_of(ce))
        })
        .ok_or_else(|| {
            Error::usage(format!("{what}: the value must be a DateInterval or null."))
        })?;
    let int = |field: &str| -> Result<i64, Error> {
        object
            .get_property::<i64>(field)
            .map_err(|_| Error::usage(format!("{what}: the DateInterval has no {field}.")))
    };
    let fraction = object
        .get_property::<f64>("f")
        .map_err(|_| Error::usage(format!("{what}: the DateInterval has no f.")))?;
    #[allow(clippy::cast_possible_truncation)] // a fraction of a second
    let microseconds = (fraction * 1e6).round() as i64;
    Ok(Some(Interval {
        years: int("y")?,
        months: int("m")?,
        days: int("d")?,
        hours: int("h")?,
        minutes: int("i")?,
        seconds: int("s")?,
        microseconds,
        invert: int("invert")? != 0,
    }))
}
