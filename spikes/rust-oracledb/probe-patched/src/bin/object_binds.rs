//! Throwaway probe for the object and Collection binds patch of the
//! rust-oracledb spike (Patch: object and Collection binds in rust-oracledb).
//!
//! Runs against the oracledb crate patched by ../patch/apply.sh
//! (object-binds.diff), which adds an image encoder, public constructors
//! (`DbObject::new` / `set`, `DbCollection::new` / `push` / `insert` /
//! `keys`), `ToDbValue` for `DbObject`, `DbCollection` and
//! `Arc<DbObjectType>` (a typed NULL / OUT placeholder), and a client-side
//! guard against a DB_TYPE_OBJECT bind without a type. Binds IN, OUT and
//! IN OUT values of the fx_object fixtures (fixtures/sql/03_object.sql).
//! Same tags as the other probes:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Run inside ddev:
//!   ddev exec bash spikes/rust-oracledb/patch/apply.sh
//!   cd spikes/rust-oracledb/probe-patched && ddev cargo run -q --bin object_binds

use oracledb::{
    Connection, DB_TYPE_NUMBER, DB_TYPE_OBJECT, DB_TYPE_VARCHAR, DbCollection, DbObject,
    DbObjectType, ToDbValue,
};
use std::fmt::Debug;
use std::sync::Arc;

type R<T> = Result<T, Box<dyn std::error::Error>>;

