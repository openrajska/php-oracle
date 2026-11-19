//! Throwaway probe for the object types / Collections ticket (#9) of the
//! rust-oracledb spike.
//!
//! Reads object types and Collections from the `fx_object` / `fx_pipe`
//! fixtures (fixtures/sql/03_object.sql, 04_pipe.sql) as table columns,
//! function results, nested values and REF CURSOR columns; runs
//! `SELECT ... FROM TABLE(pipelined_fn())`; tries to bind objects,
//! Collections and Associative arrays; then runs the workarounds (JSON in,
//! REF CURSOR out). Same tags as the other probes:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Run inside ddev: `cd spikes/rust-oracledb/probe && ddev cargo run -q --bin object`.

use oracledb::{
    Connection, Cursor, DB_TYPE_CURSOR, DB_TYPE_NUMBER, DB_TYPE_OBJECT, DB_TYPE_VARCHAR,
    DbCollection, DbObject, ToDbValue,
};
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

type Point = (Option<f64>, Option<f64>, Option<String>);

/// An fx_point as (x, y, label).
fn point(o: &DbObject) -> R<Point> {
    Ok((o.get("X")?, o.get("Y")?, o.get("LABEL")?))
}

/// An fx_point_list as points; a NULL element stays None.
fn points(c: DbCollection) -> R<Vec<Option<Point>>> {
    let v: Vec<Option<DbObject>> = c.try_into()?;
    v.iter().map(|o| o.as_ref().map(point).transpose()).collect()
}

fn strings(c: DbCollection) -> R<Vec<Option<String>>> {
    Ok(c.try_into()?)
}

fn numbers(c: DbCollection) -> R<Vec<Option<f64>>> {
    Ok(c.try_into()?)
}

/// An fx_polygon as (name, vertices).
fn polygon(mut o: DbObject) -> R<(Option<String>, Option<Vec<Option<Point>>>)> {
    let name: Option<String> = o.get("NAME")?;
    let vertices: Option<DbCollection> = o.take("VERTICES")?;
    Ok((name, vertices.map(points).transpose()?))
}

/// fx_shapes row as (center, tags, outline), each None when NULL.
type Shape = (Option<Point>, Option<Vec<Option<String>>>, Option<Vec<Option<Point>>>);

fn shape(row: &mut oracledb::Row) -> R<Shape> {
    let center: Option<DbObject> = row.take("CENTER")?;
    let tags: Option<DbCollection> = row.take("TAGS")?;
    let outline: Option<DbCollection> = row.take("OUTLINE")?;
    Ok((
        center.as_ref().map(point).transpose()?,
        tags.map(strings).transpose()?,
        outline.map(points).transpose()?,
    ))
}

fn shape_by_id(c: &Connection, id: i64) -> R<Shape> {
    let mut row = c.query_row("select center, tags, outline from fx_shapes where id = :1", &[&id])?;
    shape(&mut row)
}

/// The single value of a one-row, one-column query, taken as T.
fn scalar_query<T: for<'a> oracledb::FromDbValue<'a>>(c: &Connection, sql: &str, binds: &[&dyn ToDbValue]) -> R<T> {
    let mut row = c.query_row(sql, binds)?;
    Ok(row.take(0)?)
}

/// An Anonymous block with one REF CURSOR OUT bind `:c`.
fn cursor_out(c: &Connection, plsql: &str, binds: &[(&str, &dyn ToDbValue)]) -> R<Cursor> {
    let mut all: Vec<(&str, &dyn ToDbValue)> = binds.to_vec();
    all.push(("c", &DB_TYPE_CURSOR));
    let mut r = c.execute_named(plsql, &all)?;
    Ok(r.out_bind_data().take("c")?)
}

/// The single value of the first row of a Cursor, taken as T.
fn first_value<T: for<'a> oracledb::FromDbValue<'a>>(mut cur: Cursor) -> R<T> {
    let mut row = cur.next().ok_or("no rows")??;
    Ok(row.take(0)?)
}

