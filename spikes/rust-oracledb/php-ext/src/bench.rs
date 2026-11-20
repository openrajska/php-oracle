//! Throwaway benchmark API for the Benchmark ticket (#13) of the rust-oracledb spike.
//!
//! Just enough of a PHP API over `oracledb` to compare with oci8 on the three
//! acceptance workloads. It is not a design for Core's Driver contract.
//!
//!   $c = new Rajska\Bench\Connection($dsn, $user, $password);
//!   $c = Rajska\Bench\Connection::pooled($dsn, $user, $password, $max); // one Pool per process
//!   $out  = $c->call($sql, $in, $out, $typed);        // IN binds by name; OUT kinds: number|varchar|date
//!   $stmt = $c->query($sql, $in, $typed, $arraysize); // a query
//!   $stmt = $c->cursor($sql, $in, $name, $typed);     // a Call with one REF CURSOR OUT bind
//!   $row  = $stmt->fetch();  $rows = $stmt->fetchAll();
//!   $n    = $c->execute($sql, $in);  $c->commit();  $c->rollback();
//!
//! Two value shapes, chosen per Call:
//! - default: the same PHP values oci8 returns (NUMBER, DATE, TIMESTAMP and
//!   BINARY_DOUBLE as strings in oci8's formats, NULL as null), so the
//!   comparison with oci8 is like for like;
//! - typed: integer NUMBER columns (scale 0, precision up to 18) as int,
//!   BINARY_DOUBLE as float, DATE / TIMESTAMP as DateTimeImmutable, other
//!   NUMBERs as exact strings.
//!
//! DATE and TIMESTAMP are formatted client-side as `YYYY-MM-DD HH24:MI:SS` and
//! `YYYY-MM-DD HH24:MI:SS.FF6`: the benchmark sets the same NLS formats on the
//! oci8 Session, where OCI formats them client-side too.

use std::fmt::{Display, Write as _};
use std::sync::OnceLock;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendCallable, ZendHashTable, Zval};
use oracledb::{
    Connection, Cursor, DB_TYPE_BINARY_DOUBLE, DB_TYPE_BINARY_FLOAT, DB_TYPE_CHAR,
    DB_TYPE_CURSOR, DB_TYPE_DATE, DB_TYPE_LONG, DB_TYPE_NCHAR, DB_TYPE_NUMBER,
    DB_TYPE_NVARCHAR, DB_TYPE_TIMESTAMP, DB_TYPE_VARCHAR, Metadata, OracleNumber,
    OracleTimestamp, Pool, PoolConfig, Row, ToDbValue,
};

type R<T> = Result<T, PhpException>;

fn ex(e: impl Display) -> PhpException {
    PhpException::from_message(e.to_string().replace('\n', " "))
}

// ---------------------------------------------------------------- binds

/// An owned bind value, built from a PHP array entry.
enum Bind {
    Int(i64),
    Num(OracleNumber),
    Str(String),
    Null(Option<String>),
    Out(OutKind),
}

#[derive(Clone, Copy)]
enum OutKind {
    Number,
    Varchar,
    Date,
    Cursor,
}

impl Bind {
    fn value(&self) -> &dyn ToDbValue {
        match self {
            Bind::Int(v) => v,
            Bind::Num(v) => v,
            Bind::Str(v) => v,
            Bind::Null(v) => v,
            Bind::Out(OutKind::Number) => &DB_TYPE_NUMBER,
            Bind::Out(OutKind::Varchar) => &DB_TYPE_VARCHAR,
            Bind::Out(OutKind::Date) => &DB_TYPE_DATE,
            Bind::Out(OutKind::Cursor) => &DB_TYPE_CURSOR,
        }
    }
}

fn in_binds(ht: Option<&ZendHashTable>, out: &mut Vec<(String, Bind)>) -> R<()> {
    let Some(ht) = ht else { return Ok(()) };
    for (k, z) in ht.iter() {
        let b = if let Some(v) = z.long() {
            Bind::Int(v)
        } else if let Some(v) = z.double() {
            // A PHP float binds as its shortest decimal text (spike workaround:
            // an f64 bind is a BINARY_DOUBLE).
            Bind::Num(v.to_string().parse().map_err(ex)?)
        } else if let Some(v) = z.str() {
            Bind::Str(v.to_string())
        } else if z.is_null() {
            Bind::Null(None)
        } else {
            return Err(ex(format!("unsupported IN bind type for {k}")));
        };
        out.push((k.to_string(), b));
    }
    Ok(())
}

