//! Native JSON values both ways (spec #32 §7, §8).
//!
//! A JSON value decodes like `json_decode($text, true)`: an object becomes
//! an array keyed by name (key order is not guaranteed, numeric keys become
//! ints), an array a `list`, and scalars follow the column rules under the
//! same `ValueMapping`:
//!
//! | JSON scalar                 | PHP                                      |
//! |-----------------------------|------------------------------------------|
//! | number (NUMBER)             | `int`, the exact string, or a `float`    |
//! |                             | under `numbers: Float`                   |
//! | BINARY_DOUBLE, BINARY_FLOAT | `float`                                  |
//! | DATE, TIMESTAMP             | as a TIMESTAMP column                    |
//! | TIMESTAMP WITH TIME ZONE    | as a TSTZ column                         |
//! | INTERVALs                   | `DateInterval`                           |
//! | binary (RAW)                | bytes                                    |
//! | VECTOR                      | as a VECTOR column (see [`vector`])      |
//!
//! An OSON id (the `_id` of a JSON collection table) fails loudly. The
//! crate gives a DATE inside JSON as a timestamp, so it maps as a
//! TIMESTAMP.
//!
//! `Json::in()` takes what Core's `Json` leaves of any JSON-encodable value:
//! `null`, `bool`, `int`, `float` (an exact NUMBER, C4), UTF-8 strings,
//! arrays (a `list` is a JSON array, any other array an object), `stdClass`
//! (an object) and `DateTimeInterface` (a TIMESTAMP, its Wall clock).

use std::collections::HashMap;

use ext_php_rs::types::{ZendHashTable, Zval};
use ext_php_rs::zend::ClassEntry;
use oracledb::{DB_TYPE_JSON, JsonValue, OracleNumber, ToDbValue};

use crate::bind::exact;
use crate::error::Error;
use crate::options::Options;
use crate::temporal::{self, Temporal};
use crate::value::{number_value, set_string, widen};
use crate::vector;

/// How deep a value may nest, as `json_encode()` allows by default.
const MAX_DEPTH: usize = 512;

/// A JSON value as PHP sees it, under the Execution's `ValueMapping`.
pub fn value(json: JsonValue, options: &Options, what: &str) -> Result<Zval, Error> {
    let mut zval = Zval::new();
    match json {
        JsonValue::Null => zval.set_null(),
        JsonValue::Boolean(v) => zval.set_bool(v),
        JsonValue::Number(number) => number_value(&mut zval, number, options.numbers)?,
        JsonValue::String(text) => set_string(&mut zval, &text)?,
        JsonValue::Raw(bytes) => zval.set_binary(bytes),
        JsonValue::BinaryDouble(v) => zval.set_double(v),
        JsonValue::BinaryFloat(v) => zval.set_double(widen(v)),
        JsonValue::Timestamp(timestamp) => {
            let temporal = if timestamp.is_utc() {
                Temporal::TimestampTz
            } else {
                Temporal::Timestamp
            };
            return temporal::timestamp_value(timestamp, temporal, options.temporals);
        }
        JsonValue::IntervalDS(interval) => return temporal::interval_ds(interval),
        JsonValue::IntervalYM(interval) => return temporal::interval_ym(interval),
        JsonValue::Vector(v) => return vector::value(v),
        JsonValue::JsonId(_) => return Err(oson_id(&format!("{what}: the JSON value"))),
        JsonValue::JsonArray(values) => {
            let mut list = ZendHashTable::with_capacity(u32::try_from(values.len()).unwrap_or(0));
            for element in values {
                list.push(value(element, options, what)?).map_err(put)?;
            }
            zval.set_hashtable(list);
        }
        JsonValue::JsonObject(members) => {
            let mut object =
                ZendHashTable::with_capacity(u32::try_from(members.len()).unwrap_or(0));
            for (key, member) in members {
                // A numeric key becomes an int, as json_decode() makes it.
                object
                    .insert(key.as_str(), value(member, options, what)?)
                    .map_err(put)?;
            }
            zval.set_hashtable(object);
        }
    }
    Ok(zval)
}