/// A one-scalar-OUT Anonymous block (`:o`, NUMBER).
fn number_out(c: &Connection, plsql: &str, binds: &[(&str, &dyn ToDbValue)]) -> R<Option<f64>> {
    let mut all: Vec<(&str, &dyn ToDbValue)> = binds.to_vec();
    all.push(("o", &DB_TYPE_NUMBER));
    let mut r = c.execute_named(plsql, &all)?;
    Ok(r.out_bind_data().get("o")?)
}

/// Type metadata as (fqn, is_collection, attribute names or element type).
fn describe(t: std::sync::Arc<oracledb::DbObjectType>) -> (String, bool, Vec<String>) {
    let parts = if t.is_collection() {
        t.element_metadata().map(|m| vec![m.data_type()]).unwrap_or_default()
    } else {
        t.attributes().iter().map(|m| m.name().to_string()).collect()
    };
    (t.fqn(), t.is_collection(), parts)
}

const SHAPE1: &str = r#"(Some((Some(1.5), Some(-2.0), Some("center"))), Some([Some("red"), Some("café")]), Some([Some((Some(0.0), Some(0.0), Some("a"))), Some((Some(1.0), Some(0.0), Some("b"))), Some((Some(0.0), Some(1.0), None))]))"#;

/// IN workaround: a JSON array of numbers becomes an fx_number_list in
/// PL/SQL. A NULL bind stays a NULL Collection; "[]" an empty one.
const JSON_NUMBERS: &str = "declare
  l fx_number_list;
begin
  if :j is not null then
    select n bulk collect into l
      from json_table(:j, '$[*]' columns (n number path '$'));
  end if;
  :o := fx_object.sum_numbers(l);
end;";

