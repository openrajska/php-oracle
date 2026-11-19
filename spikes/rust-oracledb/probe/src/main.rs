//! Throwaway probe for the scalar-binds ticket (#7) of the rust-oracledb spike.
//!
//! Runs Calls against the `fx_scalar` fixture package (fixtures/sql/01_scalar.sql)
//! and a few Anonymous blocks, and prints one line per case:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Run inside ddev: `cd spikes/rust-oracledb/probe && ddev cargo run -q`.

use oracledb::{
    Connection, DB_TYPE_DATE, DB_TYPE_NUMBER, DB_TYPE_TIMESTAMP, DB_TYPE_TIMESTAMP_LTZ,
    DB_TYPE_TIMESTAMP_TZ, DB_TYPE_VARCHAR, OracleNumber, OracleTimestamp, ToDbValue,
};
use std::fmt::Debug;
use std::str::FromStr;

type R<T> = Result<T, Box<dyn std::error::Error>>;

struct Tally {
    pass: u32,
    fail: u32,
    err: u32,
    obs: u32,
}

impl Tally {
    /// A case with a fixed expectation, compared as the Debug text of the value read.
    fn check<T: Debug>(&mut self, id: &str, what: &str, expected: &str, got: R<T>) {
        match got {
            Ok(v) => {
                let s = format!("{v:?}");
                if s == expected {
                    self.pass += 1;
                    println!("PASS {id:<5} {what} => {}", short(&s));
                } else {
                    self.fail += 1;
                    println!("FAIL {id:<5} {what} => {} (expected {})", short(&s), short(expected));
                }
            }
            Err(e) => {
                self.err += 1;
                println!("ERR  {id:<5} {what} => {}", one_line(&e.to_string()));
            }
        }
    }

    /// A case that records behaviour (value or error) without a fixed expectation.
    fn observe<T: Debug>(&mut self, id: &str, what: &str, got: R<T>) {
        self.obs += 1;
        match got {
            Ok(v) => println!("OBS  {id:<5} {what} => Ok({})", short(&format!("{v:?}"))),
            Err(e) => println!("OBS  {id:<5} {what} => Err({})", one_line(&e.to_string())),
        }
    }
}

fn one_line(s: &str) -> String {
    s.lines().next().unwrap_or("").to_string()
}

/// Long strings are summarised as length + head + tail so the output stays readable.
fn short(s: &str) -> String {
    let n = s.chars().count();
    if n <= 120 {
        return s.to_string();
    }
    let head: String = s.chars().take(20).collect();
    let tail: String = s.chars().skip(n - 10).collect();
    format!("<{n} chars> {head}…{tail}")
}

/// A string read back, summarised as (chars, bytes, equal-to-expected).
fn str_shape(s: &Option<String>, expected: &str) -> (usize, usize, bool) {
    match s {
        Some(s) => (s.chars().count(), s.len(), s == expected),
        None => (0, 0, expected.is_empty()),
    }
}

fn num(s: &str) -> OracleNumber {
    OracleNumber::from_str(s).expect("valid number literal")
}

fn ts(y: i16, mo: u8, d: u8, h: u8, mi: u8, s: u8, ns: u32) -> OracleTimestamp {
    OracleTimestamp::new_timestamp(y, mo, d, h, mi, s, ns)
}

/// `fx_scalar.echo_out`: OUT binds given as DbTypes, read back by name.
fn echo(
    c: &Connection,
    n: &dyn ToDbValue,
    s: &dyn ToDbValue,
    d: &dyn ToDbValue,
) -> R<(Option<OracleNumber>, Option<String>, Option<OracleTimestamp>)> {
    let mut r = c.execute_named(
        "begin fx_scalar.echo_out(:p_num, :p_str, :p_date, :o_num, :o_str, :o_date); end;",
        &[
            ("p_num", n),
            ("p_str", s),
            ("p_date", d),
            ("o_num", &DB_TYPE_NUMBER),
            ("o_str", &DB_TYPE_VARCHAR),
            ("o_date", &DB_TYPE_DATE),
        ],
    )?;
    let out = r.out_bind_data();
    Ok((out.get("o_num")?, out.get("o_str")?, out.get("o_date")?))
}

/// An Anonymous block with a single OUT bind `:o`, read as T.
fn out1<T>(c: &Connection, plsql: &str, ty: &dyn ToDbValue) -> R<T>
where
    T: for<'a> oracledb::FromDbValue<'a>,
{
    let mut r = c.execute_named(plsql, &[("o", ty)])?;
    Ok(r.out_bind_data().get("o")?)
}

