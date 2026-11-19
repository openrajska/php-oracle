//! Throwaway probe for the REF CURSOR / Implicit results ticket (#8) of the
//! rust-oracledb spike.
//!
//! Reads REF CURSORs from the `fx_cursor` fixture package
//! (fixtures/sql/02_cursor.sql) as OUT parameters, as function return values
//! and as nested `CURSOR()` columns, then runs `DBMS_SQL.RETURN_RESULT`
//! blocks. Prints one line per case, with the same tags as the scalar probe:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Run inside ddev: `cd spikes/rust-oracledb/probe && ddev cargo run -q --bin cursor`.

use oracledb::{Connection, Cursor, DB_TYPE_CURSOR, DB_TYPE_NUMBER, OracleTimestamp, ToDbValue};
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
/// Below the total row count means the rest is still on the server.
fn cached_rows(c: &Cursor) -> String {
    let d = format!("{c:?}");
    let i = d.find("cached_rows: ").map(|i| i + 13).unwrap_or(0);
    d[i..].chars().take_while(|c| c.is_ascii_digit()).collect()
}

/// An fx_items row as (id, name, price, created_at).
fn item(row: &oracledb::Row) -> R<(i64, String, f64, OracleTimestamp)> {
    Ok((row.get("ID")?, row.get("NAME")?, row.get("PRICE")?, row.get("CREATED_AT")?))
}

/// Drains a Cursor of fx_items rows into (columns, row count, sum of prices,
/// first row, last row). Expected values come from the fixture README.
#[derive(Debug)]
#[allow(dead_code)]
struct Items {
    cols: String,
    rows: usize,
    sum: f64,
    first: Option<(i64, String, f64, OracleTimestamp)>,
    last: Option<(i64, String, f64, OracleTimestamp)>,
}

fn drain_items(mut c: Cursor) -> R<Items> {
    let cols = cols(&c);
    let (mut rows, mut sum, mut first, mut last) = (0, 0.0, None, None);
    for row in &mut c {
        let it = item(&row?)?;
        rows += 1;
        sum += it.2;
        if first.is_none() {
            first = Some(it.clone());
        }
        last = Some(it);
    }
    Ok(Items { cols, rows, sum, first, last })
}

/// (rows, sum of prices) of a drained Cursor of fx_items rows.
fn count_sum(c: Cursor) -> R<(usize, f64)> {
    let i = drain_items(c)?;
    Ok((i.rows, i.sum))
}

/// Names of a Cursor of fx_categories rows (ID, NAME).
fn category_names(c: Cursor) -> R<Vec<String>> {
    c.map(|r| Ok(r?.get::<String>("NAME")?)).collect()
}

/// `fx_cursor.two_cursors`: the two REF CURSOR OUT parameters and the scalar OUT.
fn two_cursors(c: &Connection, limit: i64) -> R<(Cursor, Cursor, i64)> {
    let mut r = c.execute_named(
        "begin fx_cursor.two_cursors(:p_limit, :o_items, :o_categories, :o_total); end;",
        &[
            ("p_limit", &limit),
            ("o_items", &DB_TYPE_CURSOR),
            ("o_categories", &DB_TYPE_CURSOR),
            ("o_total", &DB_TYPE_NUMBER),
        ],
    )?;
    let mut out = r.out_bind_data();
    Ok((out.take("o_items")?, out.take("o_categories")?, out.get("o_total")?))
}

/// An Anonymous block with one REF CURSOR OUT bind `:o`.
fn cursor_out(c: &Connection, plsql: &str, binds: &[(&str, &dyn ToDbValue)]) -> R<Cursor> {
    let mut all: Vec<(&str, &dyn ToDbValue)> = binds.to_vec();
    all.push(("o", &DB_TYPE_CURSOR));
    let mut r = c.execute_named(plsql, &all)?;
    Ok(r.out_bind_data().take("o")?)
}

/// Rows of a Cursor of (ID, NAME, ITEMS) where ITEMS is a nested Cursor of
/// item rows, rendered as `id:name[item ids]` and read row by row.
fn nested_row_by_row(outer: Cursor) -> R<Vec<String>> {
    let mut v = Vec::new();
    for row in outer {
        let mut row = row?;
        let id: i64 = row.get("ID")?;
        let name: String = row.get("NAME")?;
        let items: Cursor = row.take("ITEMS")?;
        let ids = items.map(|r| Ok(r?.get::<i64>("ID")?)).collect::<R<Vec<_>>>()?;
        v.push(format!("{id}:{name}{ids:?}"));
    }
    Ok(v)
}