fn out_kind(s: &str) -> R<OutKind> {
    Ok(match s {
        "number" => OutKind::Number,
        "varchar" => OutKind::Varchar,
        "date" => OutKind::Date,
        "cursor" => OutKind::Cursor,
        _ => return Err(ex(format!("unknown OUT kind {s}"))),
    })
}

fn as_params(binds: &[(String, Bind)]) -> Vec<(&str, &dyn ToDbValue)> {
    binds.iter().map(|(n, b)| (n.as_str(), b.value())).collect()
}

// ---------------------------------------------------------------- values

#[derive(Clone, Copy, PartialEq)]
enum Kind {
    /// NUMBER as text (oci8 shape, or a non-integer NUMBER when typed).
    Number,
    /// NUMBER(p, 0) with p <= 18, read as i64 when typed.
    Int,
    Double,
    Text,
    Date,
    Timestamp,
}

fn kind_of(m: &Metadata) -> R<Kind> {
    let t = m.db_type();
    Ok(if t == DB_TYPE_NUMBER {
        if m.scale() == 0 && (1..=18).contains(&m.precision()) { Kind::Int } else { Kind::Number }
    } else if t == DB_TYPE_BINARY_DOUBLE || t == DB_TYPE_BINARY_FLOAT {
        Kind::Double
    } else if t == DB_TYPE_VARCHAR
        || t == DB_TYPE_CHAR
        || t == DB_TYPE_NVARCHAR
        || t == DB_TYPE_NCHAR
        || t == DB_TYPE_LONG
    {
        Kind::Text
    } else if t == DB_TYPE_DATE {
        Kind::Date
    } else if t == DB_TYPE_TIMESTAMP {
        Kind::Timestamp
    } else {
        return Err(ex(format!("column {}: unsupported type {}", m.name(), m.data_type())));
    })
}

/// oci8 prints a NUMBER below 1 without the leading zero (`.5`, `-.5`).
fn number_text(n: &OracleNumber) -> String {
    let s = n.to_string();
    if let Some(rest) = s.strip_prefix("0.") {
        format!(".{rest}")
    } else if let Some(rest) = s.strip_prefix("-0.") {
        format!("-.{rest}")
    } else {
        s
    }
}

/// oci8 prints a BINARY_DOUBLE as OCI does: 17 significant digits, trailing
/// zeros cut down to one decimal, a signed 3-digit exponent (`7.0E+000`,
/// `1.0000000000000001E-001`), and zero as `0`.
fn double_text(v: f64) -> String {
    if v == 0.0 {
        return "0".to_string();
    }
    let s = format!("{v:.16E}");
    let (mant, exp) = s.split_once('E').unwrap_or((&s, "0"));
    let exp: i32 = exp.parse().unwrap_or(0);
    let mut mant = mant.trim_end_matches('0').to_string();
    if mant.ends_with('.') {
        mant.push('0');
    }
    format!("{mant}E{}{:03}", if exp < 0 { '-' } else { '+' }, exp.abs())
}

fn ts_text(t: &OracleTimestamp, frac: bool) -> String {
    let mut s = String::with_capacity(26);
    let _ = write!(
        s,
        "{:04}-{:02}-{:02} {:02}:{:02}:{:02}",
        t.year(),
        t.month(),
        t.day(),
        t.hour(),
        t.minute(),
        t.second()
    );
    if frac {
        let _ = write!(s, ".{:06}", t.nanoseconds() / 1000);
    }
    s
}

fn datetime(text: &str) -> R<Zval> {
    ZendCallable::try_from_name("date_create_immutable")
        .map_err(ex)?
        .try_call(vec![&text])
        .map_err(ex)
}

