//! VECTOR values both ways (spec #32 §7, §8, §14).
//!
//! | Oracle                    | PHP                                         |
//! |---------------------------|---------------------------------------------|
//! | FLOAT32, FLOAT64          | `list<float>` (FLOAT32 widened through its  |
//! |                           | shortest round-trip text)                   |
//! | INT8                      | `list<int>`                                 |
//! | BINARY                    | `list<int>` of bytes (8 dimensions each)    |
//! | sparse                    | `Rajska\Oracle\SparseVector`                |
//!
//! A sparse VECTOR becomes a `SparseVector` through
//! `SparseVector::fromDriver(int $dimensions, array $values)`, the one Core
//! call the Extension makes: its name and signature are part of the
//! Extension's semver contract.
//!
//! `Vector::in()` takes a `list<int|float>` or a `SparseVector`, with the
//! `VectorFormat` (its backing value travels as the bind's `typeName`).
//! Every value is checked before any Round trip.

use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZvalDyn;
use ext_php_rs::types::{ArrayKey, ZendHashTable, Zval};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals, Function};
use oracledb::{DB_TYPE_VECTOR, SparseVector, ToDbValue, Vector, VectorData};

use crate::error::Error;
use crate::value::widen;

/// The Core class a sparse VECTOR becomes.
pub const SPARSE_VECTOR_CLASS: &str = "Rajska\\Oracle\\SparseVector";

/// The most non-zero values a sparse VECTOR sends (its count is 16 bits).
const MAX_SPARSE_VALUES: usize = 65535;

/// A VECTOR as PHP sees it: a list, or a `SparseVector`.
pub fn value(vector: Vector) -> Result<Zval, Error> {
    match vector {
        Vector::Dense(data) => {
            let mut list = ZendHashTable::with_capacity(u32::try_from(len(&data)).unwrap_or(0));
            for value in values(&data) {
                list.push(value).map_err(put)?;
            }
            Ok(array(list))
        }
        Vector::Sparse(sparse) => {
            let mut values =
                ZendHashTable::with_capacity(u32::try_from(sparse.indices().len()).unwrap_or(0));
            for (index, value) in sparse.indices().iter().zip(self::values(sparse.values())) {
                values
                    .insert(i64::try_from(*index).unwrap_or(i64::MAX), value)
                    .map_err(put)?;
            }
            let dimensions = i64::try_from(sparse.num_dimensions()).unwrap_or(i64::MAX);
            from_driver(dimensions, values)
        }
    }
}

/// `SparseVector::fromDriver($dimensions, $values)`; an exception it throws
/// goes to the caller as is.
fn from_driver(dimensions: i64, values: ZBox<ZendHashTable>) -> Result<Zval, Error> {
    let function = Function::try_from_method(SPARSE_VECTOR_CLASS, "fromDriver").ok_or_else(|| {
        Error::unsupported(format!(
            "The class {SPARSE_VECTOR_CLASS} is missing: install openrajska/php-oracle to read sparse VECTORs."
        ))
    })?;
    let values = array(values);
    let args: Vec<&dyn IntoZvalDyn> = vec![&dimensions, &values];
    let vector = function.try_call(args).map_err(put)?;
    if ExecutorGlobals::has_exception() {
        return Err(Error::php_exception());
    }
    Ok(vector)
}

fn len(data: &VectorData) -> usize {
    match data {
        VectorData::Float32(v) => v.len(),
        VectorData::Float64(v) => v.len(),
        VectorData::Int8(v) => v.len(),
        VectorData::Binary(v) => v.len(),
    }
}

/// Each element as a PHP value: a float, or an int for INT8 and BINARY.
fn values(data: &VectorData) -> Vec<Zval> {
    let float = |v: f64| {
        let mut zval = Zval::new();
        zval.set_double(v);
        zval
    };
    let int = |v: i64| {
        let mut zval = Zval::new();
        zval.set_long(v);
        zval
    };
    match data {
        VectorData::Float32(v) => v.iter().map(|v| float(widen(*v))).collect(),
        VectorData::Float64(v) => v.iter().map(|v| float(*v)).collect(),
        VectorData::Int8(v) => v.iter().map(|v| int(i64::from(*v))).collect(),
        VectorData::Binary(v) => v.iter().map(|v| int(i64::from(*v))).collect(),
    }
}

fn array(table: ZBox<ZendHashTable>) -> Zval {
    let mut zval = Zval::new();
    zval.set_hashtable(table);
    zval
}

fn put(e: ext_php_rs::error::Error) -> Error {
    Error::unsupported(e.to_string())
}

// ---------------------------------------------------------------------------
// From PHP to Oracle
// ---------------------------------------------------------------------------

/// How a `Vector` bind stores its elements: `VectorFormat`'s backing value.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Format {
    Float32,
    Float64,
    Int8,
    Binary,
}

impl Format {
    fn of(name: &str, format: Option<&str>) -> Result<Self, Error> {
        match format {
            Some("float32") => Ok(Self::Float32),
            Some("float64") => Ok(Self::Float64),
            Some("int8") => Ok(Self::Int8),
            Some("binary") => Ok(Self::Binary),
            _ => Err(Error::usage(format!(
                "Bind '{name}': a Vector bind needs its VectorFormat as typeName."
            ))),
        }
    }

    fn sql_name(self) -> &'static str {
        match self {
            Self::Float32 => "FLOAT32",
            Self::Float64 => "FLOAT64",
            Self::Int8 => "INT8",
            Self::Binary => "BINARY",
        }
    }
}

