//! `Rajska\Oracle\Extension\Cursor`: the rows of one query, read in batches
//! of `fetchSize` rows, each batch at most one Round trip (spec #32 §6, §14).

use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::Arc;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};
use oracledb::{
    DB_TYPE_BFILE, DB_TYPE_BINARY_DOUBLE, DB_TYPE_BINARY_FLOAT, DB_TYPE_BINARY_INTEGER,
    DB_TYPE_BLOB, DB_TYPE_BOOLEAN, DB_TYPE_CHAR, DB_TYPE_CLOB, DB_TYPE_CURSOR, DB_TYPE_DATE,
    DB_TYPE_INTERVAL_DS, DB_TYPE_INTERVAL_YM, DB_TYPE_JSON, DB_TYPE_LONG, DB_TYPE_LONG_RAW,
    DB_TYPE_NCHAR, DB_TYPE_NCLOB, DB_TYPE_NUMBER, DB_TYPE_NVARCHAR, DB_TYPE_OBJECT, DB_TYPE_RAW,
    DB_TYPE_ROWID, DB_TYPE_TIMESTAMP, DB_TYPE_TIMESTAMP_LTZ, DB_TYPE_TIMESTAMP_TZ, DB_TYPE_UROWID,
    DB_TYPE_VARCHAR, DB_TYPE_VECTOR, DB_TYPE_XMLTYPE, DbType, Metadata, OracleNumber, Row,
    VectorStorageFormat,
};

use crate::connection::{ConnState, CursorSlot};
use crate::error::{Error, run};
use crate::options::{KeyCase, NumberMode, Options, RowShape};
use crate::pool::lock;

/// How a column's values become PHP values.
enum Mapping {
    /// NUMBER: `int` when integral and within 64 bits (decided per value),
    /// else the exact canonical string; under `NumberMode::Float`, a
    /// non-integral value becomes a `float`.
    Number(NumberMode),
    /// Character types: `string`.
    Text,
    /// A type whose values cannot be fetched yet; describing it is fine.
    Unsupported(String),
}

/// `Type`'s backing value for a column, or `None` for a type the server
/// should never describe.
fn type_name(metadata: &Metadata) -> Option<&'static str> {
    let db_type = metadata.db_type();
    let names: [(&DbType, &'static str); 28] = [
        (DB_TYPE_VARCHAR, "varchar"),
        (DB_TYPE_CHAR, "char"),
        (DB_TYPE_NVARCHAR, "nVarchar"),
        (DB_TYPE_NCHAR, "nChar"),
        (DB_TYPE_NUMBER, "number"),
        (DB_TYPE_BINARY_FLOAT, "binaryFloat"),
        (DB_TYPE_BINARY_DOUBLE, "binaryDouble"),
        (DB_TYPE_BINARY_INTEGER, "plsInteger"),
        (DB_TYPE_BOOLEAN, "boolean"),
        (DB_TYPE_DATE, "date"),
        (DB_TYPE_TIMESTAMP, "timestamp"),
        (DB_TYPE_TIMESTAMP_TZ, "timestampTz"),
        (DB_TYPE_TIMESTAMP_LTZ, "timestampLtz"),
        (DB_TYPE_INTERVAL_DS, "intervalDs"),
        (DB_TYPE_INTERVAL_YM, "intervalYm"),
        (DB_TYPE_RAW, "raw"),
        (DB_TYPE_ROWID, "rowid"),
        (DB_TYPE_UROWID, "urowid"),
        (DB_TYPE_CLOB, "clob"),
        (DB_TYPE_NCLOB, "nClob"),
        (DB_TYPE_BLOB, "blob"),
        (DB_TYPE_JSON, "json"),
        (DB_TYPE_VECTOR, "vector"),
        (DB_TYPE_CURSOR, "cursor"),
        (DB_TYPE_LONG, "long"),
        (DB_TYPE_LONG_RAW, "longRaw"),
        (DB_TYPE_BFILE, "bfile"),
        (DB_TYPE_XMLTYPE, "xmltype"),
    ];
    if db_type == DB_TYPE_OBJECT {
        return Some(match metadata.object_type() {
            Ok(Some(object_type)) if object_type.is_collection() => "collection",
            _ => "object",
        });
    }
    names
        .iter()
        .find(|(candidate, _)| db_type == *candidate)
        .map(|(_, name)| *name)
}