/// An OSON id: not readable yet. `subject` starts the message.
pub fn oson_id(subject: &str) -> Error {
    Error::unsupported(format!(
        "{subject} holds an OSON id (such as the _id of a JSON collection table), which cannot be read yet; select json_serialize(…) of it, or the fields you need."
    ))
}

fn put(e: ext_php_rs::error::Error) -> Error {
    Error::unsupported(e.to_string())
}

// ---------------------------------------------------------------------------
// From PHP to Oracle
// ---------------------------------------------------------------------------

/// A `Json` bind's value: NULL (and an OUT bind) as a NULL JSON, anything
/// else as the JSON value it encodes.
pub fn bind(name: &str, value: &Zval) -> Result<Box<dyn ToDbValue>, Error> {
    if value.is_null() {
        return Ok(Box::new(DB_TYPE_JSON));
    }
    Ok(Box::new(of(&format!("Bind '{name}'"), value, 0)?))
}

fn of(what: &str, value: &Zval, depth: usize) -> Result<JsonValue, Error> {
    if depth > MAX_DEPTH {
        return Err(Error::usage(format!(
            "{what}: the value nests deeper than {MAX_DEPTH} levels."
        )));
    }
    let value = value.dereference();
    if value.is_null() {
        Ok(JsonValue::Null)
    } else if let Some(v) = value.bool() {
        Ok(JsonValue::Boolean(v))
    } else if let Some(v) = value.long() {
        Ok(JsonValue::Number(OracleNumber::from(v)))
    } else if let Some(v) = value.double() {
        exact(v).map(JsonValue::Number).ok_or_else(|| {
            Error::usage(format!(
                "{what}: JSON cannot hold this float (NaN, INF, or beyond NUMBER's 1e-130..1e126)."
            ))
        })
    } else if value.is_string() {
        value
            .str()
            .map(|s| JsonValue::String(s.to_string()))
            .ok_or_else(|| {
                Error::usage(format!(
                    "{what}: JSON text must be valid UTF-8; the string is not."
                ))
            })
    } else if let Some(array) = value.array() {
        members(what, array, depth)
    } else if let Some(object) = value.object() {
        let is = |class: &str| ClassEntry::try_find(class).is_some_and(|ce| object.instance_of(ce));
        if is("DateTimeInterface") {
            // A TIMESTAMP: the object's Wall clock, its zone ignored.
            temporal::timestamp_of(what, value, Temporal::Timestamp, false)?
                .map(JsonValue::Timestamp)
                .ok_or_else(|| Error::usage(format!("{what}: the date cannot be read.")))
        } else if is("stdClass") {
            let properties = object.get_properties().map_err(put)?;
            Ok(JsonValue::JsonObject(object_members(
                what, properties, depth,
            )?))
        } else {
            Err(Error::usage(format!(
                "{what}: an object of class {} cannot be encoded as JSON here; build the bind with Json::in().",
                object.get_class_name().unwrap_or_default()
            )))
        }
    } else {
        Err(Error::usage(format!(
            "{what}: JSON cannot hold a {}.",
            value.get_type()
        )))
    }
}

/// A `list` as a JSON array, any other array as an object.
fn members(what: &str, array: &ZendHashTable, depth: usize) -> Result<JsonValue, Error> {
    if array.has_sequential_keys() {
        let values = array
            .values()
            .map(|element| of(what, element, depth + 1))
            .collect::<Result<_, _>>()?;
        Ok(JsonValue::JsonArray(values))
    } else {
        Ok(JsonValue::JsonObject(object_members(what, array, depth)?))
    }
}

fn object_members(
    what: &str,
    table: &ZendHashTable,
    depth: usize,
) -> Result<HashMap<String, JsonValue>, Error> {
    let mut members = HashMap::with_capacity(table.len());
    for (key, member) in table {
        members.insert(key.to_string(), of(what, member, depth + 1)?);
    }
    Ok(members)
}
