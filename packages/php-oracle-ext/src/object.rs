//! Objects, Collections and Associative arrays as plain PHP arrays, both
//! ways (spec #32 §7, §8).
//!
//! | Oracle                         | PHP                                      |
//! |--------------------------------|------------------------------------------|
//! | Object                         | array keyed by attribute name (`keyCase`)|
//! | Collection (nested table, VARRAY) | `list`                                |
//! | Associative array (INDEX BY)   | `array<int, mixed>`, in key order        |
//!
//! Atomically NULL is `null`, empty is `[]`, and an all-NULL Object is an
//! array of `null`s. Attributes and elements map as columns do, under the
//! same `ValueMapping`. What comes out binds back unchanged: `Struct`,
//! `Collection` and `Indexed` take the same shapes.
//!
//! A type is named by a string under Oracle's identifier rules and
//! described on first use; the crate caches the description per Session.
//! Every check on a value runs on the client, before the statement's Round
//! trip.

use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::{ArrayKey, ZendHashTable, Zval};
use oracledb::{
    DB_TYPE_BINARY_DOUBLE, DB_TYPE_BINARY_FLOAT, DB_TYPE_BINARY_INTEGER, DB_TYPE_BOOLEAN,
    DB_TYPE_CHAR, DB_TYPE_INTERVAL_DS, DB_TYPE_INTERVAL_YM, DB_TYPE_NCHAR, DB_TYPE_NUMBER,
    DB_TYPE_NVARCHAR, DB_TYPE_OBJECT, DB_TYPE_RAW, DB_TYPE_VARCHAR, DbCollection, DbObject,
    DbObjectType, ErrorKind, FromDbValue, Lob, Metadata, OracleIntervalDS, OracleIntervalYM,
    OracleNumber, OracleTimestamp, Row, ToDbValue,
};

use crate::bind::{MAX_VARCHAR2, exact};
use crate::error::{self, Error};
use crate::options::{KeyCase, Options};
use crate::pool::SessionNotes;
use crate::temporal::{self, Temporal};
use crate::value::{number_value, set_string, widen};

/// The longest identifier, in bytes, quotes excluded.
const MAX_IDENTIFIER: usize = 128;

/// Which bind class names the type: `Struct`, `Collection` or `Indexed`.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    Object,
    Collection,
    AssociativeArray,
}

impl Kind {
    /// The kind a typed bind declares (`Type`'s backing value).
    pub fn of_bind(type_name: &str) -> Option<Self> {
        match type_name {
            "object" => Some(Self::Object),
            "collection" => Some(Self::Collection),
            "associativeArray" => Some(Self::AssociativeArray),
            _ => None,
        }
    }

    fn of_type(object_type: &DbObjectType) -> Self {
        if object_type.is_associative_array() {
            Self::AssociativeArray
        } else if object_type.is_collection() {
            Self::Collection
        } else {
            Self::Object
        }
    }

    /// The glossary's name, for messages.
    fn noun(self) -> &'static str {
        match self {
            Self::Object => "an Object type",
            Self::Collection => "a Collection type (nested table or VARRAY)",
            Self::AssociativeArray => "an Associative array type (INDEX BY PLS_INTEGER)",
        }
    }

    fn class(self) -> &'static str {
        match self {
            Self::Object => "Struct",
            Self::Collection => "Collection",
            Self::AssociativeArray => "Indexed",
        }
    }
}

/// Where a value sits, for messages: built only when one is written.
#[derive(Clone, Copy)]
pub enum Path<'a> {
    /// `Bind 'x'`, `OUT bind 'x'`, `Column X`.
    Root(&'a str),
    Attribute(&'a Path<'a>, &'a str),
    Element(&'a Path<'a>, usize),
    Key(&'a Path<'a>, i64),
}

impl fmt::Display for Path<'_> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Root(root) => f.write_str(root),
            Self::Attribute(parent, name) => write!(f, "{parent}, attribute {name}"),
            Self::Element(parent, index) => write!(f, "{parent}, element {index}"),
            Self::Key(parent, key) => write!(f, "{parent}, key {key}"),
        }
    }
}