/// An Anonymous block with a single IN OUT bind `:v`, read as T.
fn inout1<T>(c: &Connection, plsql: &str, v: &dyn ToDbValue) -> R<T>
where
    T: for<'a> oracledb::FromDbValue<'a>,
{
    let mut r = c.execute_named(plsql, &[("v", v)])?;
    Ok(r.out_bind_data().get("v")?)
}

fn main() -> R<()> {
    let user = std::env::var("ORACLE_USER")?;
    let pass = std::env::var("ORACLE_PASSWORD")?;
    let dsn = std::env::var("ORACLE_CONNECT_STRING")?;
    let config = oracledb::Config::default()
        .set_credentials(&user, &pass)
        .set_connect_string(&dsn)?;
    let c = oracledb::connect(config)?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };
    let d0 = ts(2026, 11, 19, 13, 45, 30, 0);

    println!("== NUMBER: OUT (fx_scalar.echo_out) ==");
    t.check("N1", "42 as i64", "42", (|| {
        let (n, _, _) = echo(&c, &42i64, &"x", &d0)?;
        Ok(n.unwrap().to_string().parse::<i64>()?)
    })());
    t.check("N2", "i64::MAX via OracleNumber", "9223372036854775807", (|| {
        Ok(echo(&c, &i64::MAX, &"x", &d0)?.0.unwrap())
    })());
    let n38 = "12345678901234567890123456789012345678";
    t.check("N3", "38-digit integer via OracleNumber", n38, (|| {
        Ok(echo(&c, &num(n38), &"x", &d0)?.0.unwrap())
    })());
    t.observe("N3b", "38-digit integer read as i64 (must not wrap)", (|| {
        let mut r = c.execute_named(
            "begin fx_scalar.echo_out(:p_num, 'x', sysdate, :o_num, :o_str, :o_date); end;",
            &[("p_num", &num(n38)), ("o_num", &DB_TYPE_NUMBER), ("o_str", &DB_TYPE_VARCHAR), ("o_date", &DB_TYPE_DATE)],
        )?;
        Ok(r.out_bind_data().get::<i64>("o_num")?)
    })());
    t.observe("N3c", "38-digit integer read as i128", (|| {
        let mut r = c.execute_named(
            "begin fx_scalar.echo_out(:p_num, 'x', sysdate, :o_num, :o_str, :o_date); end;",
            &[("p_num", &num(n38)), ("o_num", &DB_TYPE_NUMBER), ("o_str", &DB_TYPE_VARCHAR), ("o_date", &DB_TYPE_DATE)],
        )?;
        Ok(r.out_bind_data().get::<i128>("o_num")?)
    })());
    t.observe("N3d", "38-digit integer read as f64 (lossy by design)", (|| {
        let mut r = c.execute_named(
            "begin fx_scalar.echo_out(:p_num, 'x', sysdate, :o_num, :o_str, :o_date); end;",
            &[("p_num", &num(n38)), ("o_num", &DB_TYPE_NUMBER), ("o_str", &DB_TYPE_VARCHAR), ("o_date", &DB_TYPE_DATE)],
        )?;
        Ok(r.out_bind_data().get::<f64>("o_num")?)
    })());
    t.check("N4", "-123.456 decimal", "-123.456", (|| {
        Ok(echo(&c, &num("-123.456"), &"x", &d0)?.0.unwrap())
    })());
    t.check("N5", "NULL NUMBER -> None", "None", (|| {
        Ok(echo(&c, &None::<OracleNumber>, &"x", &d0)?.0)
    })());
    t.check("N6", "0 -> 0", "0", (|| Ok(echo(&c, &0i64, &"x", &d0)?.0.unwrap()))());
    t.observe("N7", "f64 0.1 IN (binds as BINARY_DOUBLE) -> NUMBER OUT", (|| {
        Ok(echo(&c, &0.1f64, &"x", &d0)?.0.unwrap())
    })());
    t.check("N7b", "workaround: PHP float as shortest-repr string -> OracleNumber", "0.1", (|| {
        Ok(echo(&c, &num(&format!("{}", 0.1f64)), &"x", &d0)?.0.unwrap())
    })());

    println!("== NUMBER: server-made values, OUT in an Anonymous block ==");
    t.check("N8", "1/3 keeps 40 significant digits", "0.3333333333333333333333333333333333333333", out1::<OracleNumber>(&c, "begin :o := 1/3; end;", &DB_TYPE_NUMBER));
    t.check("N9", "largest NUMBER 9.99..e125", &format!("{}{}", "9".repeat(40), "0".repeat(86)), out1::<OracleNumber>(&c, "begin :o := 9.999999999999999999999999999999999999999e125; end;", &DB_TYPE_NUMBER));
    t.check("N10", "smallest positive NUMBER 1e-130", &format!("0.{}1", "0".repeat(129)), out1::<OracleNumber>(&c, "begin :o := 1e-130; end;", &DB_TYPE_NUMBER));
    t.check("N11", "-1e-130", &format!("-0.{}1", "0".repeat(129)), out1::<OracleNumber>(&c, "begin :o := -1e-130; end;", &DB_TYPE_NUMBER));
    t.check("N12", "0.1 read as f64", "0.1", out1::<f64>(&c, "begin :o := 0.1; end;", &DB_TYPE_NUMBER));
    t.check("N13", "1/3 read as String", "\"0.3333333333333333333333333333333333333333\"", out1::<String>(&c, "begin :o := 1/3; end;", &DB_TYPE_NUMBER));

    println!("== NUMBER: IN OUT (fx_scalar.bump) ==");
    let bump_num = |v: &dyn ToDbValue| -> R<Option<OracleNumber>> {
        let mut r = c.execute_named(
            "declare s varchar2(10) := 'a'; d date := sysdate; begin fx_scalar.bump(:v, s, d); end;",
            &[("v", v)],
        )?;
        Ok(r.out_bind_data().get("v")?)
    };
    t.check("N14", "41 -> 42", "Some(42)", bump_num(&41i64));
    t.check("N15", "38-digit + 1", "Some(12345678901234567890123456789012345679)", bump_num(&num(n38)));
    t.check("N16", "i64::MAX + 1 (beyond i64)", "Some(9223372036854775808)", bump_num(&i64::MAX));
    t.check("N17", "NULL -> NULL", "None", bump_num(&None::<OracleNumber>));
    t.check("N18", "1.5 -> 2.5", "Some(2.5)", bump_num(&num("1.5")));

    println!("== VARCHAR2: OUT (fx_scalar.echo_out) ==");
    let mb = "Ação Café Straße 日本 😀";
    t.check("S1", "ASCII", "Some(\"hello\")", (|| Ok(echo(&c, &1i64, &"hello", &d0)?.1))());
    t.check("S2", "multibyte incl. 4-byte emoji", &format!("Some({mb:?})"), (|| Ok(echo(&c, &1i64, &mb, &d0)?.1))());
    t.check("S3", "'' is NULL in Oracle -> None", "None", (|| Ok(echo(&c, &1i64, &"", &d0)?.1))());
    t.check("S4", "NULL -> None", "None", (|| Ok(echo(&c, &1i64, &None::<String>, &d0)?.1))());

    println!("== VARCHAR2: OUT buffer sizing (fx_scalar.repeat_out) ==");
    let repeat = |ch: &str, len: usize, out: &dyn ToDbValue| -> R<(usize, usize, bool)> {
        let mut r = c.execute_named(
            "begin fx_scalar.repeat_out(:p_char, :p_length, :o_str); end;",
            &[("p_char", &ch), ("p_length", &(len as i64)), ("o_str", out)],
        )?;
        let s: Option<String> = r.out_bind_data().get("o_str")?;
        Ok(str_shape(&s, &ch.repeat(len)))
    };
    t.check("S5", "4000 x 'a' into bare DB_TYPE_VARCHAR", "(4000, 4000, true)", repeat("a", 4000, &DB_TYPE_VARCHAR));
    t.observe("S6", "4001 x 'a' into bare DB_TYPE_VARCHAR", repeat("a", 4001, &DB_TYPE_VARCHAR));
    t.observe("S7", "32767 x 'a' into bare DB_TYPE_VARCHAR", repeat("a", 32767, &DB_TYPE_VARCHAR));
    t.observe("S8", "4000 x 'ç' (8000 bytes) into bare DB_TYPE_VARCHAR", repeat("ç", 4000, &DB_TYPE_VARCHAR));
    t.check("S8b", "16000 x 'a' (= 4000 x 4 bytes) into bare DB_TYPE_VARCHAR", "(16000, 16000, true)", repeat("a", 16000, &DB_TYPE_VARCHAR));
    t.observe("S8c", "16001 x 'a' into bare DB_TYPE_VARCHAR", repeat("a", 16001, &DB_TYPE_VARCHAR));
    let pad = " ".repeat(32767);
    t.check("S9", "32767 x 'a' with a 32767-byte padding value (doc workaround)", "(32767, 32767, true)", repeat("a", 32767, &pad));
    t.check("S10", "16383 x 'ç' (32766 bytes) with padding value", "(16383, 32766, true)", repeat("ç", 16383, &pad));
    t.observe("S11", "32768 x 'a' (over the PL/SQL limit) with padding value", repeat("a", 32768, &pad));

    println!("== VARCHAR2: IN OUT (fx_scalar.bump and Anonymous blocks) ==");
    let bump_str = |v: &dyn ToDbValue| -> R<Option<String>> {
        let mut r = c.execute_named(
            "declare n number := 1; d date := sysdate; begin fx_scalar.bump(n, :v, d); end;",
            &[("v", v)],
        )?;
        Ok(r.out_bind_data().get("v")?)
    };
    t.check("S12", "'abc' -> 'abc!'", "Some(\"abc!\")", bump_str(&"abc"));
    t.check("S13", "multibyte + '!'", &format!("Some({:?})", format!("{mb}!")), bump_str(&mb));
    t.observe("S14", "NULL IN OUT (0-byte buffer?) -> expected '!'", bump_str(&None::<String>));
    t.observe("S15", "'a' grows to 101 bytes (buffer is 4 x 1 byte)", inout1::<Option<String>>(&c, "begin :v := :v || rpad('x', 100, 'x'); end;", &"a"));
    t.observe("S16", "'a' grows to 4 bytes (exactly 4 x input)", inout1::<Option<String>>(&c, "begin :v := :v || 'bcd'; end;", &"a"));
    t.observe("S17", "'a' grows to 5 bytes (4 x input + 1)", inout1::<Option<String>>(&c, "begin :v := :v || 'bcde'; end;", &"a"));
    t.check("S18", "positional get(0) counts only OUT binds", "\"!x!\"", (|| {
        let mut r = c.execute("begin :1 := :2 || :3; end;", &[&DB_TYPE_VARCHAR, &"!x", &"!"])?;
        Ok(r.out_bind_data().get::<String>(0)?)
    })());
    t.observe("S14b", "NULL IN OUT grows to 100 bytes", inout1::<Option<String>>(&c, "begin :v := :v || rpad('x', 100, 'x'); end;", &None::<String>));
    t.check("S19", "workaround: IN OUT split into IN + padded OUT via a local variable", "(32767, 32767, true)", (|| {
        let mut r = c.execute_named(
            "declare v varchar2(32767) := :v_in; begin v := v || rpad('x', 32766, 'x'); :v_out := v; end;",
            &[("v_in", &"a"), ("v_out", &pad)],
        )?;
        let s: Option<String> = r.out_bind_data().get("v_out")?;
        Ok(str_shape(&s, &format!("a{}", "x".repeat(32766))))
    })());
    t.check("S20", "workaround on a real Call: fx_scalar.bump via local variable", "Some(\"abc!\")", (|| {
        let mut r = c.execute_named(
            "declare n number := 1; d date := sysdate; v varchar2(32767) := :v_in; begin fx_scalar.bump(n, v, d); :v_out := v; end;",
            &[("v_in", &"abc"), ("v_out", &pad)],
        )?;
        Ok(r.out_bind_data().get::<Option<String>>("v_out")?)
    })());
    t.check("S21", "workaround with NULL input: bump(NULL) via local variable", "Some(\"!\")", (|| {
        let mut r = c.execute_named(
            "declare n number := 1; d date := sysdate; v varchar2(32767) := :v_in; begin fx_scalar.bump(n, v, d); :v_out := v; end;",
            &[("v_in", &None::<String>), ("v_out", &pad)],
        )?;
        Ok(r.out_bind_data().get::<Option<String>>("v_out")?)
    })());

    println!("== DATE: OUT and IN OUT ==");
    t.check("D1", "DATE with time of day", "Some(OracleTimestamp(\"2026-11-19T13:45:30\"))", (|| Ok(echo(&c, &1i64, &"x", &d0)?.2))());
    t.check("D2", "0001-01-01 00:00:00", "Some(OracleTimestamp(\"0001-01-01T00:00:00\"))", (|| Ok(echo(&c, &1i64, &"x", &ts(1, 1, 1, 0, 0, 0, 0))?.2))());
    t.check("D3", "9999-12-31 23:59:59", "Some(OracleTimestamp(\"9999-12-31T23:59:59\"))", (|| Ok(echo(&c, &1i64, &"x", &ts(9999, 12, 31, 23, 59, 59, 0))?.2))());
    t.check("D4", "NULL DATE -> None", "None", (|| Ok(echo(&c, &1i64, &"x", &None::<OracleTimestamp>)?.2))());
    t.observe("D5", "IN TIMESTAMP with nanos into a DATE param (truncate or round?)", (|| Ok(echo(&c, &1i64, &"x", &ts(2026, 11, 19, 13, 45, 30, 999_999_999))?.2))());
    t.observe("D5b", "server-only: DATE := TIMESTAMP .999999999 (reference for D5)", out1::<String>(&c, "declare d date; begin d := to_timestamp('2026-11-19 13:45:30.999999999', 'YYYY-MM-DD HH24:MI:SS.FF9'); :o := to_char(d, 'YYYY-MM-DD HH24:MI:SS'); end;", &DB_TYPE_VARCHAR));
    t.check("D6", "BC date via server: 4712-01-01 BC", "Ok(Some(OracleTimestamp(\"-4712-01-01T00:00:00\")))", Ok(out1::<Option<OracleTimestamp>>(&c, "begin :o := to_date('-4712-01-01', 'SYYYY-MM-DD'); end;", &DB_TYPE_DATE).map_err(|e| e.to_string())));
    let bump_date = |v: &dyn ToDbValue| -> R<Option<OracleTimestamp>> {
        let mut r = c.execute_named(
            "declare n number := 1; s varchar2(10) := 'a'; begin fx_scalar.bump(n, s, :v); end;",
            &[("v", v)],
        )?;
        Ok(r.out_bind_data().get("v")?)
    };
    t.check("D7", "IN OUT 2024-02-28 + 1 = leap day", "Some(OracleTimestamp(\"2024-02-29T10:00:00\"))", bump_date(&ts(2024, 2, 28, 10, 0, 0, 0)));
    t.check("D8", "IN OUT 2025-12-31 23:59:59 + 1", "Some(OracleTimestamp(\"2026-01-01T23:59:59\"))", bump_date(&ts(2025, 12, 31, 23, 59, 59, 0)));
    t.check("D9", "IN OUT NULL -> None", "None", bump_date(&None::<OracleTimestamp>));

    println!("== TIMESTAMP family: OUT and IN OUT in Anonymous blocks ==");
    t.check("T1", "TIMESTAMP(9) OUT keeps nanoseconds", "OracleTimestamp(\"2026-11-19T13:45:30.123456789\")", out1::<OracleTimestamp>(&c, "begin :o := to_timestamp('2026-11-19 13:45:30.123456789', 'YYYY-MM-DD HH24:MI:SS.FF9'); end;", &DB_TYPE_TIMESTAMP));
    t.check("T2", "TIMESTAMP IN OUT + 1 second keeps nanos", "OracleTimestamp(\"2026-11-19T13:45:31.000000001\")", inout1::<OracleTimestamp>(&c, "begin :v := :v + interval '1' second; end;", &ts(2026, 11, 19, 13, 45, 30, 1)));
    t.observe("T3", "TIMESTAMP IN OUT with nanos=0 (client sends it as DATE)", inout1::<OracleTimestamp>(&c, "begin :v := :v + interval '0.5' second; end;", &ts(2026, 11, 19, 13, 45, 30, 0)));
    t.observe("T4", "TIMESTAMP WITH TIME ZONE -03:00 OUT (offset kept?)", out1::<OracleTimestamp>(&c, "begin :o := to_timestamp_tz('2026-11-19 13:45:30.5 -03:00', 'YYYY-MM-DD HH24:MI:SS.FF TZH:TZM'); end;", &DB_TYPE_TIMESTAMP_TZ));
    t.observe("T5", "TIMESTAMP WITH TIME ZONE named region OUT", out1::<OracleTimestamp>(&c, "begin :o := to_timestamp_tz('2026-11-19 13:45:30 America/Sao_Paulo', 'YYYY-MM-DD HH24:MI:SS TZR'); end;", &DB_TYPE_TIMESTAMP_TZ));
    t.check("T5b", "workaround: TSTZ offset kept as text via to_char", "\"2026-11-19 13:45:30.500000000 -03:00\"", out1::<String>(&c, "begin :o := to_char(to_timestamp_tz('2026-11-19 13:45:30.5 -03:00', 'YYYY-MM-DD HH24:MI:SS.FF TZH:TZM'), 'YYYY-MM-DD HH24:MI:SS.FF9 TZH:TZM'); end;", &DB_TYPE_VARCHAR));
    t.observe("T5c", "session and database time zones", out1::<String>(&c, "begin :o := sessiontimezone || ' / ' || dbtimezone; end;", &DB_TYPE_VARCHAR));
    t.observe("T6", "TIMESTAMP WITH LOCAL TIME ZONE OUT", out1::<OracleTimestamp>(&c, "begin :o := cast(to_timestamp_tz('2026-11-19 13:45:30 -03:00', 'YYYY-MM-DD HH24:MI:SS TZH:TZM') as timestamp with local time zone); end;", &DB_TYPE_TIMESTAMP_LTZ));
    t.observe("T7", "UTC TIMESTAMP IN OUT (binds as TIMESTAMP_TZ) + 1 hour", inout1::<OracleTimestamp>(&c, "begin :v := :v + interval '1' hour; end;", &OracleTimestamp::new_utc_timestamp(2026, 11, 19, 13, 45, 30, 5)));
    t.observe("T8", "DATE OUT read into a DB_TYPE_TIMESTAMP bind", out1::<OracleTimestamp>(&c, "begin :o := to_date('2026-11-19 13:45:30', 'YYYY-MM-DD HH24:MI:SS'); end;", &DB_TYPE_TIMESTAMP));

    println!("== Function return values ==");
    let add = |a: &dyn ToDbValue, b: &dyn ToDbValue| -> R<Option<OracleNumber>> {
        let mut r = c.execute_named("begin :r := fx_scalar.add_numbers(:a, :b); end;", &[("r", &DB_TYPE_NUMBER), ("a", a), ("b", b)])?;
        Ok(r.out_bind_data().get("r")?)
    };
    t.check("F1", "add_numbers(2, 40)", "Some(42)", add(&2i64, &40i64));
    t.check("F2", "add_numbers(38 digits, 1)", "Some(12345678901234567890123456789012345679)", add(&num(n38), &1i64));
    t.check("F3", "add_numbers(NULL, 1) -> None", "None", add(&None::<OracleNumber>, &1i64));
    let greet = |name: &dyn ToDbValue| -> R<Option<String>> {
        let mut r = c.execute_named("begin :r := fx_scalar.greet(:n); end;", &[("r", &DB_TYPE_VARCHAR), ("n", name)])?;
        Ok(r.out_bind_data().get("r")?)
    };
    t.check("F4", "greet(multibyte)", &format!("Some({:?})", format!("Hello, {mb}!")), greet(&mb));
    t.check("F5", "greet(NULL)", "Some(\"Hello, !\")", greet(&None::<String>));
    let long_name = "a".repeat(10_000);
    t.observe("F6", "greet(10000 x 'a') into bare DB_TYPE_VARCHAR (result 10008 bytes)", greet(&long_name).map(|s| s.map(|s| s.len())));
    let add_days = |d: &dyn ToDbValue, n: &dyn ToDbValue| -> R<Option<OracleTimestamp>> {
        let mut r = c.execute_named("begin :r := fx_scalar.add_days(:d, :n); end;", &[("r", &DB_TYPE_DATE), ("d", d), ("n", n)])?;
        Ok(r.out_bind_data().get("r")?)
    };
    t.check("F7", "add_days(2026-11-19 13:45:30, 30)", "Some(OracleTimestamp(\"2026-12-19T13:45:30\"))", add_days(&d0, &30i64));
    t.check("F8", "add_days(.., 1.5) = +36h", "Some(OracleTimestamp(\"2026-11-21T01:45:30\"))", add_days(&d0, &num("1.5")));
    t.check("F9", "function call positional, get(0)", "42", (|| {
        let mut r = c.execute("begin :1 := fx_scalar.add_numbers(:2, :3); end;", &[&DB_TYPE_NUMBER, &40i64, &2i64])?;
        Ok(r.out_bind_data().get::<i64>(0)?)
    })());

    println!(
        "== {} pass, {} fail, {} err, {} observed ==",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