/// A `Vector` bind's value: NULL (and an OUT bind) as a NULL VECTOR, a
/// `list<int|float>` as a dense VECTOR, a `SparseVector` as a sparse one,
/// each stored in `format` (`VectorFormat`'s backing value).
pub fn bind(name: &str, value: &Zval, format: Option<&str>) -> Result<Box<dyn ToDbValue>, Error> {
    if value.is_null() {
        return Ok(Box::new(DB_TYPE_VECTOR));
    }
    Ok(Box::new(of(
        &format!("Bind '{name}'"),
        value,
        Format::of(name, format)?,
    )?))
}

/// A VECTOR from a PHP value; `what` starts every message ("Bind 'v'").
fn of(what: &str, value: &Zval, format: Format) -> Result<Vector, Error> {
    if let Some(list) = value.array() {
        if list.is_empty() || !list.has_sequential_keys() {
            return Err(Error::usage(format!(
                "{what}: a dense VECTOR is a non-empty list of numbers; use a SparseVector for values keyed by position."
            )));
        }
        let elements: Vec<&Zval> = list.values().collect();
        return Ok(Vector::Dense(data(what, &elements, format)?));
    }
    let sparse = value
        .object()
        .filter(|object| {
            ClassEntry::try_find(SPARSE_VECTOR_CLASS).is_some_and(|ce| object.instance_of(ce))
        })
        .ok_or_else(|| {
            Error::usage(format!(
                "{what}: the value must be a list of ints and floats, a SparseVector or null."
            ))
        })?;
    if format == Format::Binary {
        return Err(Error::usage(format!(
            "{what}: a sparse VECTOR cannot be BINARY; use VectorFormat::Float32, Float64 or Int8."
        )));
    }
    let properties = sparse.get_properties().map_err(put)?;
    let dimensions = properties
        .get("dimensions")
        .and_then(Zval::long)
        .and_then(|d| u32::try_from(d).ok())
        .filter(|d| *d > 0)
        .ok_or_else(|| {
            Error::usage(format!(
                "{what}: the SparseVector's dimensions are not valid."
            ))
        })?;
    let values = properties
        .get("values")
        .and_then(Zval::array)
        .ok_or_else(|| Error::usage(format!("{what}: the SparseVector has no values.")))?;
    if values.len() > MAX_SPARSE_VALUES {
        return Err(Error::usage(format!(
            "{what}: a sparse VECTOR holds at most {MAX_SPARSE_VALUES} non-zero values."
        )));
    }
    let mut entries = Vec::with_capacity(values.len());
    for (key, element) in values {
        let position = match key {
            ArrayKey::Long(position) => u32::try_from(position).ok(),
            _ => None,
        }
        .filter(|p| *p < dimensions)
        .ok_or_else(|| {
            Error::usage(format!(
                "{what}: the SparseVector's positions must be ints from 0 to {}.",
                dimensions - 1
            ))
        })?;
        entries.push((position as usize, element));
    }
    entries.sort_by_key(|(position, _)| *position);
    let elements: Vec<&Zval> = entries.iter().map(|(_, element)| *element).collect();
    Ok(Vector::Sparse(SparseVector::new(
        dimensions as usize,
        entries.iter().map(|(position, _)| *position).collect(),
        data(what, &elements, format)?,
    )))
}

/// The elements in `format`. Each must be an int or a float that the
/// format holds: a finite float for FLOAT32 and FLOAT64, an int from -128
/// to 127 for INT8, and a byte (0 to 255, 8 dimensions) for BINARY.
fn data(what: &str, elements: &[&Zval], format: Format) -> Result<VectorData, Error> {
    let number = |element: &Zval| -> Option<f64> {
        let element = element.dereference();
        #[allow(clippy::cast_precision_loss)]
        element
            .double()
            .or_else(|| element.long().map(|v| v as f64))
    };
    let int = |element: &Zval| element.dereference().long();
    let wrong = |expected: &str| {
        Error::usage(format!(
            "{what}: {} {} VECTOR holds {expected}.",
            if format == Format::Int8 { "an" } else { "a" },
            format.sql_name()
        ))
    };
    if u32::try_from(elements.len()).is_err() {
        return Err(Error::usage(format!(
            "{what}: the VECTOR has too many dimensions."
        )));
    }
    Ok(match format {
        Format::Float64 => VectorData::Float64(
            elements
                .iter()
                .map(|e| number(e).filter(|v| v.is_finite()))
                .collect::<Option<_>>()
                .ok_or_else(|| wrong("finite ints and floats"))?,
        ),
        Format::Float32 => VectorData::Float32(
            elements
                .iter()
                .map(|e| {
                    #[allow(clippy::cast_possible_truncation)] // checked finite below
                    number(e).map(|v| v as f32).filter(|v| v.is_finite())
                })
                .collect::<Option<_>>()
                .ok_or_else(|| wrong("ints and floats within FLOAT32's range"))?,
        ),
        Format::Int8 => VectorData::Int8(
            elements
                .iter()
                .map(|e| int(e).and_then(|v| i8::try_from(v).ok()))
                .collect::<Option<_>>()
                .ok_or_else(|| wrong("ints from -128 to 127"))?,
        ),
        Format::Binary => VectorData::Binary(
            elements
                .iter()
                .map(|e| int(e).and_then(|v| u8::try_from(v).ok()))
                .collect::<Option<_>>()
                .ok_or_else(|| wrong("bytes: ints from 0 to 255, each 8 dimensions"))?,
        ),
    })
}
