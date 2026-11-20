//! Throwaway probe for the execute_batch half of the "LOB streaming and
//! execute_batch errors" ticket (#10) of the rust-oracledb spike.
//!
//! Runs `execute_batch` against `fx_batch_rows` (fixtures/sql/06_batch.sql)
//! with rows that break its constraints, records how the failure surfaces
//! (which error, whether a row index is reported, which rows stay applied),
//! and checks two workarounds that report every failing row: a row-by-row
//! retry after a savepoint, and DML error logging (`LOG ERRORS`). Same tags
//! as the other probes:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Lines starting with `~` carry timing for the case above them. Every case
//! rolls back; the error log table the probe creates is dropped at the end.
//!
//! Run inside ddev: `cd spikes/rust-oracledb/probe && ddev cargo run -q --release --bin batch`.

use oracledb::{Connection, ToDbValue};
use std::fmt::Debug;
use std::time::Instant;

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

/// One fx_batch_rows row: (id, code, qty).
type Row = (Option<i64>, Option<String>, Option<i64>);

#[derive(Clone, Copy)]
enum Bad {
    DuplicateId,
    NullCode,
    NegativeQty,
}

/// `n` valid rows (id = i + 1, code = 'C<i+1>', qty = i), with the given
/// indexes (0-based) broken.
fn rows(n: usize, bad: &[(usize, Bad)]) -> Vec<Row> {
    let mut v: Vec<Row> = (0..n).map(|i| (Some(i as i64 + 1), Some(format!("C{}", i + 1)), Some(i as i64))).collect();
    for &(i, b) in bad {
        match b {
            Bad::DuplicateId => v[i].0 = Some(1),
            Bad::NullCode => v[i].1 = None,
            Bad::NegativeQty => v[i].2 = Some(-1),
        }
    }
    v
}

const INSERT: &str = "insert into fx_batch_rows (id, code, qty) values (:1, :2, :3)";

fn batch(c: &Connection, sql: &str, rows: &[Row]) -> Result<u64, oracledb::Error> {
    let refs: Vec<Vec<&dyn ToDbValue>> = rows.iter().map(|(a, b, q)| vec![a as &dyn ToDbValue, b, q]).collect();
    let slices: Vec<&[&dyn ToDbValue]> = refs.iter().map(|v| v.as_slice()).collect();
    Ok(c.execute_batch(sql, slices.as_slice())?.rows_affected())
}

fn count(c: &Connection) -> R<i64> {
    Ok(c.query_row("select count(*) from fx_batch_rows", &[])?.get(0)?)
}

fn ids(c: &Connection) -> R<Vec<i64>> {
    let rows = c.query("select id from fx_batch_rows order by id", &[])?;
    rows.map(|r| Ok(r?.get::<i64>(0)?)).collect()
}

/// What a failed batch leaves: (error code, error message, rows in the table).
fn failed(c: &Connection, rows: &[Row]) -> R<(Option<usize>, String, i64)> {
    match batch(c, INSERT, rows) {
        Ok(n) => Err(format!("no error, {n} rows").into()),
        Err(e) => Ok((e.db_error_code(), one_line(&e.to_string()), count(c)?)),
    }
}

/// Workaround 1: one execute_batch; if it fails, roll back to a savepoint
/// and run the rows one by one, collecting (index, ORA code) per failure.
fn batch_or_retry(c: &Connection, rows: &[Row]) -> R<(u64, Vec<(usize, usize)>)> {
    c.execute("savepoint fx_batch", &[])?;
    if let Ok(n) = batch(c, INSERT, rows) {
        return Ok((n, vec![]));
    }
    c.execute("rollback to savepoint fx_batch", &[])?;
    let (mut ok, mut errors) = (0u64, Vec::new());
    for (i, (a, b, q)) in rows.iter().enumerate() {
        match c.execute(INSERT, &[a, b, q]) {
            Ok(_) => ok += 1,
            Err(e) => errors.push((i, e.db_error_code().unwrap_or(0))),
        }
    }
    Ok((ok, errors))
}

/// Workaround 2: DML error logging. Each row carries its index as the tag,
/// so one round trip inserts the good rows and logs (index, ORA code) for
/// the rest.
fn batch_log_errors(c: &Connection, rows: &[Row]) -> R<(u64, Vec<(String, i64)>)> {
    let (n, logged) = batch_log_errors_full(c, rows)?;
    Ok((n, logged.into_iter().map(|(tag, code, ..)| (tag, code)).collect()))
}

