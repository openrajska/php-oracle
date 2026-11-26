//! Binds, read from the `$binds` array as Core passes them: keyed by
//! placeholder name, holding plain PHP values (spec #32 §7).
//!
//! Named only: keys are placeholder names without `:`, matched to the SQL's
//! placeholders case-insensitively. Plain values bind by inference:
//!
//! | PHP                         | Binds as                                  |
//! |-----------------------------|-------------------------------------------|
//! | `int`                       | NUMBER                                    |
//! | `float`                     | NUMBER from its shortest round-trip text  |
//! | `string` ≤ 32767 bytes      | VARCHAR2                                  |
//! | `string` > 32767 bytes      | CLOB, through a temporary LOB             |
//! | `bool`                      | BOOLEAN                                   |
//! | `null`                      | NULL VARCHAR2                             |
//! | `BackedEnum`                | its backing value, inferred again         |
//! | `Stringable`                | VARCHAR2                                  |
//!
//! Anything else, and every naming mistake, is a `usage` error raised before
//! any Round trip, naming the fix. Messages name the bind, never its value.
//!
//! A typed bind (`Rajska\Oracle\Binds\Bind`) binds as its declared type (see
//! [`crate::typed`]).
//!
//! A CLOB, NCLOB or BLOB value is written into a temporary LOB by
//! [`Binds::write_lobs`], after every check that needs no Round trip (see
//! [`crate::temp_lob`]).

use std::cell::Cell;
use std::collections::HashMap;
use std::rc::Rc;
use std::sync::Arc;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendObject, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, ce};
use oracledb::{DB_TYPE_CLOB, DbType, ExecResult, OracleNumber, Row, ToDbValue};

use crate::connection::ConnState;
use crate::error::Error;
use crate::input::entries;
use crate::object::Describe;
use crate::options::Options;
use crate::temp_lob::{TempLob, Written};
use crate::temporal::Temporal;
use crate::typed::{self, Typed};
use crate::value::cell;

/// Core's Cursor and the native one: passing either back is later.
const CORE_CURSOR_CLASS: &str = "Rajska\\Oracle\\Statement\\Cursor";
const NATIVE_CURSOR_CLASS: &str = "Rajska\\Oracle\\Extension\\Cursor";

/// The longest string that binds as VARCHAR2 (PL/SQL's limit).
pub const MAX_VARCHAR2: usize = 32767;

/// One bind's value, as the crate takes it.
pub enum Value {
    /// NULL as VARCHAR2.
    Null(Option<String>),
    /// NULL of another type: a NULL in an Array DML column of that type.
    NullOf(&'static DbType),
    Int(i64),
    Number(OracleNumber),
    Text(String),
    Bool(bool),
    /// A string over 32767 bytes, as a CLOB.
    Clob(TempLob),
    Typed(Typed),
}

impl Value {
    pub fn as_db_value(&self) -> &dyn ToDbValue {
        match self {
            Value::Null(v) => v,
            Value::NullOf(v) => v,
            Value::Int(v) => v,
            Value::Number(v) => v,
            Value::Text(v) => v,
            Value::Bool(v) => v,
            Value::Clob(v) => v.as_db_value(),
            Value::Typed(v) => v.as_db_value(),
        }
    }

    /// Whether the user reads this bind back through `out()`.
    pub fn is_out(&self) -> bool {
        matches!(self, Value::Typed(typed) if typed.is_out())
    }