/// What `columns()` reports for one column: exactly `Column`'s fields.
struct Description {
    type_name: Option<&'static str>,
    nullable: bool,
    precision: Option<i64>,
    scale: Option<i64>,
    max_size: Option<i64>,
    object_type: Option<String>,
    vector_dimensions: Option<i64>,
    vector_format: Option<&'static str>,
    sparse: bool,
}

impl Description {
    fn new(metadata: &Metadata) -> Self {
        let db_type = metadata.db_type();
        let (precision, scale) = (i64::from(metadata.precision()), i64::from(metadata.scale()));
        let (precision, scale) = if db_type == DB_TYPE_NUMBER {
            // An unconstrained NUMBER is described as precision 0, and a
            // FLOAT (or an unconstrained NUMBER) as scale -127.
            (
                Some(precision).filter(|p| *p != 0),
                Some(scale).filter(|s| *s != -127),
            )
        } else if db_type == DB_TYPE_INTERVAL_DS {
            (Some(precision), Some(scale))
        } else if db_type == DB_TYPE_INTERVAL_YM {
            (Some(precision), None)
        } else if [
            DB_TYPE_TIMESTAMP,
            DB_TYPE_TIMESTAMP_TZ,
            DB_TYPE_TIMESTAMP_LTZ,
        ]
        .contains(&db_type)
        {
            (None, Some(scale))
        } else {
            (None, None)
        };
        let max_size = [
            DB_TYPE_VARCHAR,
            DB_TYPE_CHAR,
            DB_TYPE_NVARCHAR,
            DB_TYPE_NCHAR,
            DB_TYPE_RAW,
        ]
        .contains(&db_type)
        .then(|| i64::from(metadata.max_size()));
        let object_type = match metadata.object_type() {
            Ok(Some(object_type)) => Some(match object_type.package_name() {
                Some(package) => {
                    format!("{}.{package}.{}", object_type.schema(), object_type.name())
                }
                None => format!("{}.{}", object_type.schema(), object_type.name()),
            }),
            _ => None,
        };
        let vector_format = metadata.vector_storage_format().map(|format| match format {
            VectorStorageFormat::Float32 => "float32",
            VectorStorageFormat::Float64 => "float64",
            VectorStorageFormat::Int8 => "int8",
            VectorStorageFormat::Binary => "binary",
        });
        Self {
            type_name: type_name(metadata),
            nullable: metadata.nullable(),
            precision,
            scale,
            max_size,
            object_type,
            vector_dimensions: metadata
                .vector_dimensions()
                .map(|d| i64::try_from(d).unwrap_or(i64::MAX)),
            vector_format,
            sparse: metadata.is_sparse_vector(),
        }
    }
}

struct Column {
    /// The name in the Cursor's `KeyCase`: the row key and `Column::$name`.
    name: String,
    mapping: Mapping,
    description: Description,
}

impl Column {
    /// `Column`'s fields, enums as their backing values.
    fn describe(&self) -> Result<ZBox<ZendHashTable>, Error> {
        let d = &self.description;
        let type_name = d.type_name.ok_or_else(|| {
            Error::unsupported(format!(
                "Column {}: the server described a type this Backend does not know.",
                self.name
            ))
        })?;
        let mut column = ZendHashTable::with_capacity(10);
        column.insert("name", self.name.as_str()).map_err(put)?;
        column.insert("type", type_name).map_err(put)?;
        column.insert("nullable", d.nullable).map_err(put)?;
        column.insert("precision", d.precision).map_err(put)?;
        column.insert("scale", d.scale).map_err(put)?;
        column.insert("maxSize", d.max_size).map_err(put)?;
        column
            .insert("objectType", d.object_type.as_deref())
            .map_err(put)?;
        column
            .insert("vectorDimensions", d.vector_dimensions)
            .map_err(put)?;
        column
            .insert("vectorFormat", d.vector_format)
            .map_err(put)?;
        column.insert("sparse", d.sparse).map_err(put)?;
        Ok(column)
    }