/// A type's canonical name, `HR.ADDRESS`, as `Column::$objectType` shows it.
pub fn canonical(object_type: &DbObjectType) -> String {
    match object_type.package_name() {
        Some(package) => format!("{}.{package}.{}", object_type.schema(), object_type.name()),
        None => format!("{}.{}", object_type.schema(), object_type.name()),
    }
}

// ---------------------------------------------------------------------------
// Type names and descriptions
// ---------------------------------------------------------------------------

/// A type name under Oracle's identifier rules, as the crate looks it up:
/// every part double-quoted. Unquoted parts are uppercased, quoted ones kept
/// as written; at most three parts (`SCHEMA.PACKAGE.TYPE`). The same
/// strictness as Call names: a part is a letter, then letters, digits, `_`,
/// `$` or `#`, or a double-quoted name, of at most 128 bytes.
pub fn lookup_name(what: &Path<'_>, name: &str) -> Result<String, Error> {
    let invalid = |why: &str| {
        let link = if name.contains('@') {
            " Database links (@) are not supported."
        } else {
            ""
        };
        Error::usage(format!(
            "{what}: the type name '{name}' is not valid: {why}.{link}"
        ))
    };
    let rule = "each dot-separated part must be an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes";
    let mut parts = Vec::new();
    let mut rest = name;
    loop {
        let (part, after) = if let Some(quoted) = rest.strip_prefix('"') {
            let close = quoted
                .find('"')
                .ok_or_else(|| invalid("it has an unterminated quote"))?;
            let part = &quoted[..close];
            if part.is_empty() || part.contains('\0') {
                return Err(invalid(rule));
            }
            (part.to_string(), &quoted[close + 1..])
        } else {
            let end = rest
                .char_indices()
                .find(|(i, c)| {
                    !(c.is_ascii_alphabetic()
                        || (*i > 0 && (c.is_ascii_digit() || matches!(c, '_' | '$' | '#'))))
                })
                .map_or(rest.len(), |(i, _)| i);
            if end == 0 {
                return Err(invalid(rule));
            }
            (rest[..end].to_ascii_uppercase(), &rest[end..])
        };
        if part.len() > MAX_IDENTIFIER {
            return Err(invalid(rule));
        }
        parts.push(format!("\"{part}\""));
        if after.is_empty() {
            break;
        }
        rest = after.strip_prefix('.').ok_or_else(|| invalid(rule))?;
    }
    if parts.len() > 3 {
        return Err(Error::usage(format!(
            "{what}: the type name '{name}' has {} parts; a type name has at most 3: schema.package.type.",
            parts.len()
        )));
    }
    Ok(parts.join("."))
}

/// Describes a type through the crate, whose cache keeps it for the
/// Session: Round trips only on its first use there. A type the server
/// does not know fails as a database error (`QueryException`).
///
/// The crate caches a type under the name it was asked for, and asks for
/// the type of a column (or of a REF CURSOR's column) by its quoted
/// canonical name. So a type first named otherwise (`fx_point`, a synonym)
/// is asked for by that name too, on its first use: a column of it then
/// costs no description of its own.
///
/// The crate takes an Associative array indexed by strings (`INDEX BY
/// VARCHAR2`) for a nested table, losing its keys, so a packaged Collection
/// type is checked in the dictionary once per Session (`notes`) and that
/// one is refused.
pub fn describe(
    session: &oracledb::Connection,
    notes: &SessionNotes,
    what: &Path<'_>,
    lookup: &str,
) -> Result<Arc<DbObjectType>, Error> {
    let object_type = session.get_type(lookup).map_err(|e| match e.kind() {
        ErrorKind::InvalidDbObjectTypeName(_) => Error::new(
            error::Kind::Database,
            format!(
                "{what}: the type {} does not exist, or this user cannot see it.",
                lookup.replace('"', "")
            ),
        ),
        _ => crate_error(what, e),
    })?;
    let quoted = match object_type.package_name() {
        Some(package) => format!(
            "\"{}\".\"{package}\".\"{}\"",
            object_type.schema(),
            object_type.name()
        ),
        None => format!("\"{}\".\"{}\"", object_type.schema(), object_type.name()),
    };
    if quoted != lookup {
        session
            .get_type(&quoted)
            .map_err(|e| crate_error(what, e))?;
    }
    if !object_type.is_collection() && object_type.attributes().is_empty() {
        return Err(opaque(what, &object_type));
    }
    if let Some(package) = object_type.package_name()
        && object_type.is_collection()
        && !object_type.is_associative_array()
    {
        let name = canonical(&object_type);
        let known = crate::pool::lock(&notes.string_indexed).get(&name).copied();
        let string_indexed = match known {
            Some(known) => known,
            None => {
                let row = session.query_row_named(
                    "select count(*) from all_plsql_coll_types \
                     where owner = :owner and package_name = :package \
                     and type_name = :name and index_by = 'VARCHAR2'",
                    &[
                        ("owner", &object_type.schema()),
                        ("package", &package),
                        ("name", &object_type.name()),
                    ],
                )?;
                let string_indexed = row.get::<i64>(0)? > 0;
                crate::pool::lock(&notes.string_indexed).insert(name.clone(), string_indexed);
                string_indexed
            }
        };
        if string_indexed {
            return Err(Error::unsupported(format!(
                "{what}: {name} is an Associative array indexed by strings (INDEX BY VARCHAR2), which is not supported yet; use one INDEX BY PLS_INTEGER."
            )));
        }
    }
    Ok(object_type)
}