    /// The timestamp type a typed bind declared.
    fn reads_as(&self) -> Option<Temporal> {
        match self {
            Value::Typed(typed) => typed.reads_as(),
            _ => None,
        }
    }
}

/// Owned bind values, alive for the whole execute, each under the name the
/// user wrote.
pub struct Binds(pub Vec<(Name, Value)>);

/// A bind's name, shared by the rows of an Array DML call that write it the same.
pub type Name = Rc<str>;

/// Up to this many binds, names are compared by a scan instead of a map.
const FEW: usize = 16;

/// Whether two names are the same ignoring case, as their uppercase forms
/// compare, without building them.
pub fn same_name(a: &str, b: &str) -> bool {
    if a.is_ascii() && b.is_ascii() {
        a.eq_ignore_ascii_case(b)
    } else {
        a.chars()
            .flat_map(char::to_uppercase)
            .eq(b.chars().flat_map(char::to_uppercase))
    }
}

/// Which placeholder each bind matched, in the order of the binds.
pub struct Matched {
    placeholders: usize,
    found: Vec<usize>,
}

impl Binds {
    /// Reads every bind: its name, then its value by inference. `describe`
    /// describes the type an Object or Collection bind names.
    pub fn read(binds: &ZendHashTable, describe: &Describe<'_>) -> Result<Self, Error> {
        Self::read_with(binds, describe, &Classes::default(), None)
    }

    /// [`Binds::read`], with the class entries already looked up and the
    /// binds of an earlier row, `like` (the rows of an Array DML call share both):
    /// a name written as `like` writes it at the same position is shared,
    /// and checked already.
    pub fn read_with(
        binds: &ZendHashTable,
        describe: &Describe<'_>,
        classes: &Classes,
        like: Option<&Binds>,
    ) -> Result<Self, Error> {
        let mut values: Vec<(Name, Value)> = Vec::with_capacity(binds.len());
        // Few binds: a scan, with no allocation; many: a map.
        let mut seen: Option<HashMap<String, usize>> =
            (binds.len() > FEW).then(|| HashMap::with_capacity(binds.len()));
        // Every name so far was `like`'s: none differs only in case.
        let mut as_like = true;
        for (position, (key, zval)) in entries(binds).enumerate() {
            let shared = like
                .and_then(|like| like.0.get(position))
                .filter(|(name, _)| matches!(key, ArrayKey::Str(key) if key == &**name))
                .map(|(name, _)| Rc::clone(name));
            as_like &= shared.is_some();
            let name = match shared {
                Some(name) => name,
                None => Name::from(self::name(&key)?),
            };
            let other = match &mut seen {
                Some(seen) => seen.insert(name.to_uppercase(), values.len()),
                None if as_like => None,
                None => values.iter().position(|(other, _)| same_name(other, &name)),
            };
            if let Some(other) = other {
                let other = &values[other].0;
                return Err(Error::usage(format!(
                    "Binds '{other}' and '{name}' differ only in case; placeholder names are case-insensitive, so keep one of them."
                )));
            }
            let value = infer(&name, zval, describe, classes)?;
            values.push((name, value));
        }
        Ok(Self(values))
    }

    /// Matches the binds to the statement's placeholders
    /// (`Statement::bind_names()`), ignoring case. An extra or a missing
    /// bind fails here, before any Round trip.
    pub fn matched(&self, placeholders: &[String]) -> Result<Matched, Error> {
        let mut found = Vec::with_capacity(self.0.len());
        for (name, _) in &self.0 {
            // The last of placeholders that differ only in case, as a map
            // keyed by the uppercase name would keep.
            let Some(placeholder) = placeholders.iter().rposition(|p| same_name(p, name)) else {
                return Err(Error::usage(extra(name, placeholders)));
            };
            found.push(placeholder);
        }
        if found.len() < placeholders.len()
            && let Some(missing) = placeholders
                .iter()
                .find(|p| !self.0.iter().any(|(name, _)| same_name(name, p)))
        {
            return Err(Error::usage(format!(
                "Placeholder :{missing} has no bind; add it to the binds, keyed '{}'.",
                missing.to_lowercase()
            )));
        }
        Ok(Matched {
            placeholders: placeholders.len(),
            found,
        })
    }

    /// The values by name, as the crate's named execute takes them: each
    /// under its placeholder's name, quoted so that the crate takes it
    /// exactly as the SQL wrote it.
    pub fn named(
        &self,
        matched: &Matched,
        placeholders: &[String],
    ) -> Vec<(String, &dyn ToDbValue)> {
        self.0
            .iter()
            .zip(&matched.found)
            .map(|((_, value), &placeholder)| {
                (
                    format!("\"{}\"", placeholders[placeholder]),
                    value.as_db_value(),
                )
            })
            .collect()
    }