    fn new(metadata: &Metadata, options: &Options) -> Self {
        let db_type = metadata.db_type();
        let mapping = if db_type == DB_TYPE_NUMBER {
            Mapping::Number(options.numbers)
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
        let name = match options.key_case {
            KeyCase::Native => metadata.name().to_string(),
            KeyCase::Lower => metadata.name().to_lowercase(),
        };
        Self {
            name,
            mapping,
            description: Description::new(metadata),
        }
    }

    fn value(&self, row: &Row, index: usize) -> Result<Zval, Error> {
        let mut zval = Zval::new();
        match &self.mapping {
            Mapping::Number(numbers) => match row.get::<Option<OracleNumber>>(index)? {
                Some(number) => {
                    // Display gives the exact decimal text, never an exponent.
                    let text = number.to_string();
                    if let Ok(int) = text.parse::<i64>() {
                        zval.set_long(int);
                    } else if *numbers == NumberMode::Float && text.contains('.') {
                        // Correctly rounded from the exact decimal text.
                        zval.set_double(text.parse::<f64>().map_err(|e| {
                            Error::unsupported(format!("Column {}: {e}", self.name))
                        })?);
                    } else {
                        set_string(&mut zval, &text)?;
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

fn put(e: ext_php_rs::error::Error) -> Error {
    Error::unsupported(e.to_string())
}

#[php_class]
#[php(name = "Rajska\\Oracle\\Extension\\Cursor")]
#[php(flags = ClassFlags::Final)]
pub struct NativeCursor {
    /// Emptied by `close()` and by the Connection's Release. Declared
    /// before `state`, so it drops first, while the Session is still held.
    cursor: Arc<CursorSlot>,
    exhausted: bool,
    columns: Vec<Column>,
    row_shape: RowShape,
    fetch_size: usize,
    state: Arc<ConnState>,
}

impl NativeCursor {
    /// Describes the columns and builds the row keys, once per Cursor.
    /// Duplicate keys under `Assoc` fail here, when the Cursor opens.
    pub fn new(
        state: Arc<ConnState>,
        cursor: oracledb::Cursor,
        options: &Options,
    ) -> Result<Self, Error> {
        let columns: Vec<Column> = cursor
            .columns()
            .iter()
            .map(|metadata| Column::new(metadata, options))
            .collect();
        if options.row_shape == RowShape::Assoc {
            for (index, column) in columns.iter().enumerate() {
                if columns[..index].iter().any(|c| c.name == column.name) {
                    return Err(Error::usage(format!(
                        "Two columns are named '{}', so they cannot both be keys of an \
                         associative row: give them distinct aliases, or use RowShape::List.",
                        column.name
                    )));
                }
            }
        }
        Ok(Self {
            cursor: state.register(cursor),
            exhausted: false,
            columns,
            row_shape: options.row_shape,
            fetch_size: options.fetch_size as usize,
            state,
        })
    }

    fn row(&self, row: &Row) -> Result<ZBox<ZendHashTable>, Error> {
        let mut values =
            ZendHashTable::with_capacity(u32::try_from(self.columns.len()).unwrap_or(0));
        for (index, column) in self.columns.iter().enumerate() {
            let value = column.value(row, index)?;
            match self.row_shape {
                RowShape::Assoc => values.insert(column.name.as_str(), value).map_err(put)?,
                RowShape::List => values.push(value).map_err(put)?,
            }
        }
        Ok(values)
    }
}

#[php_impl]
impl NativeCursor {
    /// One array per column with exactly `Column`'s keys, enums as their
    /// backing values. Available before the first row; no Round trip.
    pub fn columns(&self) -> PhpResult<ZBox<ZendHashTable>> {
        self.state.run(|| {
            self.state.ensure_usable()?;
            if lock(&self.cursor).is_none() {
                return Err(Error::usage("The Cursor is closed."));
            }
            let mut columns =
                ZendHashTable::with_capacity(u32::try_from(self.columns.len()).unwrap_or(0));
            for column in &self.columns {
                columns.push(column.describe()?).map_err(put)?;
            }
            Ok(columns)
        })
    }

    /// Up to `fetchSize` rows; `[]` at the end and on every later call.
    pub fn fetch_batch(&mut self) -> PhpResult<ZBox<ZendHashTable>> {
        let state = Arc::clone(&self.state);
        state.run(|| {
            state.ensure_usable()?;
            let mut slot = lock(&self.cursor);
            let cursor = slot
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
                rows.push(self.row(row)?).map_err(put)?;
            }
            Ok(rows)
        })
    }

    /// Closes the server cursor: the close rides on the Session's next Round
    /// trip. Idempotent.
    pub fn close(&mut self) -> PhpResult<()> {
        run(|| {
            lock(&self.cursor).take();
            Ok(())
        })
    }
}

impl Drop for NativeCursor {
    /// Closes the server cursor before `state` goes (perhaps Releasing the
    /// Connection). A panic must not cross into PHP's free handler.
    fn drop(&mut self) {
        let _ = catch_unwind(AssertUnwindSafe(|| lock(&self.cursor).take()));
    }
}