/// Converts column `i` of `row` into a Zval.
fn value(row: &Row, i: usize, kind: Kind, typed: bool) -> R<Zval> {
    let mut z = Zval::new();
    match (kind, typed) {
        (Kind::Int, true) => match row.get::<Option<i64>>(i).map_err(ex)? {
            Some(v) => z.set_long(v),
            None => z.set_null(),
        },
        (Kind::Number | Kind::Int, _) => match row.get::<Option<OracleNumber>>(i).map_err(ex)? {
            Some(v) => z.set_string(&number_text(&v), false).map_err(ex)?,
            None => z.set_null(),
        },
        (Kind::Double, true) => match row.get::<Option<f64>>(i).map_err(ex)? {
            Some(v) => z.set_double(v),
            None => z.set_null(),
        },
        (Kind::Double, false) => match row.get::<Option<f64>>(i).map_err(ex)? {
            Some(v) => z.set_string(&double_text(v), false).map_err(ex)?,
            None => z.set_null(),
        },
        (Kind::Text, _) => match row.get::<Option<&str>>(i).map_err(ex)? {
            Some(v) => z.set_string(v, false).map_err(ex)?,
            None => z.set_null(),
        },
        (Kind::Date | Kind::Timestamp, _) => {
            match row.get::<Option<OracleTimestamp>>(i).map_err(ex)? {
                Some(v) => {
                    let text = ts_text(&v, kind == Kind::Timestamp);
                    if typed {
                        z = datetime(&text)?;
                    } else {
                        z.set_string(&text, false).map_err(ex)?;
                    }
                }
                None => z.set_null(),
            }
        }
    }
    Ok(z)
}

// ---------------------------------------------------------------- Pool

static POOL: OnceLock<Pool> = OnceLock::new();

fn pool(dsn: &str, user: &str, password: &str, max: usize) -> R<&'static Pool> {
    if let Some(p) = POOL.get() {
        return Ok(p);
    }
    // min and increment stay at 1: above 1 the Pool never grows (rust-oracledb#97).
    let config = PoolConfig::default()
        .set_credentials(user, password)
        .set_connect_string(dsn)
        .map_err(ex)?
        .set_min_connections(1)
        .set_max_connections(max)
        .set_connection_increment(1);
    let created = oracledb::create_pool(config).map_err(ex)?;
    // Another thread may have won the race; its Pool is the one everyone uses.
    let _ = POOL.set(created);
    Ok(POOL.get().expect("pool set"))
}

// ---------------------------------------------------------------- classes

#[php_class]
#[php(name = "Rajska\\Bench\\Connection")]
pub struct BenchConnection {
    conn: Connection,
}

#[php_impl]
impl BenchConnection {
    pub fn __construct(dsn: String, user: String, password: String) -> R<Self> {
        let config = oracledb::Config::default()
            .set_credentials(&user, &password)
            .set_connect_string(&dsn)
            .map_err(ex)?;
        Ok(Self { conn: oracledb::connect(config).map_err(ex)? })
    }

    /// A Connection from the process-wide Pool (created on first use with
    /// `max` Connections). It goes back to the Pool when the object is freed.
    pub fn pooled(dsn: String, user: String, password: String, max: i64) -> R<Self> {
        let p = pool(&dsn, &user, &password, max.max(1) as usize)?;
        Ok(Self { conn: p.acquire().map_err(ex)? })
    }