// ---------------------------------------------------------------------------
// From PHP to Oracle
// ---------------------------------------------------------------------------

/// An attribute or element value, as the crate stores it.
enum Value {
    Null,
    Number(OracleNumber),
    Double(f64),
    Float(f32),
    Bool(bool),
    Text(String),
    Bytes(Vec<u8>),
    Timestamp(OracleTimestamp),
    IntervalDs(OracleIntervalDS),
    IntervalYm(OracleIntervalYM),
    Object(DbObject),
    Collection(DbCollection),
}

/// Stores a [`Value`] with `$store`, which takes any of the crate's
/// object values.
macro_rules! store {
    ($value:expr, |$v:ident| $store:expr) => {
        match $value {
            Value::Null => {
                let $v: Option<bool> = None;
                $store
            }
            Value::Number($v) => $store,
            Value::Double($v) => $store,
            Value::Float($v) => $store,
            Value::Bool($v) => $store,
            Value::Text($v) => $store,
            Value::Bytes($v) => $store,
            Value::Timestamp($v) => $store,
            Value::IntervalDs($v) => $store,
            Value::IntervalYm($v) => $store,
            Value::Object($v) => $store,
            Value::Collection($v) => $store,
        }
    };
}

/// Describes a type on the Connection's Session (see [`describe`]):
/// `(what, lookup name)`.
pub type Describe<'d> = dyn Fn(&Path<'_>, &str) -> Result<Arc<DbObjectType>, Error> + 'd;

/// The value of a `Struct`, `Collection` or `Indexed` bind: an Object or
/// Collection of the type `type_name` names, or a NULL of that type.
pub fn bind(
    name: &str,
    kind: Kind,
    type_name: &str,
    value: &Zval,
    describe: &Describe<'_>,
) -> Result<Box<dyn ToDbValue>, Error> {
    let root = format!("Bind '{name}'");
    let what = Path::Root(&root);
    let lookup = lookup_name(&what, type_name)?;
    let value = value.dereference();
    // What needs no description fails before it, so before any Round trip
    // even on first use.
    if !value.is_null() {
        shape(&what, kind, value)?;
    }
    let object_type = describe(&what, &lookup)?;
    let actual = Kind::of_type(&object_type);
    if actual != kind {
        return Err(Error::usage(format!(
            "{what}: {} is {}, not {}; use {}, not {}.",
            canonical(&object_type),
            actual.noun(),
            kind.noun(),
            actual.class(),
            kind.class()
        )));
    }
    if value.is_null() {
        // A NULL of the type: for IN, OUT and IN OUT alike.
        return Ok(Box::new(object_type));
    }
    Ok(match build(&what, &object_type, value)? {
        Built::Object(object) => Box::new(object),
        Built::Collection(collection) => Box::new(collection),
    })
}