const CATEGORIES: &str = r#"["Plain", "Ação", "Café", "Straße", "日本"]"#;
const NESTED: &str = r#"["1:Plain[1, 6, 11, 16]", "2:Ação[2, 7, 12, 17]", "3:Café[3, 8, 13, 18]", "4:Straße[4, 9, 14, 19]", "5:日本[5, 10, 15, 20]"]"#;
const CURSOR_QUERY: &str = "select c.id, c.name,
       cursor(select i.id, i.name from fx_items i
               where i.category_id = c.id and i.id <= 20 order by i.id) as items
  from fx_categories c
 order by c.id";

/// Runs an Implicit results workaround: the Call goes through DBMS_SQL with
/// `treat_as_client_for_results => true`, and each result taken with
/// `DBMS_SQL.GET_NEXT_RESULT` is handed back as a REF CURSOR OUT bind. Up to
/// `slots` results; `:o_count` says how many slots were filled.
const IMPLICIT_WRAPPER: &str = "declare
  c  integer := dbms_sql.open_cursor(treat_as_client_for_results => true);
  n  integer;
  rc sys_refcursor;
  k  integer := 0;
begin
  dbms_sql.parse(c, 'begin fx_cursor.implicit_results(:p_limit); end;', dbms_sql.native);
  dbms_sql.bind_variable(c, ':p_limit', :p_limit);
  n := dbms_sql.execute(c);
  begin
    dbms_sql.get_next_result(c, rc); :o_r1 := rc; k := 1;
    dbms_sql.get_next_result(c, rc); :o_r2 := rc; k := 2;
    dbms_sql.get_next_result(c, rc); :o_r3 := rc; k := 3;
  exception
    when no_data_found then null;
  end;
  :o_count := k;
  dbms_sql.close_cursor(c);
end;";