    /// The values in the order of the statement's placeholders, as the
    /// crate's positional execute takes them, when every placeholder has a
    /// bind of its own (a PL/SQL block names each placeholder once): what
    /// the crate's named execute would bind, without its matching.
    pub fn in_order(&self, matched: &Matched) -> Option<Vec<&dyn ToDbValue>> {
        let count = matched.placeholders;
        if self.0.len() != count {
            return None;
        }
        let mut values: Vec<Option<&dyn ToDbValue>> = vec![None; count];
        for ((_, value), &placeholder) in self.0.iter().zip(&matched.found) {
            values[placeholder] = Some(value.as_db_value());
        }
        values.into_iter().collect()
    }

    /// The values in the order of the statement's placeholders
    /// (`Statement::bind_names()`), for a positional execute. The binds must
    /// have passed [`Binds::matched`].
    pub fn positional(&self, placeholders: &[String]) -> Vec<&dyn ToDbValue> {
        placeholders
            .iter()
            .filter_map(|placeholder| {
                self.0
                    .iter()
                    .find(|(name, _)| same_name(name, placeholder))
                    .map(|(_, value)| value.as_db_value())
            })
            .collect()
    }

    /// Writes every CLOB, NCLOB and BLOB value into a temporary LOB of the
    /// Session, so it binds: Round trips, so only once the statement passed
    /// every other check.
    pub fn write_lobs(&mut self, state: &ConnState) -> Result<(), Error> {
        for (name, value) in &mut self.0 {
            let temp = match value {
                Value::Clob(temp) => Some(temp),
                Value::Typed(typed) => typed.temp_lob(),
                _ => None,
            };
            if let Some(temp) = temp {
                temp.write(state, name)?;
            }
        }
        Ok(())
    }

    /// Takes the temporary LOBs out of the binds, once the execute is
    /// done with them, registered with the Connection so that its Release
    /// frees them; `None` when there is none.
    pub fn take_written(&mut self, state: &ConnState) -> Option<Arc<Written>> {
        Written::keep(state, self.take_lobs())
    }

    /// Takes the temporary LOBs out of the binds, once the execute is done
    /// with them.
    pub fn take_lobs(&mut self) -> Vec<oracledb::Lob> {
        self.0
            .iter_mut()
            .filter_map(|(_, value)| match value {
                Value::Clob(temp) => temp.take(),
                Value::Typed(typed) => typed.temp_lob().and_then(TempLob::take),
                _ => None,
            })
            .collect()
    }

    /// The OUT and IN OUT values, keyed by the name the user wrote. Each is
    /// the list of values the bind produced: one for a PL/SQL block, one per
    /// returned row for `RETURNING`, none for anything else. IN binds have no
    /// entry. A REF CURSOR becomes a native Cursor of `state`, with
    /// `options`.
    pub fn out_values(
        &self,
        result: Option<&mut ExecResult>,
        is_plsql: bool,
        state: &Arc<ConnState>,
        options: &Options,
    ) -> Result<ZBox<ZendHashTable>, Error> {
        let mut out = ZendHashTable::new();
        if !self.has_out() {
            return Ok(out);
        }
        let mut rows: Vec<Row> = match result {
            Some(result) if is_plsql => vec![result.out_bind_data()],
            Some(result) => result.returned_data(),
            None => Vec::new(),
        };
        for (name, value) in self.0.iter().filter(|(_, value)| value.is_out()) {
            let values = out_list(&mut rows, name, value, state, options)?;
            out.insert(&**name, values).map_err(put)?;
        }
        Ok(out)
    }