/// The shape a kind of value needs, checked without the type: an array; a
/// `list` for a Collection; `int` keys in the 32-bit range for an
/// Associative array; attribute names, no two differing only in case, for
/// an Object.
fn shape(what: &Path<'_>, kind: Kind, value: &Zval) -> Result<(), Error> {
    let Some(array) = value.array() else {
        return Err(Error::usage(format!(
            "{what}: the value must be {} or null.",
            expected(kind)
        )));
    };
    match kind {
        Kind::Collection => list(what, array),
        Kind::AssociativeArray => {
            for (key, _) in array {
                index_key(what, &key)?;
            }
            Ok(())
        }
        Kind::Object => {
            let mut seen: HashMap<String, String> = HashMap::new();
            for (key, _) in array {
                let key = attribute_key(what, &key)?;
                if let Some(other) = seen.insert(key.to_uppercase(), key.to_string()) {
                    return Err(Error::usage(format!(
                        "{what}: keys '{other}' and '{key}' differ only in case and name the same attribute; keep one of them."
                    )));
                }
            }
            Ok(())
        }
    }
}

fn expected(kind: Kind) -> &'static str {
    match kind {
        Kind::Object => "an array keyed by attribute name",
        Kind::Collection => "a list",
        Kind::AssociativeArray => "an array with int keys",
    }
}

/// A Collection's value: a `list`.
fn list(what: &Path<'_>, array: &ZendHashTable) -> Result<(), Error> {
    for (position, (key, _)) in array.iter().enumerate() {
        if !matches!(key, ArrayKey::Long(index) if usize::try_from(index) == Ok(position)) {
            return Err(Error::usage(format!(
                "{what}: a Collection is a list (keys 0, 1, 2… in order), but key {} is not; pass array_values() of it, or bind an Associative array with Indexed::in().",
                key_text(&key)
            )));
        }
    }
    Ok(())
}

/// An Associative array's key: an `int` in the 32-bit signed range.
fn index_key(what: &Path<'_>, key: &ArrayKey<'_>) -> Result<i32, Error> {
    match key {
        ArrayKey::Long(index) => i32::try_from(*index).map_err(|_| {
            Error::usage(format!(
                "{what}: key {index} is outside the 32-bit range of an Associative array's keys (INDEX BY PLS_INTEGER)."
            ))
        }),
        _ => Err(Error::usage(format!(
            "{what}: an Associative array has int keys, but key {} is a string; INDEX BY VARCHAR2 is not supported yet.",
            key_text(key)
        ))),
    }
}

/// An Object's key: an attribute name.
fn attribute_key(what: &Path<'_>, key: &ArrayKey<'_>) -> Result<String, Error> {
    match key {
        ArrayKey::Long(index) => Err(Error::usage(format!(
            "{what}: an Object is keyed by attribute name, but key {index} is an int (is the array a list?)."
        ))),
        name => Ok(name.to_string()),
    }
}

fn key_text(key: &ArrayKey<'_>) -> String {
    match key {
        ArrayKey::Long(index) => index.to_string(),
        name => format!("'{name}'"),
    }
}

enum Built {
    Object(DbObject),
    Collection(DbCollection),
}

