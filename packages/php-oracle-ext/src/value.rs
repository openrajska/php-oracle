//! Values on their way to PHP: how a value the server sent becomes a PHP
//! value (spec #32 §8). OUT values follow these rules, as columns do.

use std::sync::Arc;

use ext_php_rs::convert::IntoZval;
use ext_php_rs::types::{ZendClassObject, Zval};
use oracledb::{
    DB_TYPE_BINARY_DOUBLE, DB_TYPE_BINARY_FLOAT, DB_TYPE_BINARY_INTEGER, DB_TYPE_BOOLEAN,
    DB_TYPE_CHAR, DB_TYPE_CURSOR, DB_TYPE_INTERVAL_DS, DB_TYPE_INTERVAL_YM, DB_TYPE_LONG,
    DB_TYPE_LONG_RAW, DB_TYPE_NCHAR, DB_TYPE_NUMBER, DB_TYPE_NVARCHAR, DB_TYPE_RAW, DB_TYPE_ROWID,
    DB_TYPE_UROWID, DB_TYPE_VARCHAR, OracleIntervalDS, OracleIntervalYM, OracleNumber,
    OracleTimestamp, Row,
};

use crate::connection::{ConnState, CursorSlot};
use crate::cursor::NativeCursor;
use crate::error::Error;
use crate::options::{NumberMode, Options};
use crate::temporal::{self, Temporal};

/// The value at `index` of a row, as PHP sees it. `what` names the value in
/// an error ("OUT bind 'x'"). A REF CURSOR is taken out of the row and
/// becomes a native Cursor of the Connection, with the Execution's options.
///
/// `reads_as` is the timestamp type an OUT bind declared, when it differs
/// from what comes back: a `Date` IN OUT goes (and comes back) as TIMESTAMP,
/// a `TimestampLtz` IN OUT as TSTZ (see [`temporal::timestamp_bind`]).
pub fn cell(
    row: &mut Row,
    index: usize,
    state: &Arc<ConnState>,
    options: &Options,
    what: &str,
    reads_as: Option<Temporal>,
) -> Result<Zval, Error> {
    let numbers = options.numbers;
    let metadata = row
        .columns()
        .get(index)
        .ok_or_else(|| Error::unsupported(format!("{what}: no value came back.")))?;
    let db_type = metadata.db_type();
    if db_type == DB_TYPE_CURSOR {
        return cursor(row, index, state, options, Vec::new());
    }
    if let Some(temporal) = Temporal::of(db_type) {
        return match row.get::<Option<OracleTimestamp>>(index)? {
            Some(value) => {
                temporal::timestamp_value(value, reads_as.unwrap_or(temporal), options.temporals)
            }
            None => Ok(Zval::null()),
        };
    }
    if db_type == DB_TYPE_INTERVAL_DS {
        return match row.get::<Option<OracleIntervalDS>>(index)? {
            Some(value) => temporal::interval_ds(value),
            None => Ok(Zval::null()),
        };
    }
    if db_type == DB_TYPE_INTERVAL_YM {
        return match row.get::<Option<OracleIntervalYM>>(index)? {
            Some(value) => temporal::interval_ym(value),
            None => Ok(Zval::null()),
        };
    }
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

/// The REF CURSOR or nested `CURSOR()` at `index` of a row, taken out of
/// it, as a native Cursor holding `parents`: the slots of the Cursors it is
/// nested in, outermost first. NULL gives `null`.
pub fn cursor(
    row: &mut Row,
    index: usize,
    state: &Arc<ConnState>,
    options: &Options,
    parents: Vec<Arc<CursorSlot>>,
) -> Result<Zval, Error> {
    match row.take::<Option<oracledb::Cursor>>(index)? {
        Some(cursor) => {
            let cursor = NativeCursor::new(Arc::clone(state), cursor, options, parents)?;
            ZendClassObject::new(cursor)
                .into_zval(false)
                .map_err(|e| Error::unsupported(e.to_string()))
        }
        None => Ok(Zval::null()),
    }
}

/// NUMBER: `int` when integral and within 64 bits, else the exact canonical
/// string, or a `float` under `NumberMode::Float` when it is not integral.
fn number_value(zval: &mut Zval, number: OracleNumber, numbers: NumberMode) -> Result<(), Error> {
    // Display gives the exact decimal text, never an exponent.
    let text = number.to_string();
    if let Ok(int) = text.parse::<i64>() {
        zval.set_long(int);
    } else if numbers == NumberMode::Float
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
