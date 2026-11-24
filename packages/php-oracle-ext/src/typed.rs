//! Typed binds: `Rajska\Oracle\Bind\Bind` objects, read through the base's
//! properties only (`type`, `direction`, `value`, `typeName`; spec #32 §7,
//! §14).
//!
//! An OUT or IN OUT text or RAW bind declares the largest buffer, 32767
//! bytes. The size travels in the bind metadata only (C5 / C6), and an OUT
//! bind sends NULL.

use ext_php_rs::types::{ZendHashTable, ZendObject, Zval};
use oracledb::{
    BindValue, DB_TYPE_CHAR, DB_TYPE_CURSOR, DB_TYPE_NCHAR, DB_TYPE_NVARCHAR, DB_TYPE_OBJECT,
    DB_TYPE_RAW, DB_TYPE_VARCHAR, DbType, OracleNumber, ToDbValue,
};

use crate::bind::{MAX_VARCHAR2, exact_number};
use crate::error::Error;
use crate::object::{self, Describe};
use crate::temporal::{self, Temporal};

/// The base class every typed bind extends.
pub const BIND_CLASS: &str = "Rajska\\Oracle\\Bind\\Bind";

/// The buffer an OUT or IN OUT text or RAW bind declares: PL/SQL's largest.
const OUT_SIZE: usize = MAX_VARCHAR2;

/// A typed bind: its value as the crate takes it, and its direction.
pub struct Typed {
    value: Box<dyn ToDbValue>,
    direction: Direction,
    /// The timestamp type declared, which may travel as another type.
    reads_as: Option<Temporal>,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Direction {
    In,
    Out,
    InOut,
}

impl Typed {
    pub fn as_db_value(&self) -> &dyn ToDbValue {
        self.value.as_ref()
    }

    /// Whether the user reads it back (OUT or IN OUT).
    pub fn is_out(&self) -> bool {
        self.direction != Direction::In
    }

    /// The timestamp type declared: its OUT value maps as that type,
    /// whatever type the server sent it back as.
    pub fn reads_as(&self) -> Option<Temporal> {
        self.reads_as
    }