/// An Object or Collection of `object_type` from its PHP array.
fn build(what: &Path<'_>, object_type: &Arc<DbObjectType>, value: &Zval) -> Result<Built, Error> {
    let kind = Kind::of_type(object_type);
    shape(what, kind, value)?;
    let array = value
        .array()
        .unwrap_or_else(|| unreachable!("checked by shape()"));
    if kind == Kind::Object {
        let attributes = object_type.attributes();
        let mut given: Vec<Option<&Zval>> = vec![None; attributes.len()];
        for (key, value) in array {
            let key = attribute_key(what, &key)?;
            let index = attributes
                .iter()
                .position(|a| a.name() == key)
                .or_else(|| {
                    attributes
                        .iter()
                        .position(|a| a.name().eq_ignore_ascii_case(&key))
                })
                .ok_or_else(|| {
                    Error::usage(format!(
                        "{what}: {} has no attribute '{key}'; its attributes are {}.",
                        canonical(object_type),
                        names(attributes)
                    ))
                })?;
            given[index] = Some(value);
        }
        let missing: Vec<&Metadata> = attributes
            .iter()
            .zip(&given)
            .filter(|(_, value)| value.is_none())
            .map(|(attribute, _)| attribute)
            .collect();
        if !missing.is_empty() {
            return Err(Error::usage(format!(
                "{what}: the value has no key for {} {} of {}; give every attribute, null for a NULL one.",
                if missing.len() == 1 {
                    "attribute"
                } else {
                    "attributes"
                },
                missing
                    .iter()
                    .map(|a| a.name())
                    .collect::<Vec<_>>()
                    .join(", "),
                canonical(object_type)
            )));
        }
        let mut object = DbObject::new(object_type).map_err(|e| crate_error(what, e))?;
        for (index, (attribute, value)) in attributes.iter().zip(given).enumerate() {
            let path = Path::Attribute(what, attribute.name());
            let value = scalar(&path, attribute, value)?;
            store!(value, |v| object.set(index, v)).map_err(|e| crate_error(what, e))?;
        }
        Ok(Built::Object(object))
    } else {
        let element = object_type
            .element_metadata()
            .ok_or_else(|| Error::unsupported(format!("{what}: the type has no element type.")))?;
        let mut collection = DbCollection::new(object_type).map_err(|e| crate_error(what, e))?;
        for (position, (key, value)) in array.iter().enumerate() {
            if kind == Kind::AssociativeArray {
                let index = index_key(what, &key)?;
                let path = Path::Key(what, index.into());
                let value = scalar(&path, element, Some(value))?;
                store!(value, |v| collection.insert(index, v))
                    .map_err(|e| crate_error(&path, e))?;
            } else {
                let path = Path::Element(what, position);
                let value = scalar(&path, element, Some(value))?;
                store!(value, |v| collection.push(v)).map_err(|e| crate_error(&path, e))?;
            }
        }
        Ok(Built::Collection(collection))
    }
}

fn names(attributes: &[Metadata]) -> String {
    attributes
        .iter()
        .map(Metadata::name)
        .collect::<Vec<_>>()
        .join(", ")
}

/// A PHP value as the attribute or element `metadata` describes takes it:
/// the shapes reading gives (so a fetched value binds back), and a
/// `DateTimeInterface` for any timestamp type.
fn scalar(what: &Path<'_>, metadata: &Metadata, value: Option<&Zval>) -> Result<Value, Error> {
    let Some(value) = value.map(Zval::dereference).filter(|v| !v.is_null()) else {
        return Ok(Value::Null);
    };
    let db_type = metadata.db_type();
    let wrong = |expected: &str| {
        Error::usage(format!(
            "{what}: a {} holds {expected} or null, not {}.",
            metadata.data_type(),
            php_type(value)
        ))
    };
    if db_type == DB_TYPE_NUMBER {
        if let Some(int) = value.long() {
            Ok(Value::Number(OracleNumber::from(int)))
        } else if let Some(float) = value.double() {
            exact(float).map(Value::Number).ok_or_else(|| {
                Error::usage(format!(
                    "{what}: this float is outside NUMBER's range (NaN, INF, or beyond 1e-130..1e126)."
                ))
            })
        } else if let Some(text) = value.str() {
            text.parse().map(Value::Number).map_err(|_| {
                Error::usage(format!(
                    "{what}: a NUMBER takes a decimal literal with '.', such as '12.30', as text."
                ))
            })
        } else {
            Err(wrong("an int, a float or a decimal string"))
        }
    } else if db_type == DB_TYPE_BINARY_INTEGER {
        value
            .long()
            .map(|v| Value::Number(OracleNumber::from(v)))
            .ok_or_else(|| wrong("an int"))
    } else if db_type == DB_TYPE_BINARY_DOUBLE || db_type == DB_TYPE_BINARY_FLOAT {
        #[allow(clippy::cast_precision_loss)]
        let float = value
            .double()
            .or_else(|| value.long().map(|v| v as f64))
            .ok_or_else(|| wrong("an int or a float"))?;
        #[allow(clippy::cast_possible_truncation)] // BINARY_FLOAT: the nearest f32
        Ok(if db_type == DB_TYPE_BINARY_FLOAT {
            Value::Float(float as f32)
        } else {
            Value::Double(float)
        })
    } else if [
        DB_TYPE_VARCHAR,
        DB_TYPE_CHAR,
        DB_TYPE_NVARCHAR,
        DB_TYPE_NCHAR,
    ]
    .contains(&db_type)
    {
        if !value.is_string() {
            return Err(wrong("a string"));
        }
        let text = value.str().ok_or_else(|| {
            Error::usage(format!(
                "{what}: the string is not valid UTF-8; binary data goes in a RAW attribute."
            ))
        })?;
        Ok(Value::Text(text.to_string()))
    } else if db_type == DB_TYPE_RAW {
        let bytes = value
            .binary::<u8>()
            .filter(|_| value.is_string())
            .ok_or_else(|| wrong("a string of bytes"))?;
        if bytes.len() > MAX_VARCHAR2 {
            return Err(Error::usage(format!(
                "{what}: the bytes are over {MAX_VARCHAR2}."
            )));
        }
        Ok(Value::Bytes(bytes))
    } else if db_type == DB_TYPE_BOOLEAN {
        value.bool().map(Value::Bool).ok_or_else(|| wrong("a bool"))
    } else if let Some(temporal) = Temporal::of(db_type) {
        Ok(
            temporal::timestamp_of(&what.to_string(), value, temporal, true)?
                .map_or(Value::Null, Value::Timestamp),
        )
    } else if db_type == DB_TYPE_INTERVAL_DS {
        Ok(temporal::interval_ds_of(&what.to_string(), value)?
            .map_or(Value::Null, Value::IntervalDs))
    } else if db_type == DB_TYPE_INTERVAL_YM {
        Ok(temporal::interval_ym_of(&what.to_string(), value)?
            .map_or(Value::Null, Value::IntervalYm))
    } else if db_type == DB_TYPE_OBJECT {
        let object_type = metadata
            .object_type()
            .map_err(|e| crate_error(what, e))?
            .ok_or_else(|| Error::unsupported(format!("{what}: its type is not known.")))?;
        Ok(match build(what, &object_type, value)? {
            Built::Object(object) => Value::Object(object),
            Built::Collection(collection) => Value::Collection(collection),
        })
    } else {
        Err(later(what, metadata))
    }
}