    pub fn has_out(&self) -> bool {
        self.0.iter().any(|(_, value)| value.is_out())
    }
}

/// A ZendHashTable insert or push failed: it does not, for these values.
pub fn put(e: ext_php_rs::error::Error) -> Error {
    Error::unsupported(e.to_string())
}

/// The list of values the OUT bind `name` produced, one per row of `rows`.
pub fn out_list(
    rows: &mut [Row],
    name: &str,
    bind: &Value,
    state: &Arc<ConnState>,
    options: &Options,
) -> Result<ZBox<ZendHashTable>, Error> {
    let what = format!("OUT bind '{name}'");
    let index = rows.first().and_then(|row| {
        row.columns()
            .iter()
            .position(|column| same_name(column.name(), name))
    });
    let mut values = ZendHashTable::with_capacity(u32::try_from(rows.len()).unwrap_or(0));
    for row in rows {
        // A bind the block only reads sends nothing back.
        let value = match index {
            Some(index) => cell(row, index, state, options, &what, bind.reads_as())?,
            None => Zval::null(),
        };
        values.push(value).map_err(put)?;
    }
    Ok(values)
}

/// A bind's name: its key, which must be a placeholder name without `:`.
fn name(key: &ArrayKey<'_>) -> Result<String, Error> {
    if let ArrayKey::Long(index) = key {
        return Err(Error::usage(format!(
            "Bind keys are placeholder names, but key {index} is an integer (is the array a list?); key every value by its placeholder name without ':'. Positional and '?' placeholders are not accepted."
        )));
    }
    let name = match key {
        ArrayKey::Str(key) => (*key).to_owned(),
        key => key.to_string(),
    };
    if let Some(bare) = name.strip_prefix(':') {
        return Err(Error::usage(format!(
            "Bind ':{bare}': write the key without ':', as '{bare}'."
        )));
    }
    Ok(name)
}

fn extra(name: &str, placeholders: &[String]) -> String {
    if placeholders.is_empty() {
        format!(
            "Bind '{name}' matches no placeholder: the SQL has none. Placeholders are written ':name'; '?' is not accepted."
        )
    } else {
        let list: Vec<String> = placeholders.iter().map(|p| format!(":{p}")).collect();
        format!(
            "Bind '{name}' matches no placeholder in the SQL; its placeholders are {}.",
            list.join(", ")
        )
    }
}

/// The class a bind's object is checked against first, looked up by name
/// once per read of the binds rather than once per object: a lookup
/// lowercases and hashes the name. Only a class found is kept.
#[derive(Default)]
pub struct Classes {
    bind: Cell<Option<&'static ClassEntry>>,
}

impl Classes {
    fn bind(&self) -> Option<&'static ClassEntry> {
        if self.bind.get().is_none() {
            self.bind.set(ClassEntry::try_find(typed::BIND_CLASS));
        }
        self.bind.get()
    }
}

/// Infers how a plain PHP value binds (spec #32 §7).
fn infer(
    name: &str,
    zval: &Zval,
    describe: &Describe<'_>,
    classes: &Classes,
) -> Result<Value, Error> {
    let zval = zval.dereference();
    if zval.is_null() {
        Ok(Value::Null(None))
    } else if let Some(v) = zval.long() {
        Ok(Value::Int(v))
    } else if let Some(v) = zval.double() {
        float(name, v)
    } else if let Some(v) = zval.bool() {
        Ok(Value::Bool(v))
    } else if zval.is_string() {
        text(name, zval)
    } else if let Some(object) = zval.object() {
        object_value(name, object, describe, classes)
    } else if zval.is_array() {
        Err(Error::usage(format!(
            "Bind '{name}': an array does not bind by itself; use In\\Json or a typed bind."
        )))
    } else {
        Err(Error::usage(format!(
            "Bind '{name}': a {} does not bind by itself; use In\\Clob or In\\Blob for a stream, or a typed bind.",
            zval.get_type()
        )))
    }
}