/// Logged rows as (tag, ORA code, ID, CODE, QTY), the last three being the
/// row's own values as the error log table stores them.
type Logged = (String, i64, Option<String>, Option<String>, Option<String>);

fn batch_log_errors_full(c: &Connection, rows: &[Row]) -> R<(u64, Vec<Logged>)> {
    // Error logging writes autonomously (a rollback keeps the log rows), so
    // start from an empty log. TRUNCATE is DDL: run it between transactions.
    c.execute("truncate table fx_batch_rows_err", &[])?;
    let sql = "insert into fx_batch_rows (id, code, qty) values (:1, :2, :3)
               log errors into fx_batch_rows_err (:4) reject limit unlimited";
    let tags: Vec<String> = (0..rows.len()).map(|i| i.to_string()).collect();
    let refs: Vec<Vec<&dyn ToDbValue>> =
        rows.iter().zip(&tags).map(|((a, b, q), t)| vec![a as &dyn ToDbValue, b, q, t]).collect();
    let slices: Vec<&[&dyn ToDbValue]> = refs.iter().map(|v| v.as_slice()).collect();
    let n = c.execute_batch(sql, slices.as_slice())?.rows_affected();
    let logged = c.query(
        "select ora_err_tag$, ora_err_number$, id, code, qty from fx_batch_rows_err order by to_number(ora_err_tag$)",
        &[],
    )?;
    let logged = logged.map(|r| {
        let r = r?;
        Ok((r.get::<String>(0)?, r.get::<i64>(1)?, r.get(2)?, r.get(3)?, r.get(4)?))
    });
    Ok((n, logged.collect::<R<Vec<_>>>()?))
}

