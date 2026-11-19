//! Throwaway probe for the Implicit results patch of the rust-oracledb spike.
//!
//! Runs against the oracledb crate patched by ../patch/apply.sh
//! (implicit-results.diff), which handles TTC message type 27 during execute
//! and exposes the cursors as `ExecResult::implicit_results()`. Prints one
//! line per case, with the same tags as the other probes:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Run inside ddev:
//!   ddev exec bash spikes/rust-oracledb/patch/apply.sh
//!   cd spikes/rust-oracledb/probe-patched && ddev cargo run -q

use oracledb::{Connection, Cursor, DB_TYPE_CURSOR, DB_TYPE_NUMBER};
use std::fmt::Debug;

type R<T> = Result<T, Box<dyn std::error::Error>>;

struct Tally {
    pass: u32,
    fail: u32,
    err: u32,
    obs: u32,
}

impl Tally {
    fn check<T: Debug>(&mut self, id: &str, what: &str, expected: &str, got: R<T>) {
        match got {
            Ok(v) => {
                let s = format!("{v:?}");
                if s == expected {
                    self.pass += 1;
                    println!("PASS {id:<5} {what} => {s}");
                } else {
                    self.fail += 1;
                    println!("FAIL {id:<5} {what} => {s} (expected {expected})");
                }
            }
            Err(e) => {
                self.err += 1;
                println!("ERR  {id:<5} {what} => {}", one_line(&e.to_string()));
            }
        }
    }

    fn observe<T: Debug>(&mut self, id: &str, what: &str, got: R<T>) {
        self.obs += 1;
        match got {
            Ok(v) => println!("OBS  {id:<5} {what} => Ok({v:?})"),
            Err(e) => println!("OBS  {id:<5} {what} => Err({})", one_line(&e.to_string())),
        }
    }
}

fn one_line(s: &str) -> String {
    s.lines().next().unwrap_or("").to_string()
}

fn connect() -> R<Connection> {
    let config = oracledb::Config::default()
        .set_credentials(&std::env::var("ORACLE_USER")?, &std::env::var("ORACLE_PASSWORD")?)
        .set_connect_string(&std::env::var("ORACLE_CONNECT_STRING")?)?;
    Ok(oracledb::connect(config)?)
}

/// Column names of a Cursor, comma-separated.
fn cols(c: &Cursor) -> String {
    c.columns().iter().map(|m| m.name().to_string()).collect::<Vec<_>>().join(",")
}

/// Rows the Cursor holds client-side right now, read from its Debug output.
fn cached_rows(c: &Cursor) -> String {
    let d = format!("{c:?}");
    let i = d.find("cached_rows: ").map(|i| i + 13).unwrap_or(0);
    d[i..].chars().take_while(|c| c.is_ascii_digit()).collect()
}

/// Names of a Cursor of fx_categories rows (ID, NAME).
fn category_names(c: Cursor) -> R<Vec<String>> {
    c.map(|r| Ok(r?.get::<String>("NAME")?)).collect()
}

/// The Implicit results of `fx_cursor.implicit_results(limit)`.
fn implicit(c: &Connection, limit: i64) -> R<Vec<Cursor>> {
    let mut r = c.execute("begin fx_cursor.implicit_results(:1); end;", &[&limit])?;
    Ok(r.implicit_results())
}

/// Takes the n-th cursor out of a Vec.
fn nth(v: Vec<Cursor>, n: usize) -> R<Cursor> {
    v.into_iter().nth(n).ok_or_else(|| format!("no result #{}", n + 1).into())
}

const CATEGORIES: &str = r#"["Plain", "Ação", "Café", "Straße", "日本"]"#;