struct Tally {
    pass: u32,
    fail: u32,
    err: u32,
    obs: u32,
    errors: Vec<String>,
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
                self.errors.push(e.to_string());
                println!("ERR  {id:<5} {what} => {}", one_line(&e.to_string()));
            }
        }
    }

    fn observe<T: Debug>(&mut self, id: &str, what: &str, got: R<T>) {
        self.obs += 1;
        match got {
            Ok(v) => println!("OBS  {id:<5} {what} => Ok({v:?})"),
            Err(e) => {
                self.errors.push(e.to_string());
                println!("OBS  {id:<5} {what} => Err({})", one_line(&e.to_string()))
            }
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

fn read_point(o: &DbObject) -> R<Point> {
    Ok((o.get("X")?, o.get("Y")?, o.get("LABEL")?))
}

fn read_points(c: DbCollection) -> R<Vec<Option<Point>>> {
    let v: Vec<Option<DbObject>> = c.try_into()?;
    v.iter().map(|o| o.as_ref().map(read_point).transpose()).collect()
}

fn read_polygon(mut o: DbObject) -> R<(Option<String>, Option<Vec<Option<Point>>>)> {
    let name: Option<String> = o.get("NAME")?;
    let vertices: Option<DbCollection> = o.take("VERTICES")?;
    Ok((name, vertices.map(read_points).transpose()?))
}

/// An associative array as (keys, values).
fn read_map(c: DbCollection) -> R<(Vec<i32>, Vec<Option<f64>>)> {
    let keys = c.keys().ok_or("not an associative array")?.to_vec();
    Ok((keys, c.try_into()?))
}

struct Types {
    point: Arc<DbObjectType>,
    point_list: Arc<DbObjectType>,
    polygon: Arc<DbObjectType>,
    numbers: Arc<DbObjectType>,
    strings: Arc<DbObjectType>,
    map: Arc<DbObjectType>,
}

impl Types {
    fn get(c: &Connection) -> R<Self> {
        Ok(Self {
            point: c.get_type("FX_POINT")?,
            point_list: c.get_type("FX_POINT_LIST")?,
            polygon: c.get_type("FX_POLYGON")?,
            numbers: c.get_type("FX_NUMBER_LIST")?,
            strings: c.get_type("FX_STRING_VARRAY")?,
            map: c.get_type("FX_OBJECT.T_NUMBER_MAP")?,
        })
    }

    fn point(&self, x: Option<f64>, y: Option<f64>, label: Option<&str>) -> R<DbObject> {
        let mut p = DbObject::new(&self.point)?;
        p.set("X", x)?;
        p.set("Y", y)?;
        p.set("LABEL", label)?;
        Ok(p)
    }

    /// A polygon whose vertices are (i, i * i, "P<i>") for i in 1..=n.
    fn polygon(&self, name: &str, n: usize) -> R<DbObject> {
        let mut vertices = DbCollection::new(&self.point_list)?;
        for i in 1..=n {
            let label = format!("P{i}");
            vertices.push(self.point(Some(i as f64), Some((i * i) as f64), Some(&label))?)?;
        }
        let mut p = DbObject::new(&self.polygon)?;
        p.set("NAME", name)?;
        p.set("VERTICES", vertices)?;
        Ok(p)
    }

    fn numbers(&self, values: &[Option<f64>]) -> R<DbCollection> {
        let mut c = DbCollection::new(&self.numbers)?;
        for v in values {
            c.push(*v)?;
        }
        Ok(c)
    }

    fn strings(&self, values: &[Option<&str>]) -> R<DbCollection> {
        let mut c = DbCollection::new(&self.strings)?;
        for v in values {
            c.push(*v)?;
        }
        Ok(c)
    }

    fn map(&self, entries: &[(i32, f64)]) -> R<DbCollection> {
        let mut c = DbCollection::new(&self.map)?;
        for (k, v) in entries {
            c.insert(*k, *v)?;
        }
        Ok(c)
    }
}

/// An Anonymous block with one NUMBER OUT bind `:o`.
fn number_out(c: &Connection, plsql: &str, binds: &[(&str, &dyn ToDbValue)]) -> R<Option<f64>> {
    let mut all: Vec<(&str, &dyn ToDbValue)> = binds.to_vec();
    all.push(("o", &DB_TYPE_NUMBER));
    let mut r = c.execute_named(plsql, &all)?;
    Ok(r.out_bind_data().get("o")?)
}

/// An Anonymous block with one VARCHAR2 OUT bind `:o`.
fn string_out(c: &Connection, plsql: &str, binds: &[(&str, &dyn ToDbValue)]) -> R<Option<String>> {
    let mut all: Vec<(&str, &dyn ToDbValue)> = binds.to_vec();
    all.push(("o", &DB_TYPE_VARCHAR));
    let mut r = c.execute_named(plsql, &all)?;
    Ok(r.out_bind_data().get("o")?)
}

/// An Anonymous block whose bind `name` is IN OUT or OUT; returns its value.
fn object_out<T: for<'a> oracledb::FromDbValue<'a>>(
    c: &Connection,
    plsql: &str,
    binds: &[(&str, &dyn ToDbValue)],
    name: &str,
) -> R<T> {
    let mut r = c.execute_named(plsql, binds)?;
    Ok(r.out_bind_data().take(name)?)
}

const MAP_TO_TEXT: &str = "declare
  m fx_object.t_number_map := :m;
  k pls_integer := m.first;
  s varchar2(4000);
begin
  while k is not null loop
    s := s || k || '=' || m(k) || ',';
    k := m.next(k);
  end loop;
  :o := s;
end;";

const POINT_TO_TEXT: &str = "declare
  p fx_point := :p;
begin
  if p is null then
    :o := 'atomic null';
  else
    :o := nvl(to_char(p.x), '-') || '|' || nvl(to_char(p.y), '-') || '|' || nvl(p.label, '-');
  end if;
end;";

fn main() -> R<()> {
    let c = connect()?;
    let ty = Types::get(&c)?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0, errors: Vec::new() };

    println!("== Building values on the client ==");
    t.check("C1", "DbObject::new(fx_point) then set X, Y, LABEL by name; read back with get", "(Some(1.5), Some(-2.0), Some(\"ação\"))", (|| {
        read_point(&ty.point(Some(1.5), Some(-2.0), Some("ação"))?)
    })());
    t.observe("C2", "DbObject::set with the wrong value type (a string into X NUMBER)", (|| {
        let mut p = DbObject::new(&ty.point)?;
        Ok(p.set("X", "abc")?)
    })());
    t.observe("C3", "DbCollection::push of an fx_point into fx_number_list", (|| {
        let mut l = DbCollection::new(&ty.numbers)?;
        Ok(l.push(ty.point(None, None, None)?)?)
    })());
    t.observe("C4", "DbCollection::new of an object type / DbObject::new of a Collection type", (|| {
        let a = DbCollection::new(&ty.point).err().map(|e| e.to_string());
        let b = DbObject::new(&ty.numbers).err().map(|e| e.to_string());
        Ok((a, b))
    })());
    t.check("C5", "associative array insert out of order, with a replaced key: keys sorted", "([-5, 10, 20], [Some(3.5), Some(1.0), Some(7.0)])", (|| {
        let mut m = ty.map(&[(20, 2.0), (-5, 3.5), (10, 1.0)])?;
        m.insert(20, 7.0)?;
        read_map(m)
    })());

    println!("== IN ==");
    t.check("I1", "IN fx_point (multibyte label) read inside PL/SQL", "Some(\"1.5|-2|ação\")", (|| {
        string_out(&c, POINT_TO_TEXT, &[("p", &ty.point(Some(1.5), Some(-2.0), Some("ação"))?)])
    })());
    t.check("I2", "IN fx_point with every attribute NULL", "Some(\"-|-|-\")", (|| {
        string_out(&c, POINT_TO_TEXT, &[("p", &ty.point(None, None, None)?)])
    })());
    t.check("I3", "IN NULL fx_point (the type handle as the value)", "Some(\"atomic null\")", (|| {
        string_out(&c, POINT_TO_TEXT, &[("p", &ty.point)])
    })());
    t.check("I4", "IN fx_number_list: sum_numbers [1, 2, 3.5], [], NULL", "(Some(6.5), Some(0.0), None)", (|| {
        let sql = "begin :o := fx_object.sum_numbers(:l); end;";
        Ok((
            number_out(&c, sql, &[("l", &ty.numbers(&[Some(1.0), Some(2.0), Some(3.5)])?)])?,
            number_out(&c, sql, &[("l", &ty.numbers(&[])?)])?,
            number_out(&c, sql, &[("l", &ty.numbers)])?,
        ))
    })());
    t.check("I5", "IN fx_number_list with a NULL element: count, count of non-NULL", "Some(\"3/2\")", (|| {
        string_out(&c, "declare l fx_number_list := :l; n pls_integer := 0; begin for i in 1 .. l.count loop if l(i) is not null then n := n + 1; end if; end loop; :o := l.count || '/' || n; end;",
            &[("l", &ty.numbers(&[Some(1.0), None, Some(3.0)])?)])
    })());
    t.check("I6", "IN fx_string_varray ['red', 'café', NULL]: elements joined", "Some(\"red,café,<null>,\")", (|| {
        string_out(&c, "declare v fx_string_varray := :v; s varchar2(400); begin for i in 1 .. v.count loop s := s || nvl(v(i), '<null>') || ','; end loop; :o := s; end;",
            &[("v", &ty.strings(&[Some("red"), Some("café"), None])?)])
    })());
    t.observe("I7", "IN fx_string_varray with 11 elements (limit 10)", (|| {
        let v: Vec<Option<&str>> = (0..11).map(|_| Some("x")).collect();
        number_out(&c, "declare v fx_string_varray := :v; begin :o := v.count; end;", &[("v", &ty.strings(&v)?)])
    })());
    t.check("I8", "connection usable after I7", "Some(1.0)", number_out(&c, "begin :o := 1; end;", &[]));
    t.check("I9", "IN fx_polygon (nested Collection of 3 points): count_vertices", "Some(3.0)", (|| {
        number_out(&c, "begin :o := fx_object.count_vertices(:p); end;", &[("p", &ty.polygon("tri", 3)?)])
    })());
    t.check("I10", "IN fx_polygon with NULL vertices / with a NULL vertex inside", "(None, Some(\"3|<null>\"))", (|| {
        let mut p = DbObject::new(&ty.polygon)?;
        p.set("NAME", "empty")?;
        let a = number_out(&c, "begin :o := fx_object.count_vertices(:p); end;", &[("p", &p)])?;
        let mut v = DbCollection::new(&ty.point_list)?;
        v.push(ty.point(Some(1.0), Some(1.0), Some("a"))?)?;
        v.push(None::<DbObject>)?;
        v.push(ty.point(Some(2.0), Some(2.0), None)?)?;
        let mut q = DbObject::new(&ty.polygon)?;
        q.set("NAME", "holes")?;
        q.set("VERTICES", v)?;
        let b = string_out(&c, "declare p fx_polygon := :p; begin :o := p.vertices.count || '|' || case when p.vertices(2) is null then '<null>' else 'set' end; end;", &[("p", &q)])?;
        Ok((a, b))
    })());
    t.check("I11", "IN fx_polygon with 1000 vertices (image past 245 bytes and TTC chunks): count, sum of y", "Some(\"1000|333833500\")", (|| {
        string_out(&c, "declare p fx_polygon := :p; s number := 0; begin for i in 1 .. p.vertices.count loop s := s + p.vertices(i).y; end loop; :o := p.vertices.count || '|' || s; end;",
            &[("p", &ty.polygon("big", 1000)?)])
    })());
    t.check("I12", "IN associative array {10: 1, 20: 2, -5: 3.5}: sum_map and keys in order", "(Some(6.5), Some(\"-5=3.5,10=1,20=2,\"))", (|| {
        let m = ty.map(&[(10, 1.0), (20, 2.0), (-5, 3.5)])?;
        Ok((
            number_out(&c, "begin :o := fx_object.sum_map(:m); end;", &[("m", &m)])?,
            string_out(&c, MAP_TO_TEXT, &[("m", &m)])?,
        ))
    })());
    t.check("I13", "IN empty associative array: sum_map", "Some(0.0)", (|| {
        number_out(&c, "begin :o := fx_object.sum_map(:m); end;", &[("m", &ty.map(&[])?)])
    })());

    println!("== TABLE(:ids) in SQL ==");
    t.check("T1", "select ... from table(:ids) with [1, 5, 999]", "[1, 5, 999]", (|| {
        let ids = ty.numbers(&[Some(1.0), Some(5.0), Some(999.0)])?;
        let rows = c.query("select column_value from table(:1) order by 1", &[&ids])?;
        let mut out = Vec::new();
        for row in rows {
            out.push(row?.get::<i64>(0)?);
        }
        Ok(out)
    })());
    t.check("T2", "table(:ids) with 10,000 ids: count, sum", "(10000, 50005000)", (|| {
        let v: Vec<Option<f64>> = (1..=10_000).map(|i| Some(i as f64)).collect();
        let ids = ty.numbers(&v)?;
        let row = c.query_row("select count(*), sum(column_value) from table(:1)", &[&ids])?;
        Ok((row.get::<i64>(0)?, row.get::<i64>(1)?))
    })());
    t.check("T3", "join on table(:ids) with 10,000 ids against fx_bench_rows (10k rows): matches", "10000", (|| {
        let v: Vec<Option<f64>> = (1..=10_000).map(|i| Some(i as f64)).collect();
        let ids = ty.numbers(&v)?;
        let row = c.query_row("select count(*) from fx_bench_rows b join table(:1) t on b.id = t.column_value", &[&ids])?;
        Ok(row.get::<i64>(0)?)
    })());
    t.check("T4", "table(:ids) with an empty / NULL Collection: count", "(0, 0)", (|| {
        let a = c.query_row("select count(*) from table(:1)", &[&ty.numbers(&[])?])?.get::<i64>(0)?;
        let b = c.query_row("select count(*) from table(:1)", &[&ty.numbers])?.get::<i64>(0)?;
        Ok((a, b))
    })());

    println!("== OUT ==");
    t.check("O1", "OUT fx_point from make_point(1.5, -2, 'ação')", "(Some(1.5), Some(-2.0), Some(\"ação\"))", (|| {
        let o: DbObject = object_out(&c, "begin :o := fx_object.make_point(1.5, -2, 'ação'); end;", &[("o", &ty.point)], "o")?;
        read_point(&o)
    })());
    t.check("O2", "OUT fx_polygon from make_polygon('sq', 4)", "(Some(\"sq\"), Some([Some((Some(1.0), Some(0.0), Some(\"v1\"))), Some((Some(0.0), Some(1.0), Some(\"v2\"))), Some((Some(-1.0), Some(0.0), Some(\"v3\"))), Some((Some(0.0), Some(-1.0), Some(\"v4\")))]))", (|| {
        let o: DbObject = object_out(&c, "begin :o := fx_object.make_polygon('sq', 4); end;", &[("o", &ty.polygon)], "o")?;
        read_polygon(o)
    })());
    t.check("O3", "OUT fx_number_list from reverse_numbers([1, 2, 3]) / reverse_numbers(NULL)", "(Some([Some(3.0), Some(2.0), Some(1.0)]), None)", (|| {
        let sql = "begin fx_object.reverse_numbers(:i, :o); end;";
        let a: Option<DbCollection> = object_out(&c, sql, &[("i", &ty.numbers(&[Some(1.0), Some(2.0), Some(3.0)])?), ("o", &ty.numbers)], "o")?;
        let b: Option<DbCollection> = object_out(&c, sql, &[("i", &ty.numbers), ("o", &ty.numbers)], "o")?;
        let a: Option<Vec<Option<f64>>> = a.map(|c| c.try_into()).transpose()?;
        let b: Option<Vec<Option<f64>>> = b.map(|c| c.try_into()).transpose()?;
        Ok((a, b))
    })());
    t.check("O4", "OUT fx_string_varray from split_csv('a,,b,')", "[Some(\"a\"), None, Some(\"b\"), None]", (|| {
        let o: DbCollection = object_out(&c, "begin :o := fx_object.split_csv('a,,b,'); end;", &[("o", &ty.strings)], "o")?;
        let v: Vec<Option<String>> = o.try_into()?;
        Ok(v)
    })());
    t.check("O5", "OUT associative array from fill_map(3): keys, values", "([10, 20, 30], [Some(1.0), Some(2.0), Some(3.0)])", (|| {
        let o: DbCollection = object_out(&c, "begin fx_object.fill_map(3, :o); end;", &[("o", &ty.map)], "o")?;
        read_map(o)
    })());
    t.check("O6", "OUT associative array from fill_map(0): empty", "([], [])", (|| {
        let o: DbCollection = object_out(&c, "begin fx_object.fill_map(0, :o); end;", &[("o", &ty.map)], "o")?;
        read_map(o)
    })());
    t.check("O7", "OUT fx_point set to NULL / OUT fx_point_list set to an empty Collection", "(None, Some(0))", (|| {
        let a: Option<DbObject> = object_out(&c, "begin :o := null; end;", &[("o", &ty.point)], "o")?;
        let b: Option<DbCollection> = object_out(&c, "begin :o := fx_point_list(); end;", &[("o", &ty.point_list)], "o")?;
        Ok((a.map(|_| ()), b.map(|c| c.len())))
    })());
    t.check("O8", "OUT fx_number_list of 10,000 elements: count, sum", "(10000, 50005000.0)", (|| {
        let o: DbCollection = object_out(&c, "declare l fx_number_list := fx_number_list(); begin l.extend(10000); for i in 1 .. 10000 loop l(i) := i; end loop; :o := l; end;", &[("o", &ty.numbers)], "o")?;
        let v: Vec<Option<f64>> = o.try_into()?;
        Ok((v.len(), v.iter().flatten().sum::<f64>()))
    })());

    println!("== IN OUT ==");
    t.check("X1", "IN OUT fx_point: move_point((1, 2, 'ação'), 10, 20)", "(Some(11.0), Some(22.0), Some(\"ação\"))", (|| {
        let o: DbObject = object_out(&c, "begin fx_object.move_point(:p, 10, 20); end;", &[("p", &ty.point(Some(1.0), Some(2.0), Some("ação"))?)], "p")?;
        read_point(&o)
    })());
    t.check("X2", "IN OUT fx_string_varray: append_tag 'x' to NULL and to ['red', 'café']", "([Some(\"x\")], [Some(\"red\"), Some(\"café\"), Some(\"x\")])", (|| {
        let sql = "begin fx_object.append_tag(:t, 'x'); end;";
        let a: DbCollection = object_out(&c, sql, &[("t", &ty.strings)], "t")?;
        let b: DbCollection = object_out(&c, sql, &[("t", &ty.strings(&[Some("red"), Some("café")])?)], "t")?;
        let a: Vec<Option<String>> = a.try_into()?;
        let b: Vec<Option<String>> = b.try_into()?;
        Ok((a, b))
    })());
    t.check("X3", "IN OUT fx_polygon: append a vertex inside PL/SQL", "(Some(\"tri+\"), Some(4), Some((Some(9.0), Some(9.0), Some(\"new\"))))", (|| {
        let o: DbObject = object_out(&c, "declare p fx_polygon := :p; begin p.name := p.name || '+'; p.vertices.extend; p.vertices(p.vertices.count) := fx_point(9, 9, 'new'); :p := p; end;",
            &[("p", &ty.polygon("tri", 3)?)], "p")?;
        let (name, vertices) = read_polygon(o)?;
        let vertices = vertices.unwrap_or_default();
        Ok((name, Some(vertices.len()), vertices.last().cloned().flatten()))
    })());
    t.check("X4", "IN OUT associative array: {10: 1, 20: 2} then m(15) := 99, m.delete(10)", "([15, 20], [Some(99.0), Some(2.0)])", (|| {
        let o: DbCollection = object_out(&c, "declare m fx_object.t_number_map := :m; begin m(15) := 99; m.delete(10); :m := m; end;",
            &[("m", &ty.map(&[(10, 1.0), (20, 2.0)])?)], "m")?;
        read_map(o)
    })());
    t.check("X5", "fetched values re-bound: fx_shapes row 1 TAGS + push 'y', then append_tag 'z'", "[Some(\"red\"), Some(\"café\"), Some(\"y\"), Some(\"z\")]", (|| {
        let mut row = c.query_row("select tags from fx_shapes where id = 1", &[])?;
        let mut tags: DbCollection = row.take(0)?;
        tags.push("y")?;
        let o: DbCollection = object_out(&c, "begin fx_object.append_tag(:t, 'z'); end;", &[("t", &tags)], "t")?;
        let v: Vec<Option<String>> = o.try_into()?;
        Ok(v)
    })());
    t.check("X6", "fetched object re-bound after set: fx_shapes row 1 CENTER with LABEL changed, then move_point(1, 1)", "(Some(2.5), Some(-1.0), Some(\"moved\"))", (|| {
        let mut row = c.query_row("select center from fx_shapes where id = 1", &[])?;
        let mut center: DbObject = row.take(0)?;
        center.set("LABEL", "moved")?;
        let o: DbObject = object_out(&c, "begin fx_object.move_point(:p, 1, 1); end;", &[("p", &center)], "p")?;
        read_point(&o)
    })());

    println!("== Errors and robustness ==");
    t.observe("E1", "bare DB_TYPE_OBJECT OUT bind (no type): refused before the round trip", (|| {
        number_out(&c, "begin :p := fx_object.make_point(1, 2, 'x'); :o := 1; end;", &[("p", &DB_TYPE_OBJECT)])
    })());
    t.check("E2", "connection usable after E1", "Some(1.0)", number_out(&c, "begin :o := 1; end;", &[]));
    t.observe("E3", "fx_point bound where count_vertices expects fx_polygon", (|| {
        number_out(&c, "begin :o := fx_object.count_vertices(:p); end;", &[("p", &ty.point(None, None, None)?)])
    })());
    t.observe("E4", "fx_point with a 31-character label (attribute is varchar2(30)): refused by set()", (|| {
        let label = "x".repeat(31);
        string_out(&c, POINT_TO_TEXT, &[("p", &ty.point(Some(1.0), None, Some(&label))?)])
    })());
    t.check("E5", "connection usable after E3, E4", "Some(1.0)", number_out(&c, "begin :o := 1; end;", &[]));
    t.check("E6", "same statement re-executed with fx_point then fx_polygon in one bind slot (cached statement)", "(Some(\"POINT\"), Some(\"POLYGON\"))", (|| {
        let sql = "declare a anydata := anydata.convertobject(:v); begin :o := case a.gettypename() when 'RAJSKA.FX_POINT' then 'POINT' when 'RAJSKA.FX_POLYGON' then 'POLYGON' end; end;";
        let a = string_out(&c, sql, &[("v", &ty.point(None, None, None)?)])?;
        let b = string_out(&c, sql, &[("v", &ty.polygon("p", 2)?)])?;
        Ok((a, b))
    })());
    t.check("E7", "500 IN OUT fx_polygon calls (5 vertices each), then a plain call", "(500, Some(1.0))", (|| {
        let mut n = 0;
        for i in 0..500 {
            let o: DbObject = object_out(&c, "begin fx_object.move_point(:q, 0, 0); :p := :p; end;",
                &[("q", &ty.point(Some(i as f64), None, None)?), ("p", &ty.polygon("loop", 5)?)], "p")?;
            if read_polygon(o)?.1.map(|v| v.len()) == Some(5) {
                n += 1;
            }
        }
        Ok((n, number_out(&c, "begin :o := 1; end;", &[])?))
    })());
    t.check("E8", "execute_batch: 3 rows with an fx_point column (insert into fx_shapes, then rollback)", "3", (|| {
        let a = ty.point(Some(1.0), Some(1.0), Some("a"))?;
        let b = ty.point(Some(2.0), Some(2.0), Some("b"))?;
        let d = ty.point(Some(3.0), Some(3.0), Some("c"))?;
        let rows: &[&[&dyn ToDbValue]] = &[&[&901i64, &a], &[&902i64, &b], &[&903i64, &d]];
        c.execute_batch("insert into fx_shapes (id, center) values (:1, :2)", rows)?;
        let n = c.query_row("select count(*) from fx_shapes s where s.id between 901 and 903 and s.center.label is not null", &[])?.get::<i64>(0)?;
        c.rollback()?;
        Ok(n)
    })());

    let dropped = t.errors.iter().any(|e| e.contains("ORA-03137") || e.contains("ORA-03138") || e.contains("ORA-03113") || e.contains("ORA-03114"));
    t.check("S1", "no ORA-03137 / ORA-03138 / ORA-0311x in any error above", "false", Ok(dropped));

    println!(
        "== {} pass, {} fail, {} err, {} observed ==",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