fn main() -> R<()> {
    let c = connect()?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };
    let mixed = [(3, Bad::DuplicateId), (5, Bad::NullCode), (7, Bad::NegativeQty)];

    println!("== execute_batch: all rows valid ==");
    t.check("B1", "1000 valid rows: (rows_affected, rows in table)", "(1000, 1000)", (|| {
        let n = batch(&c, INSERT, &rows(1000, &[]))?;
        Ok((n, count(&c)?))
    })());
    c.rollback()?;

    println!("== execute_batch: one bad row (code, first line of the message, rows left in the table) ==");
    t.observe("B2", "10 rows, index 3 duplicates id 1", failed(&c, &rows(10, &[(3, Bad::DuplicateId)])));
    t.check("B2b", "rows applied before the failing one stay in the transaction (ids)", "[1, 2, 3]", ids(&c));
    c.rollback()?;
    t.observe("B3", "10 rows, index 5 has a NULL code", failed(&c, &rows(10, &[(5, Bad::NullCode)])));
    c.rollback()?;
    t.observe("B4", "10 rows, index 7 has qty < 0", failed(&c, &rows(10, &[(7, Bad::NegativeQty)])));
    c.rollback()?;

    println!("== execute_batch: several bad rows ==");
    t.observe("B5", "10 rows, bad at 3 (dup), 5 (NULL), 7 (CHECK): only the first surfaces", failed(&c, &rows(10, &mixed)));
    c.rollback()?;
    t.observe("B6", "the whole error (Debug): is there a row index anywhere?", (|| {
        match batch(&c, INSERT, &rows(10, &mixed)) {
            Ok(n) => Ok(format!("no error, {n} rows")),
            Err(e) => Ok(format!("{e:?}")),
        }
    })());
    c.rollback()?;
    t.observe("B7", "10,000 rows, duplicate at index 9000: (code, message, rows applied), seconds", (|| {
        let start = Instant::now();
        let r = failed(&c, &rows(10_000, &[(9000, Bad::DuplicateId)]))?;
        Ok((r, format!("{:.3}", start.elapsed().as_secs_f64())))
    })());
    c.rollback()?;
    t.check("B8", "same connection and cached statement still work: a valid batch of 10", "(10, 10)", (|| {
        let n = batch(&c, INSERT, &rows(10, &[]))?;
        Ok((n, count(&c)?))
    })());
    c.rollback()?;

    println!("== execute_batch of a PL/SQL block ==");
    t.observe("B9", "PL/SQL block 'begin insert ...; end;' over 10 rows, bad at 3, 5, 7", (|| {
        let sql = "begin insert into fx_batch_rows (id, code, qty) values (:1, :2, :3); end;";
        match batch(&c, sql, &rows(10, &mixed)) {
            Ok(n) => Ok(format!("Ok({n}), {} rows", count(&c)?)),
            Err(e) => Ok(format!("{:?}: {}, {} rows left", e.db_error_code(), one_line(&e.to_string()), count(&c)?)),
        }
    })());
    c.rollback()?;

    println!("== Workaround 1: savepoint, then row-by-row retry ==");
    t.check("R1", "10 rows, bad at 3, 5, 7: (rows inserted, [(index, ORA code)])", "(7, [(3, 1), (5, 1400), (7, 2290)])", batch_or_retry(&c, &rows(10, &mixed)));
    t.check("R1b", "ids in the table", "[1, 2, 3, 5, 7, 9, 10]", ids(&c));
    c.rollback()?;
    let timed = |n: usize, bad: &[(usize, Bad)]| -> R<String> {
        let start = Instant::now();
        let (ok, errors) = batch_or_retry(&c, &rows(n, bad))?;
        c.rollback()?;
        Ok(format!("{ok} inserted, {} errors, {:.3} s", errors.len(), start.elapsed().as_secs_f64()))
    };
    t.observe("R2", "10,000 valid rows through the same path (one round trip)", timed(10_000, &[]));
    t.observe("R3", "10,000 rows with one duplicate at 9000 (batch + savepoint + 10,000 single executes)", timed(10_000, &[(9000, Bad::DuplicateId)]));

    println!("== Workaround 2: DML error logging (LOG ERRORS ... REJECT LIMIT UNLIMITED) ==");
    t.observe("E0", "create the error log table with dbms_errlog (DDL: commits)", (|| {
        c.rollback()?;
        let _ = c.execute("drop table fx_batch_rows_err purge", &[]);
        c.execute("begin dbms_errlog.create_error_log('FX_BATCH_ROWS', 'FX_BATCH_ROWS_ERR'); end;", &[])?;
        Ok("created")
    })());
    t.check("E1", "10 rows, bad at 3, 5, 7, tag = row index: (rows_affected, [(tag, ORA code)])", "(7, [(\"3\", 1), (\"5\", 1400), (\"7\", 2290)])", batch_log_errors(&c, &rows(10, &mixed)));
    t.check("E1b", "ids in the table", "[1, 2, 3, 5, 7, 9, 10]", ids(&c));
    c.rollback()?;
    t.observe("E1c", "same batch, full log rows: (tag, ORA code, ID, CODE, QTY as logged)", (|| Ok(batch_log_errors_full(&c, &rows(10, &mixed))?.1))());
    c.rollback()?;
    t.observe("E1d", "duplicates at 3 and 6 only (ids 1 and 2): full log rows", (|| {
        Ok(batch_log_errors_full(&c, &rows(10, &[(3, Bad::DuplicateId), (6, Bad::DuplicateId)]))?.1)
    })());
    c.rollback()?;
    t.observe("E1e", "duplicate CODE (unique key, not PK) at 4: full log rows", (|| {
        let mut r = rows(10, &[]);
        r[4].1 = Some("C1".into());
        Ok(batch_log_errors_full(&c, &r)?.1)
    })());
    c.rollback()?;
    t.observe("E2", "10,000 rows, 100 bad (every 100th duplicates id 1): (rows_affected, errors logged), seconds", (|| {
        let bad: Vec<(usize, Bad)> = (1..=100).map(|k| (k * 100 - 1, Bad::DuplicateId)).collect();
        let start = Instant::now();
        let (n, logged) = batch_log_errors(&c, &rows(10_000, &bad))?;
        let secs = start.elapsed().as_secs_f64();
        c.rollback()?;
        Ok((n, logged.len(), format!("{secs:.3}")))
    })());
    t.observe("E3", "error log rows survive a rollback? (count after rollback)", (|| {
        batch_log_errors(&c, &rows(10, &mixed))?;
        c.rollback()?;
        Ok(c.query_row("select count(*) from fx_batch_rows_err", &[])?.get::<i64>(0)?)
    })());
    c.execute("drop table fx_batch_rows_err purge", &[])?;

    c.rollback()?;
    println!(
        "\n{} pass, {} fail, {} err, {} observed",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