    /// Runs a Call. `out` maps each OUT bind name to number|varchar|date.
    /// Returns the OUT values by name.
    pub fn call(
        &self,
        sql: String,
        r#in: Option<&ZendHashTable>,
        out: Option<&ZendHashTable>,
        typed: Option<bool>,
    ) -> R<ZBox<ZendHashTable>> {
        let typed = typed.unwrap_or(false);
        let mut binds = Vec::new();
        in_binds(r#in, &mut binds)?;
        let mut outs = Vec::new();
        if let Some(out) = out {
            for (k, z) in out.iter() {
                let kind = out_kind(z.str().unwrap_or(""))?;
                outs.push((k.to_string(), kind));
                binds.push((k.to_string(), Bind::Out(kind)));
            }
        }
        let mut res = self.conn.execute_named(&sql, &as_params(&binds)).map_err(ex)?;
        let row = res.out_bind_data();
        let mut ht = ZendHashTable::with_capacity(outs.len() as u32);
        for (name, kind) in &outs {
            let i = row
                .columns()
                .iter()
                .position(|m| m.name().eq_ignore_ascii_case(name))
                .ok_or_else(|| ex(format!("no OUT bind {name}")))?;
            let k = match kind {
                OutKind::Number => kind_of(&row.columns()[i])?,
                OutKind::Varchar => Kind::Text,
                OutKind::Date => Kind::Date,
                OutKind::Cursor => return Err(ex("use cursor() for REF CURSOR OUT binds")),
            };
            ht.insert(name.as_str(), value(&row, i, k, typed)?).map_err(ex)?;
        }
        Ok(ht)
    }

    /// Runs a query. `arraysize` > 0 sets both prefetch rows and the fetch
    /// array size; 0 or absent keeps the crate defaults.
    pub fn query(
        &self,
        sql: String,
        r#in: Option<&ZendHashTable>,
        typed: Option<bool>,
        arraysize: Option<i64>,
    ) -> R<BenchStatement> {
        let mut binds = Vec::new();
        in_binds(r#in, &mut binds)?;
        let params = as_params(&binds);
        let cur = match arraysize.unwrap_or(0) {
            n if n > 0 => self
                .conn
                .statement(&sql)
                .map_err(ex)?
                .fetch_array_size(n as u32)
                .prefetch_rows(n as u32)
                .build()
                .map_err(ex)?
                .query_named(&params)
                .map_err(ex)?,
            _ => self.conn.query_named(&sql, &params).map_err(ex)?,
        };
        BenchStatement::new(cur, typed.unwrap_or(false))
    }

    /// Runs a Call with one REF CURSOR OUT bind called `name` and returns it.
    pub fn cursor(
        &self,
        sql: String,
        r#in: Option<&ZendHashTable>,
        name: String,
        typed: Option<bool>,
    ) -> R<BenchStatement> {
        let mut binds = Vec::new();
        in_binds(r#in, &mut binds)?;
        binds.push((name.clone(), Bind::Out(OutKind::Cursor)));
        let mut res = self.conn.execute_named(&sql, &as_params(&binds)).map_err(ex)?;
        let cur: Cursor = res.out_bind_data().take(name.as_str()).map_err(ex)?;
        BenchStatement::new(cur, typed.unwrap_or(false))
    }

    /// Runs DML (or any statement without OUT binds); returns rows affected.
    /// Does not commit.
    pub fn execute(&self, sql: String, r#in: Option<&ZendHashTable>) -> R<i64> {
        let mut binds = Vec::new();
        in_binds(r#in, &mut binds)?;
        let res = self.conn.execute_named(&sql, &as_params(&binds)).map_err(ex)?;
        Ok(res.rows_affected() as i64)
    }

    pub fn commit(&self) -> R<()> {
        self.conn.commit().map_err(ex)
    }

    pub fn rollback(&self) -> R<()> {
        self.conn.rollback().map_err(ex)
    }
}

#[php_class]
#[php(name = "Rajska\\Bench\\Statement")]
pub struct BenchStatement {
    cur: Cursor,
    cols: Vec<(String, Kind)>,
    typed: bool,
}

impl BenchStatement {
    fn new(cur: Cursor, typed: bool) -> R<Self> {
        let cols = cur
            .columns()
            .iter()
            .map(|m| Ok((m.name().to_string(), kind_of(m)?)))
            .collect::<R<Vec<_>>>()?;
        Ok(Self { cur, cols, typed })
    }

    fn row_array(&self, row: &Row) -> R<ZBox<ZendHashTable>> {
        let mut ht = ZendHashTable::with_capacity(self.cols.len() as u32);
        for (i, (name, kind)) in self.cols.iter().enumerate() {
            ht.insert(name.as_str(), value(row, i, *kind, self.typed)?).map_err(ex)?;
        }
        Ok(ht)
    }
}

#[php_impl]
impl BenchStatement {
    /// The next row as an array keyed by column name, or null at the end.
    pub fn fetch(&mut self) -> R<Option<ZBox<ZendHashTable>>> {
        match self.cur.next() {
            None => Ok(None),
            Some(row) => Ok(Some(self.row_array(&row.map_err(ex)?)?)),
        }
    }

    /// Reads every remaining row without building any PHP value: the protocol
    /// and decoding cost alone, to split it from the row → zval conversion.
    pub fn count_rows(&mut self) -> R<i64> {
        let mut n = 0;
        while let Some(row) = self.cur.next() {
            row.map_err(ex)?;
            n += 1;
        }
        Ok(n)
    }

    /// Every remaining row.
    pub fn fetch_all(&mut self) -> R<ZBox<ZendHashTable>> {
        let mut all = ZendHashTable::new();
        while let Some(row) = self.cur.next() {
            all.push(self.row_array(&row.map_err(ex)?)?).map_err(ex)?;
        }
        Ok(all)
    }
}