    /// Whether it is an Object or a Collection (`Struct`, `Collection`,
    /// `Indexed`).
    pub fn is_object(&self) -> bool {
        self.value.db_type() == DB_TYPE_OBJECT
    }
}

/// Reads a typed bind. `describe` describes the type a `Struct`,
/// `Collection` or `Indexed` bind names.
pub fn read(name: &str, object: &ZendObject, describe: &Describe<'_>) -> Result<Typed, Error> {
    let properties = object
        .get_properties()
        .map_err(|e| Error::unsupported(format!("Bind '{name}': {e}.")))?;
    let type_name = backing(name, properties, "type")?;
    let direction = match backing(name, properties, "direction")?.as_str() {
        "in" => Direction::In,
        "out" => Direction::Out,
        "inOut" => Direction::InOut,
        other => {
            return Err(Error::usage(format!(
                "Bind '{name}': unknown direction '{other}'."
            )));
        }
    };
    let null = Zval::null();
    let value = properties.get("value").unwrap_or(&null).dereference();
    // OUT sends NULL: the value is not read.
    let value = if direction == Direction::Out {
        &null
    } else {
        value
    };
    let value: Box<dyn ToDbValue> = match type_name.as_str() {
        "varchar" | "rowid" => text(name, DB_TYPE_VARCHAR, value, direction)?,
        // Fixed-length only on the way in: a CHAR OUT bind is a CHAR(32767)
        // variable, so every value would come back blank-padded to 32767
        // bytes. As VARCHAR2 a value keeps the padding it has ('AB   ' from
        // a CHAR(5)).
        "char" if direction == Direction::In => text(name, DB_TYPE_CHAR, value, direction)?,
        "char" => text(name, DB_TYPE_VARCHAR, value, direction)?,
        "nChar" if direction == Direction::In => national(name, DB_TYPE_NCHAR, value, direction)?,
        "nVarchar" | "nChar" => national(name, DB_TYPE_NVARCHAR, value, direction)?,
        "raw" => raw(name, value, direction)?,
        "number" => Box::new(number(name, value)?),
        // PLS_INTEGER travels as NUMBER; the server converts it.
        "plsInteger" => {
            Box::new(scalar(name, value, "an int", Zval::long)?.map(OracleNumber::from))
        }
        "binaryFloat" => Box::new(
            scalar(name, value, "an int or a float", float)?
                // BINARY_FLOAT: the nearest f32.
                .map(|v| v as f32),
        ),
        "binaryDouble" => Box::new(scalar(name, value, "an int or a float", float)?),
        "boolean" => Box::new(scalar(name, value, "a bool", Zval::bool)?),
        // A REF CURSOR goes as NULL, and the server opens it.
        "cursor" if value.is_null() => Box::new(DB_TYPE_CURSOR),
        "cursor" => return Err(cursor_in(name)),
        "date" | "timestamp" | "timestampTz" | "timestampLtz" => temporal::timestamp_bind(
            name,
            value,
            Temporal::of_bind(&type_name).unwrap_or(Temporal::Timestamp),
        )?,
        "intervalDs" => temporal::interval_ds_bind(name, value)?,
        "intervalYm" => temporal::interval_ym_bind(name, value)?,
        "object" | "collection" | "associativeArray" => {
            let kind =
                object::Kind::of_bind(&type_name).unwrap_or_else(|| unreachable!("matched above"));
            let type_name = properties
                .get("typeName")
                .and_then(Zval::str)
                .ok_or_else(|| Error::usage(format!("Bind '{name}': its typeName is not set.")))?;
            object::bind(name, kind, type_name, value, describe)?
        }
        other => {
            return Err(Error::unsupported(format!(
                "Bind '{name}': {} binds are not implemented yet.",
                class_name(other)
            )));
        }
    };
    Ok(Typed {
        value,
        direction,
        reads_as: Temporal::of_bind(&type_name),
    })
}

/// The backing value of an enum property (`type`, `direction`).
fn backing(name: &str, properties: &ZendHashTable, property: &str) -> Result<String, Error> {
    properties
        .get(property)
        .and_then(Zval::object)
        .and_then(|e| e.get_properties().ok())
        .and_then(|p| p.get("value"))
        .and_then(Zval::str)
        .map(str::to_string)
        .ok_or_else(|| Error::usage(format!("Bind '{name}': its {property} is not set.")))
}

/// VARCHAR2, CHAR, NVARCHAR2, NCHAR (and ROWID, sent as VARCHAR2): UTF-8
/// text of at most 32767 bytes.
fn text(
    name: &str,
    db_type: &'static DbType,
    value: &Zval,
    direction: Direction,
) -> Result<Box<dyn ToDbValue>, Error> {
    let text = text_value(name, value)?;
    Ok(Box::new(
        BindValue::new(db_type, text)?.max_size(size(direction)),
    ))
}

fn text_value(name: &str, value: &Zval) -> Result<Option<String>, Error> {
    if value.is_null() {
        return Ok(None);
    }
    let Some(text) = value.str() else {
        return Err(Error::usage(format!(
            "Bind '{name}': the string is not valid UTF-8; bind binary data with Raw::in() or Blob::in()."
        )));
    };
    if text.len() > MAX_VARCHAR2 {
        return Err(Error::usage(format!(
            "Bind '{name}': the string is over {MAX_VARCHAR2} bytes; bind it with Clob::in()."
        )));
    }
    Ok(Some(text.to_string()))
}

/// NVARCHAR2, NCHAR: like [`text`], but the size the crate declares counts
/// characters of the national character set (AL16UTF16), so the largest
/// buffer is 16383 of them: 32766 bytes, PL/SQL's limit.
fn national(
    name: &str,
    db_type: &'static DbType,
    value: &Zval,
    direction: Direction,
) -> Result<Box<dyn ToDbValue>, Error> {
    let text = text_value(name, value)?;
    let size = if direction == Direction::In {
        0
    } else {
        OUT_SIZE / 2
    };
    Ok(Box::new(BindValue::new(db_type, text)?.max_size(size)))
}

/// RAW: bytes, at most 32767.
fn raw(name: &str, value: &Zval, direction: Direction) -> Result<Box<dyn ToDbValue>, Error> {
    let bytes = if value.is_null() {
        None
    } else {
        let bytes = value
            .binary::<u8>()
            .ok_or_else(|| wrong(name, "a string"))?;
        if bytes.len() > MAX_VARCHAR2 {
            return Err(Error::usage(format!(
                "Bind '{name}': the bytes are over {MAX_VARCHAR2}; bind them with Blob::in()."
            )));
        }
        Some(bytes)
    };
    Ok(Box::new(
        BindValue::new(DB_TYPE_RAW, bytes)?.max_size(size(direction)),
    ))
}

/// NUMBER from an int, a float (exact, C4) or a decimal string.
fn number(name: &str, value: &Zval) -> Result<Option<OracleNumber>, Error> {
    if value.is_null() {
        Ok(None)
    } else if let Some(int) = value.long() {
        Ok(Some(OracleNumber::from(int)))
    } else if let Some(float) = value.double() {
        exact_number(name, float).map(Some)
    } else if let Some(text) = value.str() {
        text.parse().map(Some).map_err(|_| {
            Error::usage(format!(
                "Bind '{name}': Number::in() takes a decimal literal with '.', such as '12.30'."
            ))
        })
    } else {
        Err(wrong(name, "an int, a float or a decimal string"))
    }
}

/// A NULL, or a value `read` accepts.
fn scalar<T>(
    name: &str,
    value: &Zval,
    expected: &str,
    read: impl Fn(&Zval) -> Option<T>,
) -> Result<Option<T>, Error> {
    if value.is_null() {
        Ok(None)
    } else {
        read(value).map(Some).ok_or_else(|| wrong(name, expected))
    }
}

fn float(value: &Zval) -> Option<f64> {
    #[allow(clippy::cast_precision_loss)]
    value.double().or_else(|| value.long().map(|v| v as f64))
}

fn size(direction: Direction) -> usize {
    if direction == Direction::In {
        0
    } else {
        OUT_SIZE
    }
}

/// A Cursor passed back to the server: later (spec #32 §1).
pub fn cursor_in(name: &str) -> Error {
    Error::unsupported(format!(
        "Bind '{name}': passing a Cursor back to the server is not supported yet; bind a REF CURSOR with Cursor::out() or Cursor::inOut() and let the server open it."
    ))
}

fn wrong(name: &str, expected: &str) -> Error {
    Error::usage(format!(
        "Bind '{name}': the value must be {expected} or null."
    ))
}

/// The bind class of a `Type` backing value, for messages.
fn class_name(type_name: &str) -> String {
    match type_name {
        "object" => "Struct".to_string(),
        "associativeArray" => "Indexed".to_string(),
        other => {
            let mut chars = other.chars();
            chars
                .next()
                .map(|first| first.to_uppercase().chain(chars).collect())
                .unwrap_or_default()
        }
    }
}
