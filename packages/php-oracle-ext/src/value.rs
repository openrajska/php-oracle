//! Values on their way to PHP: how a value the server sent becomes a PHP
//! value (spec #32 §8). OUT values follow these rules, as columns do.

use ext_php_rs::types::Zval;
use oracledb::{
    DB_TYPE_BINARY_DOUBLE, DB_TYPE_BINARY_FLOAT, DB_TYPE_BINARY_INTEGER, DB_TYPE_BOOLEAN,
    DB_TYPE_CHAR, DB_TYPE_LONG, DB_TYPE_LONG_RAW, DB_TYPE_NCHAR, DB_TYPE_NUMBER, DB_TYPE_NVARCHAR,
    DB_TYPE_RAW, DB_TYPE_ROWID, DB_TYPE_UROWID, DB_TYPE_VARCHAR, OracleNumber, Row,
};

use crate::error::Error;

/// How a non-integral NUMBER reaches PHP (`ValueMapping::$numbers`).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum Numbers {
    /// The exact canonical string.
    #[default]
    Exact,
    /// A `float`.
    Float,
}

impl Numbers {
    /// From its backing value in `$options`.
    pub fn from_option(value: &str) -> Self {
        if value == "float" {
            Numbers::Float
        } else {
            Numbers::Exact
        }
    }
}

/// The value at `index` of a row, as PHP sees it. `what` names the value in
/// an error ("OUT bind 'x'", "Column NAME").
pub fn cell(row: &Row, index: usize, numbers: Numbers, what: &str) -> Result<Zval, Error> {
    let metadata = row
        .columns()
        .get(index)
        .ok_or_else(|| Error::unsupported(format!("{what}: no value came back.")))?;
    let db_type = metadata.db_type();
    let mut zval = Zval::new();
    if db_type == DB_TYPE_NUMBER || db_type == DB_TYPE_BINARY_INTEGER {
        match row.get::<Option<OracleNumber>>(index)? {
            Some(number) => number_value(&mut zval, number, numbers)?,
            None => zval.set_null(),
        }
    } else if [
        DB_TYPE_VARCHAR,
        DB_TYPE_CHAR,
        DB_TYPE_NVARCHAR,
        DB_TYPE_NCHAR,
        DB_TYPE_LONG,
    ]
    .contains(&db_type)
    {
        match row.get::<Option<&str>>(index)? {
            Some(text) => set_string(&mut zval, text)?,
            None => zval.set_null(),
        }
    } else if db_type == DB_TYPE_ROWID || db_type == DB_TYPE_UROWID {
        match row.get::<Option<String>>(index)? {
            Some(text) => set_string(&mut zval, &text)?,
            None => zval.set_null(),
        }
    } else if db_type == DB_TYPE_RAW || db_type == DB_TYPE_LONG_RAW {
        match row.get::<Option<&[u8]>>(index)? {
            Some(bytes) => zval.set_binary(bytes.to_vec()),
            None => zval.set_null(),
        }
    } else if db_type == DB_TYPE_BINARY_DOUBLE {
        match row.get::<Option<f64>>(index)? {
            Some(v) => zval.set_double(v),
            None => zval.set_null(),
        }
    } else if db_type == DB_TYPE_BINARY_FLOAT {
        match row.get::<Option<f32>>(index)? {
            // Widened through its shortest round-trip text: 0.1f32 gives 0.1.
            Some(v) => zval.set_double(widen(v)),
            None => zval.set_null(),
        }
    } else if db_type == DB_TYPE_BOOLEAN {
        match row.get::<Option<bool>>(index)? {
            Some(v) => zval.set_bool(v),
            None => zval.set_null(),
        }
    } else {
        return Err(Error::unsupported(format!(
            "{what}: {} values cannot be read yet.",
            metadata.data_type()
        )));
    }
    Ok(zval)
}

/// NUMBER: `int` when integral and within 64 bits, else the exact canonical
/// string, or a `float` under `Numbers::Float` when it is not integral.
fn number_value(zval: &mut Zval, number: OracleNumber, numbers: Numbers) -> Result<(), Error> {
    // Display gives the exact decimal text, never an exponent.
    let text = number.to_string();
    if let Ok(int) = text.parse::<i64>() {
        zval.set_long(int);
    } else if numbers == Numbers::Float
        && text.contains('.')
        && let Ok(float) = text.parse::<f64>()
    {
        zval.set_double(float);
    } else {
        set_string(zval, &text)?;
    }
    Ok(())
}

/// A BINARY_FLOAT as the `float` its shortest round-trip text writes.
fn widen(value: f32) -> f64 {
    format!("{value}").parse().unwrap_or(f64::from(value))
}

fn set_string(zval: &mut Zval, text: &str) -> Result<(), Error> {
    zval.set_string(text, false)
        .map_err(|e| Error::unsupported(e.to_string()))
}