/// An error of the crate about the value at `what`: the client-side ones
/// say where; a server error (or a lost Session) stays verbatim.
fn crate_error(what: &Path<'_>, e: oracledb::Error) -> Error {
    let error = Error::from(e);
    if error.code() != 0 || error.is_connection_lost() {
        error
    } else {
        error.prefixed(what)
    }
}

/// A PHP value's type, as `get_debug_type()` names it.
fn php_type(value: &Zval) -> String {
    if value.is_long() {
        "int".to_string()
    } else if value.is_double() {
        "float".to_string()
    } else if value.is_string() {
        "string".to_string()
    } else if value.is_bool() {
        "bool".to_string()
    } else if value.is_array() {
        "array".to_string()
    } else if let Some(object) = value.object() {
        object
            .get_class_name()
            .unwrap_or_else(|_| "object".to_string())
    } else {
        value.get_type().to_string()
    }
}

/// An attribute of a type this Backend cannot read or write yet.
fn later(what: &Path<'_>, metadata: &Metadata) -> Error {
    Error::unsupported(format!(
        "{what}: {} values in an Object or Collection are not supported yet.",
        metadata.data_type()
    ))
}

/// An opaque type (SYS.ANYDATA and kin): never supported (spec #32 §1).
fn opaque(what: &Path<'_>, object_type: &DbObjectType) -> Error {
    Error::unsupported(format!(
        "{what}: {} is an opaque type, which is not supported.",
        canonical(object_type)
    ))
}

// ---------------------------------------------------------------------------
// From Oracle to PHP
// ---------------------------------------------------------------------------

/// The Object or Collection at `index` of a row (a column or an OUT
/// value), taken out of it; NULL gives `null`.
pub fn cell(
    row: &mut Row,
    index: usize,
    options: &Options,
    what: &Path<'_>,
) -> Result<Zval, Error> {
    let object_type = row
        .columns()
        .get(index)
        .ok_or_else(|| Error::unsupported(format!("{what}: no value came back.")))?
        .object_type()?
        .ok_or_else(|| Error::unsupported(format!("{what}: its type is not known.")))?;
    if object_type.is_collection() {
        match row.take::<Option<DbCollection>>(index)? {
            Some(collection) => collection_value(collection, options, what),
            None => Ok(Zval::null()),
        }
    } else {
        match row.take::<Option<DbObject>>(index)? {
            Some(object) => object_value(object, options, what),
            None => Ok(Zval::null()),
        }
    }
}

