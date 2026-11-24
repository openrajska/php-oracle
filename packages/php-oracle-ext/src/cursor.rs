//! `Rajska\Oracle\Extension\Cursor`: the rows of one query, read in batches
//! of `fetchSize` rows, each batch at most one Round trip (spec #32 §6, §14).

use std::sync::Arc;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};
use oracledb::{
    DB_TYPE_CHAR, DB_TYPE_LONG, DB_TYPE_NCHAR, DB_TYPE_NUMBER, DB_TYPE_NVARCHAR, DB_TYPE_VARCHAR,
    Metadata, OracleNumber, Row,
};

use crate::connection::{ConnState, Options};
use crate::error::{Error, run};

/// How a column's values become PHP values.
enum Mapping {
    /// NUMBER: `int` when integral and within 64 bits, else the exact
    /// canonical string.
    Number,
    /// Character types: `string`.
    Text,
    /// A type whose values cannot be fetched yet; describing it is fine.
    Unsupported(String),
}

struct Column {
    name: String,
    mapping: Mapping,
}

impl Column {
    fn new(metadata: &Metadata) -> Self {
        let db_type = metadata.db_type();
        let mapping = if db_type == DB_TYPE_NUMBER {
            Mapping::Number
        } else if [
            DB_TYPE_VARCHAR,
            DB_TYPE_CHAR,
            DB_TYPE_NVARCHAR,
            DB_TYPE_NCHAR,
            DB_TYPE_LONG,
        ]
        .contains(&db_type)
        {
            Mapping::Text
        } else {
            Mapping::Unsupported(metadata.data_type())
        };
        Self {
            name: metadata.name().to_string(),
            mapping,
        }
    }

    fn value(&self, row: &Row, index: usize) -> Result<Zval, Error> {
        let mut zval = Zval::new();
        match &self.mapping {
            Mapping::Number => match row.get::<Option<OracleNumber>>(index)? {
                Some(number) => {
                    // Display gives the exact decimal text, never an exponent.
                    let text = number.to_string();
                    match text.parse::<i64>() {
                        Ok(int) => zval.set_long(int),
                        Err(_) => set_string(&mut zval, &text)?,
                    }
                }
                None => zval.set_null(),
            },
            Mapping::Text => match row.get::<Option<&str>>(index)? {
                Some(text) => set_string(&mut zval, text)?,
                None => zval.set_null(),
            },
            Mapping::Unsupported(data_type) => {
                return Err(Error::unsupported(format!(
                    "Column {}: {data_type} values cannot be fetched yet.",
                    self.name
                )));
            }
        }
        Ok(zval)
    }
}

fn set_string(zval: &mut Zval, text: &str) -> Result<(), Error> {
    zval.set_string(text, false)
        .map_err(|e| Error::unsupported(e.to_string()))
}

#[php_class]
#[php(name = "Rajska\\Oracle\\Extension\\Cursor")]
#[php(flags = ClassFlags::Final)]
pub struct NativeCursor {
    // Declared before `state`, so it drops first, while the Session lives.
    cursor: Option<oracledb::Cursor>,
    exhausted: bool,
    columns: Vec<Column>,
    fetch_size: usize,
    state: Arc<ConnState>,
}

impl NativeCursor {
    pub fn new(state: Arc<ConnState>, cursor: oracledb::Cursor, options: &Options) -> Self {
        let columns = cursor.columns().iter().map(Column::new).collect();
        Self {
            cursor: Some(cursor),
            exhausted: false,
            columns,
            fetch_size: options.fetch_size as usize,
            state,
        }
    }

    fn row(&self, row: &Row) -> Result<ZBox<ZendHashTable>, Error> {
        let mut values =
            ZendHashTable::with_capacity(u32::try_from(self.columns.len()).unwrap_or(0));
        for (index, column) in self.columns.iter().enumerate() {
            values
                .insert(column.name.as_str(), column.value(row, index)?)
                .map_err(|e| Error::unsupported(e.to_string()))?;
        }
        Ok(values)
    }
}

#[php_impl]
impl NativeCursor {
    /// The column descriptions.
    pub fn columns(&self) -> PhpResult<ZBox<ZendHashTable>> {
        run(|| {
            Err(Error::unsupported(
                "Cursor::columns() is not implemented yet.",
            ))
        })
    }

    /// Up to `fetchSize` rows; `[]` at the end and on every later call.
    pub fn fetch_batch(&mut self) -> PhpResult<ZBox<ZendHashTable>> {
        run(|| {
            self.state.ensure_open()?;
            let cursor = self
                .cursor
                .as_mut()
                .ok_or_else(|| Error::usage("The Cursor is closed."))?;
            let mut rows =
                ZendHashTable::with_capacity(u32::try_from(self.fetch_size).unwrap_or(0));
            if self.exhausted {
                return Ok(rows);
            }
            let mut fetched = Vec::with_capacity(self.fetch_size);
            while fetched.len() < self.fetch_size {
                match cursor.next() {
                    Some(row) => fetched.push(row?),
                    None => {
                        self.exhausted = true;
                        break;
                    }
                }
            }
            for row in &fetched {
                rows.push(self.row(row)?)
                    .map_err(|e| Error::unsupported(e.to_string()))?;
            }
            Ok(rows)
        })
    }

    /// Closes the server cursor. Idempotent.
    pub fn close(&mut self) -> PhpResult<()> {
        run(|| {
            self.cursor = None;
            Ok(())
        })
    }
}