fn main() -> R<()> {
    let c = connect()?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };

    println!("== Fetch: object and Collection columns (fx_shapes) ==");
    t.check("F1", "row 1: object, VARRAY with multibyte, nested table of objects with a NULL attribute", SHAPE1, shape_by_id(&c, 1));
    t.check("F2", "row 2: every column NULL", "(None, None, None)", shape_by_id(&c, 2));
    t.check("F3", "row 3: object with NULL attributes, empty VARRAY, empty nested table", "(Some((None, None, None)), Some([]), Some([]))", shape_by_id(&c, 3));
    t.check("F4", "all rows in one query (type cache reused)", "3", (|| {
        let cur = c.query("select center, tags, outline from fx_shapes order by id", &[])?;
        let mut n = 0;
        for row in cur {
            shape(&mut row?)?;
            n += 1;
        }
        Ok(n)
    })());
    t.check(
        "F5",
        "column metadata: object types of CENTER, TAGS, OUTLINE",
        r#"[("RAJSKA.FX_POINT", false, ["X", "Y", "LABEL"]), ("RAJSKA.FX_STRING_VARRAY", true, ["VARCHAR2(100)"]), ("RAJSKA.FX_POINT_LIST", true, ["OBJECT"])]"#,
        (|| {
            let cur = c.query("select center, tags, outline from fx_shapes where 1 = 0", &[])?;
            cur.columns()
                .iter()
                .map(|m| Ok(describe(m.object_type()?.ok_or("no type")?)))
                .collect::<R<Vec<_>>>()
        })(),
    );

    println!("== Fetch: function results and nested values ==");
    t.check("N1", "object returned by a function in SQL", r#"(Some(1.5), Some(-2.0), Some("x"))"#, (|| {
        let o: DbObject = scalar_query(&c, "select fx_object.make_point(1.5, -2, 'x') from dual", &[])?;
        point(&o)
    })());
    t.check(
        "N2",
        "object with a Collection of objects (make_polygon 4): name, vertices",
        r#"(Some("sq"), Some([Some((Some(1.0), Some(0.0), Some("v1"))), Some((Some(0.0), Some(1.0), Some("v2"))), Some((Some(-1.0), Some(0.0), Some("v3"))), Some((Some(0.0), Some(-1.0), Some("v4")))]))"#,
        (|| polygon(scalar_query(&c, "select fx_object.make_polygon('sq', 4) from dual", &[])?))(),
    );
    t.check("N3", "VARRAY with NULL elements (split_csv 'a,,b,')", r#"[Some("a"), None, Some("b"), None]"#, (|| {
        strings(scalar_query(&c, "select fx_object.split_csv('a,,b,') from dual", &[])?)
    })());
    t.check("N4", "VARRAY limit: split_csv with 11 parts surfaces ORA-06532", "true", (|| {
        let r: R<DbCollection> = scalar_query(&c, "select fx_object.split_csv('1,2,3,4,5,6,7,8,9,10,11') from dual", &[]);
        Ok(r.err().map(|e| e.to_string().contains("ORA-06532")).unwrap_or(false))
    })());
    t.check("N5", "connection usable after N4", "Some(6.0)", (|| {
        let v: Option<f64> = scalar_query(&c, "select fx_object.sum_numbers(fx_number_list(1, 2, 3)) from dual", &[])?;
        Ok(v)
    })());
    t.check("N6", "nested table of 10000 numbers (long image length): count, sum", "(10000, 50005000.0)", (|| {
        let v = numbers(scalar_query(
            &c,
            "select cast(multiset(select level from dual connect by level <= 10000) as fx_number_list) from dual",
            &[],
        )?)?;
        Ok((v.len(), v.iter().map(|x| x.unwrap_or(0.0)).sum::<f64>()))
    })());
    t.check("N7", "1000 rows of object values: rows, sum of y, last label", r#"(1000, 333833500.0, Some("P1000"))"#, (|| {
        let cur = c.query("select fx_point(level, level * level, 'P' || level) from dual connect by level <= 1000", &[])?;
        let (mut n, mut sum, mut last) = (0, 0.0, None);
        for row in cur {
            let o: DbObject = row?.take(0)?;
            let p = point(&o)?;
            n += 1;
            sum += p.1.unwrap_or(0.0);
            last = p.2;
        }
        Ok((n, sum, last))
    })());
    t.check("N8", "object and Collection columns inside a REF CURSOR (fx_shapes row 1)", SHAPE1, (|| {
        let cur = cursor_out(&c, "begin open :c for select center, tags, outline from fx_shapes where id = 1; end;", &[])?;
        let mut cur = cur;
        let mut row = cur.next().ok_or("no rows")??;
        shape(&mut row)
    })());

    println!("== SELECT ... FROM TABLE(pipelined_fn()) ==");
    t.check("T1", "fx_pipe.numbers(:n = 1000): rows, sum", "(1000, 500500.0)", (|| {
        let cur = c.query("select column_value from table(fx_pipe.numbers(:1))", &[&1000i64])?;
        let (mut n, mut sum) = (0, 0.0);
        for row in cur {
            sum += row?.get::<f64>(0)?;
            n += 1;
        }
        Ok((n, sum))
    })());
    t.check("T2", "fx_pipe.points(:n = 1000) as columns: names, rows, sum of y, last row", r#"("X,Y,LABEL", 1000, 333833500.0, (1000.0, 1000000.0, "P1000"))"#, (|| {
        let cur = c.query("select x, y, label from table(fx_pipe.points(:1))", &[&1000i64])?;
        let cols = cur.columns().iter().map(|m| m.name().to_string()).collect::<Vec<_>>().join(",");
        let (mut n, mut sum, mut last) = (0, 0.0, (0.0, 0.0, String::new()));
        for row in cur {
            let row = row?;
            last = (row.get("X")?, row.get("Y")?, row.get("LABEL")?);
            sum += last.1;
            n += 1;
        }
        Ok((cols, n, sum, last))
    })());
    t.check("T3", "fx_pipe.points(0): no rows, columns kept", r#"("X,Y,LABEL", 0)"#, (|| {
        let cur = c.query("select x, y, label from table(fx_pipe.points(:1))", &[&0i64])?;
        let cols = cur.columns().iter().map(|m| m.name().to_string()).collect::<Vec<_>>().join(",");
        Ok((cols, cur.count()))
    })());
    t.check("T4", "value(t) of a pipelined object row (an object column)", r#"[Some((Some(1.0), Some(1.0), Some("P1"))), Some((Some(2.0), Some(4.0), Some("P2")))]"#, (|| {
        let cur = c.query("select value(t) from table(fx_pipe.points(2)) t", &[])?;
        cur.map(|r| {
            let o: DbObject = r?.take(0)?;
            Ok(Some(point(&o)?))
        })
        .collect::<R<Vec<_>>>()
    })());
    t.check("T5", "pipelined query inside a REF CURSOR: rows, sum", "(5, 15.0)", (|| {
        let cur = cursor_out(&c, "begin open :c for select column_value from table(fx_pipe.numbers(5)); end;", &[])?;
        let (mut n, mut sum) = (0, 0.0);
        for row in cur {
            sum += row?.get::<f64>(0)?;
            n += 1;
        }
        Ok((n, sum))
    })());

    println!("== Binds: objects, Collections and Associative arrays ==");
    t.check(
        "B1",
        "get_type: object, nested table, VARRAY",
        r#"[("RAJSKA.FX_POINT", false, ["X", "Y", "LABEL"]), ("RAJSKA.FX_NUMBER_LIST", true, ["NUMBER"]), ("RAJSKA.FX_STRING_VARRAY", true, ["VARCHAR2(100)"])]"#,
        (|| {
            ["FX_POINT", "FX_NUMBER_LIST", "FX_STRING_VARRAY"]
                .iter()
                .map(|n| Ok(describe(c.get_type(n)?)))
                .collect::<R<Vec<_>>>()
        })(),
    );
    t.observe("B2", "get_type of a package Associative array type FX_OBJECT.T_NUMBER_MAP", (|| Ok(describe(c.get_type("FX_OBJECT.T_NUMBER_MAP")?)))());
    t.observe("B3", "OUT object bound as the bare DB_TYPE_OBJECT (no type attached)", (|| {
        let mut r = c.execute_named("begin :o := fx_object.make_point(1, 2, 'a'); end;", &[("o", &DB_TYPE_OBJECT)])?;
        let o: DbObject = r.out_bind_data().take("o")?;
        point(&o)
    })());
    t.check("B4", "connection usable after B3", "Some(3.0)", number_out(&c, "begin :o := fx_object.sum_numbers(fx_number_list(1, 2)); end;", &[]));
    t.observe("B5", "OUT Collection bound as the bare DB_TYPE_OBJECT", (|| {
        let mut r = c.execute_named("begin fx_object.reverse_numbers(fx_number_list(1, 2, 3), :o); end;", &[("o", &DB_TYPE_OBJECT)])?;
        numbers(r.out_bind_data().take("o")?)
    })());
    // B6 and B7 kill their connection, so they run on a throwaway one.
    let d = connect()?;
    t.observe("B6", "Collection IN bound as a NULL DB_TYPE_OBJECT into TABLE(:ids) (throwaway connection)", (|| {
        let cur = d.query("select id from fx_items where id in (select column_value from table(:1))", &[&DB_TYPE_OBJECT])?;
        Ok(cur.count())
    })());
    t.observe("B7", "Associative array OUT bound as the bare DB_TYPE_OBJECT (fresh throwaway connection)", (|| {
        let d = connect()?;
        let mut r = d.execute_named("begin fx_object.fill_map(3, :o); end;", &[("o", &DB_TYPE_OBJECT)])?;
        numbers(r.out_bind_data().take("o")?)
    })());
    t.observe("B8", "throwaway connection after B6", number_out(&d, "begin :o := 1; end;", &[]));
    t.observe("B10", "3 malformed binds (B3) on one connection, then a plain Call", (|| {
        let d = connect()?;
        let bad = || d.execute_named("begin :o := fx_object.make_point(1, 2, 'a'); end;", &[("o", &DB_TYPE_OBJECT)]).err().map(|e| one_line(&e.to_string())[..9].to_string());
        let errs = vec![bad(), bad(), bad()];
        let after = number_out(&d, "begin :o := 1; end;", &[]).map_err(|e| one_line(&e.to_string()));
        Ok((errs, after))
    })());
    t.check("B9", "main connection usable after B5-B7", "Some(3.0)", number_out(&c, "begin :o := fx_object.sum_numbers(fx_number_list(1, 2)); end;", &[]));

    println!("== Workarounds: JSON in, REF CURSOR out ==");
    t.check("W1", "TABLE(:ids) via JSON_TABLE over a string bind", "[1, 5, 999, 1000]", (|| {
        let cur = c.query(
            "select id from fx_items where id in (select id from json_table(:1, '$[*]' columns (id number path '$'))) order by id",
            &[&"[1, 5, 999, 1000, 2000]"],
        )?;
        cur.map(|r| Ok(r?.get::<i64>(0)?)).collect::<R<Vec<_>>>()
    })());
    t.check("W2", "JSON_TABLE with 1000 ids (a 4893-byte string bind): rows, sum", "(1000, 500500)", (|| {
        let ids = format!("[{}]", (1..=1000).map(|i| i.to_string()).collect::<Vec<_>>().join(","));
        let cur = c.query(
            "select id from fx_items where id in (select id from json_table(:1, '$[*]' columns (id number path '$')))",
            &[&ids],
        )?;
        let v = cur.map(|r| Ok(r?.get::<i64>(0)?)).collect::<R<Vec<_>>>()?;
        Ok((v.len(), v.iter().sum::<i64>()))
    })());
    t.observe("W3", "JSON_TABLE with 10000 ids (a 48894-byte string bind, past 32767): rows", (|| {
        let ids = format!("[{}]", (1..=10000).map(|i| i.to_string()).collect::<Vec<_>>().join(","));
        let cur = c.query(
            "select id from fx_items where id in (select id from json_table(:1, '$[*]' columns (id number path '$')))",
            &[&ids],
        )?;
        Ok(cur.count())
    })());
    t.check("W3b", "JSON_TABLE with 10000 ids bound as a JsonValue array (DB_TYPE_JSON, OSON): rows", "1000", (|| {
        let ids = oracledb::JsonValue::JsonArray((1..=10000).map(|i: i64| oracledb::JsonValue::Number(i.into())).collect());
        let cur = c.query(
            "select id from fx_items where id in (select id from json_table(:1, '$[*]' columns (id number path '$')))",
            &[&ids],
        )?;
        Ok(cur.count())
    })());
    t.check("W4", "IN nested table via JSON: sum_numbers '[1, 2, 3.5]', '[]', NULL", "(Some(6.5), Some(0.0), None)", (|| {
        let none: Option<String> = None;
        Ok((
            number_out(&c, JSON_NUMBERS, &[("j", &"[1, 2, 3.5]")])?,
            number_out(&c, JSON_NUMBERS, &[("j", &"[]")])?,
            number_out(&c, JSON_NUMBERS, &[("j", &none)])?,
        ))
    })());
    t.observe("W5", "IN object via JSON_VALUE ... RETURNING fx_polygon (nested Collection)", number_out(
        &c,
        "begin :o := fx_object.count_vertices(json_value(:j, '$' returning fx_polygon)); end;",
        &[("j", &r#"{"NAME": "tri", "VERTICES": [{"X": 0, "Y": 0, "LABEL": "a"}, {"X": 1, "Y": 0}, {"X": 0, "Y": 1, "LABEL": "café"}]}"#)],
    ));
    t.check("W6", "OUT Collection via REF CURSOR: reverse_numbers of JSON '[1, 2, 3]'", "[Some(3.0), Some(2.0), Some(1.0)]", (|| {
        let cur = cursor_out(
            &c,
            "declare l fx_number_list; r fx_number_list;
             begin
               select n bulk collect into l from json_table(:j, '$[*]' columns (n number path '$'));
               fx_object.reverse_numbers(l, r);
               open :c for select r from dual;
             end;",
            &[("j", &"[1, 2, 3]")],
        )?;
        numbers(first_value(cur)?)
    })());
    t.observe("W7", "IN OUT object: move_point of a JSON point by (10, 20), read back via REF CURSOR", (|| {
        let cur = cursor_out(
            &c,
            "declare p fx_point := json_value(:j, '$' returning fx_point);
             begin
               fx_object.move_point(p, 10, 20);
               open :c for select p from dual;
             end;",
            &[("j", &r#"{"X": 1, "Y": 2, "LABEL": "ação"}"#)],
        )?;
        let o: DbObject = first_value(cur)?;
        point(&o)
    })());
    t.check("W8", "IN OUT VARRAY: append_tag 'x' to NULL and to ['red', 'café']", r#"([Some("x")], [Some("red"), Some("café"), Some("x")])"#, (|| {
        let plsql = "declare v fx_string_varray;
             begin
               if :j is not null then
                 select s bulk collect into v from json_table(:j, '$[*]' columns (s varchar2(100) path '$'));
               end if;
               fx_object.append_tag(v, 'x');
               open :c for select v from dual;
             end;";
        let none: Option<String> = None;
        let a = strings(first_value(cursor_out(&c, plsql, &[("j", &none)])?)?)?;
        let b = strings(first_value(cursor_out(&c, plsql, &[("j", &r#"["red", "café"]"#)])?)?)?;
        Ok((a, b))
    })());
    t.check("W9", "OUT object as JSON text: json_object(make_polygon('tri', 3)) into a VARCHAR2 OUT", r#"Some("{\"NAME\":\"tri\",\"VERTICES\":[{\"X\":1,\"Y\":0,\"LABEL\":\"v1\"},{\"X\":-0.5,\"Y\":0.866025,\"LABEL\":\"v2\"},{\"X\":-0.5,\"Y\":-0.866025,\"LABEL\":\"v3\"}]}")"#, (|| {
        let mut r = c.execute_named(
            "declare p fx_polygon := fx_object.make_polygon('tri', 3);
             begin select json_object(p) into :o from dual; end;",
            &[("o", &DB_TYPE_VARCHAR)],
        )?;
        let s: Option<String> = r.out_bind_data().get("o")?;
        Ok(s)
    })());
    t.check("A1", "Associative array IN via JSON: sum_map of '[1, 2, 3.5]' (keys 1..n)", "Some(6.5)", number_out(
        &c,
        "declare l fx_number_list; m fx_object.t_number_map;
         begin
           select n bulk collect into l from json_table(:j, '$[*]' columns (n number path '$'));
           for i in 1 .. l.count loop m(i) := l(i); end loop;
           :o := fx_object.sum_map(m);
         end;",
        &[("j", &"[1, 2, 3.5]")],
    ));
    t.check("A2", "Associative array OUT as (key, value) rows via a REF CURSOR: fill_map(3)", "[(10, 1), (20, 2), (30, 3)]", (|| {
        let cur = cursor_out(
            &c,
            "declare m fx_object.t_number_map; k pls_integer; ks fx_number_list := fx_number_list(); vs fx_number_list := fx_number_list();
             begin
               fx_object.fill_map(3, m);
               k := m.first;
               while k is not null loop
                 ks.extend; ks(ks.count) := k; vs.extend; vs(vs.count) := m(k);
                 k := m.next(k);
               end loop;
               open :c for select k.column_value as k, v.column_value as v
                 from (select rownum n, column_value from table(ks)) k
                 join (select rownum n, column_value from table(vs)) v on v.n = k.n
                 order by k.n;
             end;",
            &[],
        )?;
        cur.map(|r| {
            let r = r?;
            Ok((r.get::<i64>("K")?, r.get::<i64>("V")?))
        })
        .collect::<R<Vec<_>>>()
    })());
    t.observe("A3", "TABLE() over a package Associative array in a REF CURSOR (12c+ feature)", (|| {
        let cur = cursor_out(
            &c,
            "declare m fx_object.t_number_map; begin fx_object.fill_map(3, m); open :c for select * from table(m); end;",
            &[],
        )?;
        let cols = cur.columns().iter().map(|m| m.name().to_string()).collect::<Vec<_>>().join(",");
        let vals = cur.map(|r| Ok(r?.get::<i64>(0)?)).collect::<R<Vec<_>>>()?;
        Ok((cols, vals))
    })());

    println!("== Cleanup ==");
    t.check("L1", "500 REF CURSORs with object columns dropped unread, then a read", SHAPE1, (|| {
        for _ in 0..500 {
            let _ = cursor_out(&c, "begin open :c for select center, tags, outline from fx_shapes; end;", &[])?;
        }
        shape_by_id(&c, 1)
    })());

    println!(
        "\n{} pass, {} fail, {} err, {} observed",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