/// An Object as an array keyed by attribute name, in the Cursor's
/// `keyCase`.
fn object_value(mut object: DbObject, options: &Options, what: &Path<'_>) -> Result<Zval, Error> {
    let object_type = Arc::clone(object.object_type());
    let attributes = object_type.attributes();
    // An Object type has at least one attribute: one with none is opaque
    // (SYS.ANYDATA, ANYTYPE, ANYDATASET), whose value the crate cannot read,
    // and would come back as an empty array.
    if attributes.is_empty() {
        return Err(opaque(what, &object_type));
    }
    let mut array = ZendHashTable::with_capacity(u32::try_from(attributes.len()).unwrap_or(0));
    for (index, attribute) in attributes.iter().enumerate() {
        let path = Path::Attribute(what, attribute.name());
        let value = values(Attribute(&mut object, index), attribute, options, &path)?
            .pop()
            .unwrap_or_default();
        match options.key_case {
            KeyCase::Native => array.insert(attribute.name(), value),
            KeyCase::Lower => array.insert(attribute.name().to_lowercase().as_str(), value),
        }
        .map_err(put)?;
    }
    zval(array)
}

/// A nested table or VARRAY as a `list`; an Associative array keyed by its
/// keys, in key order.
fn collection_value(
    collection: DbCollection,
    options: &Options,
    what: &Path<'_>,
) -> Result<Zval, Error> {
    let object_type = Arc::clone(collection.object_type());
    let element = object_type
        .element_metadata()
        .ok_or_else(|| Error::unsupported(format!("{what}: the type has no element type.")))?;
    let keys = collection.keys().map(<[i32]>::to_vec);
    let values = values(Elements(collection), element, options, what)?;
    let mut array = ZendHashTable::with_capacity(u32::try_from(values.len()).unwrap_or(0));
    match keys {
        Some(keys) => {
            for (key, value) in keys.into_iter().zip(values) {
                array.insert_at_index(key.into(), value).map_err(put)?;
            }
        }
        None => {
            for value in values {
                array.push(value).map_err(put)?;
            }
        }
    }
    zval(array)
}

/// Values of one Rust type taken from an Object's attribute or a
/// Collection's elements; `read` maps each non-NULL one to PHP.
trait Values {
    fn read<T>(self, read: &mut dyn FnMut(T) -> Result<Zval, Error>) -> Result<Vec<Zval>, Error>
    where
        T: for<'a> FromDbValue<'a>;
}

/// One attribute of an Object.
struct Attribute<'o>(&'o mut DbObject, usize);

impl Values for Attribute<'_> {
    fn read<T>(self, read: &mut dyn FnMut(T) -> Result<Zval, Error>) -> Result<Vec<Zval>, Error>
    where
        T: for<'a> FromDbValue<'a>,
    {
        Ok(vec![match self.0.take::<Option<T>>(self.1)? {
            Some(value) => read(value)?,
            None => Zval::null(),
        }])
    }
}

/// Every element of a Collection, in order.
struct Elements(DbCollection);

impl Values for Elements {
    fn read<T>(self, read: &mut dyn FnMut(T) -> Result<Zval, Error>) -> Result<Vec<Zval>, Error>
    where
        T: for<'a> FromDbValue<'a>,
    {
        let values: Vec<Option<T>> = self.0.try_into()?;
        values
            .into_iter()
            .map(|value| match value {
                Some(value) => read(value),
                None => Ok(Zval::null()),
            })
            .collect()
    }
}