fn main() -> R<()> {
    let c = connect()?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };

    println!("== REF CURSOR OUT parameters: fx_cursor.two_cursors ==");
    t.check(
        "C1",
        "items cursor (limit 250): columns, rows, sum, first, last",
        "Items { cols: \"ID,NAME,PRICE,CREATED_AT\", rows: 250, sum: 39218.75, first: Some((1, \"Item 0001\", 1.25, OracleTimestamp(\"2026-01-01T00:00:00\"))), last: Some((250, \"Item 0250\", 312.5, OracleTimestamp(\"2026-01-11T09:00:00\"))) }",
        (|| drain_items(two_cursors(&c, 250)?.0))(),
    );
    t.check("C2", "categories cursor from the same Call (multibyte names)", CATEGORIES, (|| category_names(two_cursors(&c, 250)?.1))());
    t.check("C3", "scalar OUT next to the cursors", "1000", (|| Ok(two_cursors(&c, 250)?.2))());
    t.check("C4", "interleaved: 1 item, all categories, rest of items", "(1, 5, 249)", (|| {
        let (mut items, cats, _) = two_cursors(&c, 250)?;
        let first = items.next().into_iter().count();
        let ncat = cats.count();
        Ok((first, ncat, items.count()))
    })());
    t.check("C5", "1000 rows cross prefetch (2) and fetch array size (100): rows, sum", "(1000, 625625.0)", (|| count_sum(two_cursors(&c, 1000)?.0))());
    t.observe("C6", "lazy: rows held client-side before / after the 1st, 100th, 101st row of 1000", (|| {
        let mut items = two_cursors(&c, 1000)?.0;
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
    t.check("C7", "empty cursor (limit 0) still has its columns", "(\"ID,NAME,PRICE,CREATED_AT\", 0)", (|| {
        let items = two_cursors(&c, 0)?.0;
        Ok((cols(&items), items.count()))
    })());
    t.check("C8", "1000 Calls dropping both cursors unread (2000 > open_cursors 300), then a read", "(250, 39218.75)", (|| {
        for _ in 0..1000 {
            let _ = two_cursors(&c, 250)?;
        }
        count_sum(two_cursors(&c, 250)?.0)
    })());
    t.observe("C9", "borrowing get::<Cursor> instead of take", (|| {
        let mut r = c.execute_named(
            "begin fx_cursor.two_cursors(1, :a, :b, :n); end;",
            &[("a", &DB_TYPE_CURSOR), ("b", &DB_TYPE_CURSOR), ("n", &DB_TYPE_NUMBER)],
        )?;
        let out = r.out_bind_data();
        Ok(out.get::<Cursor>("a")?.count())
    })());
    t.check("C10", "strongly typed REF CURSOR (fx_cursor.typed_cursor)", "(\"ID,CATEGORY_ID,NAME,PRICE,CREATED_AT\", 3)", (|| {
        let cur = cursor_out(&c, "begin fx_cursor.typed_cursor(:p, :o); end;", &[("p", &3i64)])?;
        Ok((cols(&cur), cur.count()))
    })());
    t.observe("C11", "REF CURSOR OUT never opened by PL/SQL", (|| {
        let cur = cursor_out(&c, "declare x sys_refcursor; begin :o := x; end;", &[])?;
        Ok((cols(&cur), cur.map(|r| r.map(|_| ())).collect::<Result<Vec<_>, _>>()?.len()))
    })());
    t.observe("C12", "REF CURSOR OUT opened and closed by PL/SQL", (|| {
        let cur = cursor_out(&c, "declare x sys_refcursor; begin open x for select 1 from dual; close x; :o := x; end;", &[])?;
        Ok((cols(&cur), cur.map(|r| r.map(|_| ())).collect::<Result<Vec<_>, _>>()?.len()))
    })());
    t.check("C13", "connection still usable after C11/C12", "1", (|| Ok(c.query_row("select 1 from dual", &[])?.get::<i64>(0)?))());

    println!("== REF CURSOR as a function return value: fx_cursor.items ==");
    t.check(
        "R1",
        "begin :o := fx_cursor.items(1000); end;",
        "Items { cols: \"ID,CATEGORY_ID,NAME,PRICE,CREATED_AT\", rows: 1000, sum: 625625.0, first: Some((1, \"Item 0001\", 1.25, OracleTimestamp(\"2026-01-01T00:00:00\"))), last: Some((1000, \"Item 1000\", 1250.0, OracleTimestamp(\"2026-02-11T15:00:00\"))) }",
        (|| drain_items(cursor_out(&c, "begin :o := fx_cursor.items(:p); end;", &[("p", &1000i64)])?)) (),
    );
    t.check("R2", "positional bind :1 := fx_cursor.items(:2), take(0)", "3", (|| {
        let mut r = c.execute("begin :1 := fx_cursor.items(:2); end;", &[&DB_TYPE_CURSOR, &3i64])?;
        Ok(r.out_bind_data().take::<Cursor>(0)?.count())
    })());
    t.check("R3", "function cursor in a select list: select fx_cursor.items(3) from dual", "(1, 3)", (|| {
        let mut q = c.query("select fx_cursor.items(3) as items from dual", &[])?;
        let mut row = q.next().ok_or("no row")??;
        let inner: Cursor = row.take("ITEMS")?;
        Ok((1 + q.count(), inner.count()))
    })());

    println!("== Nested CURSOR() column ==");
    t.check("K1", "query with a CURSOR() column, nested read row by row", NESTED, (|| nested_row_by_row(c.query(CURSOR_QUERY, &[])?))());
    t.check("K2", "REF CURSOR whose rows hold a CURSOR() (fx_cursor.categories_with_items)", NESTED, (|| {
        nested_row_by_row(cursor_out(&c, "begin :o := fx_cursor.categories_with_items; end;", &[])?)
    })());
    t.observe("K3", "outer fetched completely first, nested cursors read afterwards", (|| {
        let mut held = Vec::new();
        for row in c.query(CURSOR_QUERY, &[])? {
            let mut row = row?;
            held.push((row.get::<i64>("ID")?, row.take::<Cursor>("ITEMS")?));
        }
        held.into_iter()
            .map(|(id, cur)| Ok(format!("{id}:{:?}", cur.map(|r| Ok(r?.get::<i64>("ID")?)).collect::<R<Vec<i64>>>())))
            .collect::<R<Vec<_>>>()
    })());
    t.check("K4", "200 queries dropping 5 nested cursors unread each, then a read", NESTED, (|| {
        for _ in 0..200 {
            for row in c.query(CURSOR_QUERY, &[])? {
                let _ = row?;
            }
        }
        nested_row_by_row(c.query(CURSOR_QUERY, &[])?)
    })());

    println!("== Implicit results (DBMS_SQL.RETURN_RESULT), each case on a fresh connection ==");
    let ic = connect()?;
    t.observe("I1", "begin fx_cursor.implicit_results(5); end;", (|| {
        ic.execute("begin fx_cursor.implicit_results(5); end;", &[])?;
        Ok("no error")
    })());
    t.observe("I2", "same connection afterwards: select 1 from dual", (|| Ok(ic.query_row("select 1 from dual", &[])?.get::<i64>(0)?))());
    let ic = connect()?;
    t.observe("I3", "did the block run to the end? (client_info set after both RETURN_RESULTs)", (|| {
        let e = ic.execute(
            "begin dbms_application_info.set_client_info('start'); fx_cursor.implicit_results(5); dbms_application_info.set_client_info('end'); end;",
            &[],
        );
        let info = ic.query_row("select sys_context('userenv', 'client_info') from dual", &[]).and_then(|r| r.get::<String>(0));
        Ok(format!("execute={:?} client_info={:?}", e.map(|_| ()).map_err(|e| one_line(&e.to_string())), info.map_err(|e| one_line(&e.to_string()))))
    })());
    let ic = connect()?;
    t.observe("I4", "OUT bind in a block that also returns Implicit results", (|| {
        let mut r = ic.execute_named("begin fx_cursor.implicit_results(5); :o := 42; end;", &[("o", &DB_TYPE_NUMBER)])?;
        Ok(r.out_bind_data().get::<i64>("o")?)
    })());
    let ic = connect()?;
    t.observe("I5", "Implicit results through query() instead of execute()", (|| {
        Ok(ic.query("begin fx_cursor.implicit_results(5); end;", &[])?.count())
    })());
    let ic = connect()?;
    t.observe("I6", "400 failing executions (800 results > open_cursors 300), then a REF CURSOR read", (|| {
        let errs = (0..400).filter(|_| ic.execute("begin fx_cursor.implicit_results(5); end;", &[]).is_err()).count();
        let read = cursor_out(&ic, "begin :o := fx_cursor.items(:p); end;", &[("p", &3i64)]).and_then(|cur| {
            Ok(cur.map(|r| r.map(|_| ())).collect::<Result<Vec<_>, _>>()?.len())
        });
        Ok(format!("errors={errs} then read={:?}", read.map_err(|e| one_line(&e.to_string()))))
    })());

    println!("== Implicit results workaround: DBMS_SQL.GET_NEXT_RESULT -> REF CURSOR OUT ==");
    let wc = connect()?;
    let wrapper = |limit: i64| -> R<(i64, Cursor, Cursor, Cursor)> {
        let mut r = wc.execute_named(
            IMPLICIT_WRAPPER,
            &[
                ("p_limit", &limit),
                ("o_r1", &DB_TYPE_CURSOR),
                ("o_r2", &DB_TYPE_CURSOR),
                ("o_r3", &DB_TYPE_CURSOR),
                ("o_count", &DB_TYPE_NUMBER),
            ],
        )?;
        let mut out = r.out_bind_data();
        Ok((out.get("o_count")?, out.take("o_r1")?, out.take("o_r2")?, out.take("o_r3")?))
    };
    t.check("W1", "result count", "2", (|| Ok(wrapper(5)?.0))());
    t.check("W2", "1st Implicit result (id, name, price; limit 5)", "(\"ID,NAME,PRICE\", 5)", (|| {
        let r1 = wrapper(5)?.1;
        Ok((cols(&r1), r1.count()))
    })());
    t.check("W3", "2nd Implicit result (different columns)", CATEGORIES, (|| category_names(wrapper(5)?.2))());
    t.observe("W4", "3rd slot, never filled", (|| {
        let r3 = wrapper(5)?.3;
        Ok((cols(&r3), r3.map(|r| r.map(|_| ())).collect::<Result<Vec<_>, _>>()?.len()))
    })());
    t.check("W5", "1st result with 1000 rows, read lazily", "(1000, 1000)", (|| {
        let r1 = wrapper(1000)?.1;
        let ids = r1.map(|r| Ok(r?.get::<i64>("ID")?)).collect::<R<Vec<i64>>>()?;
        Ok((ids.len(), *ids.last().unwrap_or(&0)))
    })());
    t.check("W6", "500 wrapped Calls dropping results unread, then a read", "2", (|| {
        for _ in 0..500 {
            let _ = wrapper(5)?;
        }
        Ok(wrapper(5)?.0)
    })());

    println!(
        "== {} pass, {} fail, {} err, {} observed ==",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