fn main() -> R<()> {
    let c = connect()?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };

    println!("== Implicit results through ExecResult::implicit_results() (patched crate) ==");
    t.check("P1", "result count of fx_cursor.implicit_results(5)", "2", (|| Ok(implicit(&c, 5)?.len()))());
    t.check("P2", "1st result: columns and rows (limit 5)", "(\"ID,NAME,PRICE\", 5)", (|| {
        let r1 = nth(implicit(&c, 5)?, 0)?;
        Ok((cols(&r1), r1.count()))
    })());
    t.check("P3", "2nd result: category names (multibyte)", CATEGORIES, (|| category_names(nth(implicit(&c, 5)?, 1)?))());
    t.check("P4", "1st and 2nd results read interleaved: 1 item, all categories, rest of items", "(1, 5, 4)", (|| {
        let mut v = implicit(&c, 5)?.into_iter();
        let (mut items, cats) = (v.next().ok_or("no #1")?, v.next().ok_or("no #2")?);
        let first = items.next().into_iter().count();
        let ncat = cats.count();
        Ok((first, ncat, items.count()))
    })());
    t.observe("P5", "lazy: rows held client-side before / after the 1st, 100th, 101st row of 1000", (|| {
        let mut items = nth(implicit(&c, 1000)?, 0)?;
        let before = cached_rows(&items);
        items.next();
        let a1 = cached_rows(&items);
        for _ in 0..99 {
            items.next();
        }
        let a100 = cached_rows(&items);
        items.next();
        let a101 = cached_rows(&items);
        Ok(format!("before={before} after1={a1} after100={a100} after101={a101}"))
    })());
    t.check("P6", "1st result with 1000 rows: row count, last id", "(1000, 1000)", (|| {
        let ids = nth(implicit(&c, 1000)?, 0)?.map(|r| Ok(r?.get::<i64>("ID")?)).collect::<R<Vec<i64>>>()?;
        Ok((ids.len(), *ids.last().unwrap_or(&0)))
    })());
    t.check("P7", "OUT bind and Implicit results in one block: (out, count, rows #1, names #2)", &format!("(42, 2, 5, {CATEGORIES})"), (|| {
        let mut r = c.execute_named("begin fx_cursor.implicit_results(5); :o := 42; end;", &[("o", &DB_TYPE_NUMBER)])?;
        let o: i64 = r.out_bind_data().get("o")?;
        let mut v = r.implicit_results().into_iter();
        let n = v.len();
        let (r1, r2) = (v.next().ok_or("no #1")?, v.next().ok_or("no #2")?);
        Ok((o, n, r1.count(), category_names(r2)?))
    })());
    t.check("P8", "block with no RETURN_RESULT: empty Vec", "0", (|| Ok(c.execute("begin null; end;", &[])?.implicit_results().len()))());
    t.check("P9", "plain DML / query through execute: empty Vec", "(0, 0)", (|| {
        let a = c.execute("update fx_items set name = name where id = 0", &[])?.implicit_results().len();
        let b = c.execute("select 1 from dual", &[])?.implicit_results().len();
        Ok((a, b))
    })());

    println!("== Errors and cleanup ==");
    let ec = connect()?;
    t.observe("P10", "RETURN_RESULT then raise_application_error in the same block", (|| {
        let mut r = ec.execute("begin fx_cursor.implicit_results(5); raise_application_error(-20001, 'boom'); end;", &[])?;
        Ok(r.implicit_results().len())
    })());
    t.check("P11", "same connection afterwards: select 1 from dual", "1", (|| Ok(ec.query_row("select 1 from dual", &[])?.get::<i64>(0)?))());
    t.check("P12", "500 failing blocks (2 results each, then the error), then a read", "(\"ID,NAME,PRICE\", 5)", (|| {
        for _ in 0..500 {
            let _ = ec.execute("begin fx_cursor.implicit_results(5); raise_application_error(-20001, 'boom'); end;", &[]);
        }
        let r1 = nth(implicit(&ec, 5)?, 0)?;
        Ok((cols(&r1), r1.count()))
    })());
    t.check("P13", "500 executions dropping both results unread (1000 > open_cursors 300), then a read", CATEGORIES, (|| {
        for _ in 0..500 {
            let _ = implicit(&c, 5)?;
        }
        category_names(nth(implicit(&c, 5)?, 1)?)
    })());
    t.check("P14", "500 executions dropping the ExecResult without calling implicit_results(), then a read", "2", (|| {
        for _ in 0..500 {
            c.execute("begin fx_cursor.implicit_results(5); end;", &[])?;
        }
        Ok(implicit(&c, 5)?.len())
    })());
    println!("== Not broken by the patch ==");
    t.check("P15", "REF CURSOR OUT: begin :o := fx_cursor.items(3); end;", "(\"ID,CATEGORY_ID,NAME,PRICE,CREATED_AT\", 3)", (|| {
        let mut r = c.execute_named("begin :o := fx_cursor.items(3); end;", &[("o", &DB_TYPE_CURSOR)])?;
        let cur: Cursor = r.out_bind_data().take("o")?;
        Ok((cols(&cur), cur.count()))
    })());

    println!(
        "== {} pass, {} fail, {} err, {} observed ==",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