/// The values of `source`, of the type `metadata` describes, mapped as
/// columns of that type are (spec #32 §8).
fn values(
    source: impl Values,
    metadata: &Metadata,
    options: &Options,
    what: &Path<'_>,
) -> Result<Vec<Zval>, Error> {
    let db_type = metadata.db_type();
    if db_type == DB_TYPE_NUMBER || db_type == DB_TYPE_BINARY_INTEGER {
        source.read(&mut |number: OracleNumber| {
            let mut zval = Zval::new();
            number_value(&mut zval, number, options.numbers)?;
            Ok(zval)
        })
    } else if [
        DB_TYPE_VARCHAR,
        DB_TYPE_CHAR,
        DB_TYPE_NVARCHAR,
        DB_TYPE_NCHAR,
    ]
    .contains(&db_type)
    {
        source.read(&mut |text: String| {
            let mut zval = Zval::new();
            set_string(&mut zval, &text)?;
            Ok(zval)
        })
    } else if db_type == DB_TYPE_RAW {
        source.read(&mut |bytes: Vec<u8>| {
            let mut zval = Zval::new();
            zval.set_binary(bytes);
            Ok(zval)
        })
    } else if db_type == DB_TYPE_BINARY_DOUBLE {
        source.read(&mut |v: f64| {
            let mut zval = Zval::new();
            zval.set_double(v);
            Ok(zval)
        })
    } else if db_type == DB_TYPE_BINARY_FLOAT {
        source.read(&mut |v: f32| {
            let mut zval = Zval::new();
            zval.set_double(widen(v));
            Ok(zval)
        })
    } else if db_type == DB_TYPE_BOOLEAN {
        source.read(&mut |v: bool| {
            let mut zval = Zval::new();
            zval.set_bool(v);
            Ok(zval)
        })
    } else if let Some(temporal) = Temporal::of(db_type) {
        source.read(&mut |v: OracleTimestamp| {
            temporal::timestamp_value(v, temporal, options.temporals)
        })
    } else if db_type == DB_TYPE_INTERVAL_DS {
        source.read(&mut temporal::interval_ds)
    } else if db_type == DB_TYPE_INTERVAL_YM {
        source.read(&mut temporal::interval_ym)
    } else if db_type == DB_TYPE_OBJECT {
        let object_type = metadata
            .object_type()?
            .ok_or_else(|| Error::unsupported(format!("{what}: its type is not known.")))?;
        if object_type.is_collection() {
            source.read(&mut |c: DbCollection| collection_value(c, options, what))
        } else {
            source.read(&mut |o: DbObject| object_value(o, options, what))
        }
    } else {
        // A LOB, BFILE, XMLTYPE...: NULL reads as null, a value fails.
        source
            .read(&mut |_: Lob| Err(later(what, metadata)))
            .map_err(|_| later(what, metadata))
    }
}

fn zval(array: ZBox<ZendHashTable>) -> Result<Zval, Error> {
    let mut zval = Zval::new();
    zval.set_hashtable(array);
    Ok(zval)
}

fn put(e: ext_php_rs::error::Error) -> Error {
    Error::unsupported(e.to_string())
}

#[cfg(test)]
mod tests {
    use super::{Path, lookup_name};

    fn name(name: &str) -> Result<String, String> {
        lookup_name(&Path::Root("Bind 'x'"), name).map_err(|e| e.message().to_string())
    }

    #[test]
    fn unquoted_parts_are_uppercased_and_quoted_parts_kept() {
        assert_eq!(name("hr.address"), Ok("\"HR\".\"ADDRESS\"".to_string()));
        assert_eq!(name("HR.ADDRESS"), Ok("\"HR\".\"ADDRESS\"".to_string()));
        assert_eq!(name("\"Mixed\""), Ok("\"Mixed\"".to_string()));
        assert_eq!(
            name("hr.\"Pkg\".t_x$#1"),
            Ok("\"HR\".\"Pkg\".\"T_X$#1\"".to_string())
        );
        assert_eq!(name("\"a.b\""), Ok("\"a.b\"".to_string()));
    }

    #[test]
    fn invalid_names_fail_naming_the_rule() {
        for invalid in [
            "", "1x", "a..b", "a.", ".a", "a b", "\"\"", "\"open", "a-b", "a;drop", "a.b.c.d",
            "x@link",
        ] {
            let error = name(invalid).expect_err(invalid);
            assert!(error.starts_with("Bind 'x': the type name"), "{error}");
        }
        assert!(name(&"a".repeat(129)).is_err());
        assert!(name(&"a".repeat(128)).is_ok());
        assert!(name("x@link").unwrap_err().contains("Database links"));
    }
}