/// A PHP string, which is bytes: VARCHAR2 when it is UTF-8 text, CLOB over
/// 32767 bytes.
fn text(name: &str, zval: &Zval) -> Result<Value, Error> {
    let Some(text) = zval.str() else {
        return Err(Error::usage(format!(
            "Bind '{name}': the string is not valid UTF-8; bind binary data with In\\Raw or In\\Blob."
        )));
    };
    if text.len() > MAX_VARCHAR2 {
        return Ok(Value::Clob(TempLob::of(DB_TYPE_CLOB, zval)));
    }
    Ok(Value::Text(text.to_string()))
}

/// A float as an exact NUMBER: the decimal its shortest round-trip text
/// writes, so `0.1` stores `0.1` (C4: the crate would bind BINARY_DOUBLE).
fn float(name: &str, value: f64) -> Result<Value, Error> {
    exact_number(name, value).map(Value::Number)
}

/// A float as the NUMBER its shortest round-trip text writes (C4).
pub fn exact_number(name: &str, value: f64) -> Result<OracleNumber, Error> {
    exact(value).ok_or_else(|| out_of_number_range(name))
}

/// A float as the NUMBER its shortest round-trip text writes (C4), or
/// `None` when NUMBER cannot hold it.
pub fn exact(value: f64) -> Option<OracleNumber> {
    if value == 0.0 {
        // -0.0 too: NUMBER has no negative zero.
        return Some(OracleNumber::from(0));
    }
    // Rust's float formatting writes the shortest text that round-trips.
    let exponent = format!("{value:e}")
        .rsplit_once('e')
        .and_then(|(_, e)| e.parse::<i32>().ok());
    // NUMBER holds 1e-130 <= |x| < 1e126; NaN and ±INF have no exponent.
    match exponent {
        Some(e) if value.is_finite() && (-130..=125).contains(&e) => {
            format!("{value}").parse().ok()
        }
        _ => None,
    }
}

fn out_of_number_range(name: &str) -> Error {
    Error::usage(format!(
        "Bind '{name}': this float is outside NUMBER's range (NaN, INF, or beyond 1e-130..1e126); bind it with In\\BinaryDouble."
    ))
}

/// An object: a `BackedEnum` or a `Stringable`. `DateTimeInterface` is
/// checked first, as Carbon is `Stringable` too.
fn object_value(
    name: &str,
    object: &ZendObject,
    describe: &Describe<'_>,
    classes: &Classes,
) -> Result<Value, Error> {
    let is = |class: &str| ClassEntry::try_find(class).is_some_and(|ce| object.instance_of(ce));
    if classes.bind().is_some_and(|ce| object.instance_of(ce)) {
        return typed::read(name, object, describe).map(Value::Typed);
    }
    if is(CORE_CURSOR_CLASS) || is(NATIVE_CURSOR_CLASS) {
        return Err(typed::cursor_in(name));
    }
    if is("DateTimeInterface") {
        return Err(Error::usage(format!(
            "Bind '{name}': a DateTimeInterface does not bind by itself; use In\\Date or In\\Timestamp."
        )));
    }
    if is("BackedEnum") {
        // Read from the property table: get_property() reads for write,
        // which a readonly property refuses.
        let backing = object
            .get_properties()
            .ok()
            .and_then(|properties| properties.get("value"))
            .ok_or_else(|| {
                Error::usage(format!("Bind '{name}': the enum has no backing value."))
            })?;
        return infer(name, backing, describe, classes);
    }
    if is("JsonSerializable") {
        return Err(Error::usage(format!(
            "Bind '{name}': a JsonSerializable does not bind by itself; use In\\Json."
        )));
    }
    if object.instance_of(ce::stringable()) {
        let string = object
            .try_call_method("__toString", vec![])
            .map_err(|e| Error::usage(format!("Bind '{name}': __toString() failed: {e}.")))?;
        if ExecutorGlobals::has_exception() {
            // __toString() threw: that exception goes to the caller as is.
            return Err(Error::php_exception());
        }
        return text(name, &string);
    }
    let class = object
        .get_class_name()
        .unwrap_or_else(|_| "object".to_string());
    Err(Error::usage(format!(
        "Bind '{name}': an object of class {class} does not bind by itself; use In\\Json or a typed bind."
    )))
}
