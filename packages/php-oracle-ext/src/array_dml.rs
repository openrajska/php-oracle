//! Array DML: one statement run once per row of binds, every row in one
//! execute and one Round trip (spec #32 §9, `runMany()`).
//!
//! The rows are checked here before any Round trip:
//! - every row has the same keys;
//! - a column takes one type, set by its first non-null value: an `int` and
//!   a `float` mix as NUMBER, any other mix fails, naming the row and bind;
//! - a string over 32767 bytes makes the whole column CLOB;
//! - an all-null column is VARCHAR2 NULL, and a NULL elsewhere is a NULL of
//!   its column's type;
//! - a typed bind keeps its class (and direction, and Object type) down its
//!   column, and a column of typed binds takes no plain value;
//! - a REF CURSOR (`Cursor::out()`) is refused, and so is a query.
//!
//! The call is atomic. Rows that fail do not stop the call (C3): their
//! errors come back together, and the Extension then rolls back what the
//! others did. Under Autocommit that is a rollback (C21 commits nothing when
//! a row failed); inside an Explicit transaction, the execute runs after a
//! savepoint (one Round trip more) and rolls back to it, so earlier work in
//! the transaction stays. The same goes for any other failure of the
//! execute, such as a PL/SQL block failing at a row: the rows before it ran.
//! Under Autocommit a PL/SQL block runs without the commit, which follows
//! once the call succeeded (one Round trip more): a block can fail after its
//! rows ran, and a failed call never commits.

use std::collections::HashMap;
use std::rc::Rc;
use std::sync::Arc;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::ZendHashTable;
use oracledb::{
    DB_TYPE_BOOLEAN, DB_TYPE_CLOB, DB_TYPE_NUMBER, ErrorKind, ExecBatchResult, Row, ToDbValue,
};

use crate::bind::{Binds, Classes, Name, Value, out_list, put, same_name};
use crate::connection::ConnState;
use crate::error::{Error, Kind, RowFailure};
use crate::input::entries;
use crate::object::Describe;
use crate::options::Options;
use crate::temp_lob::{TempLob, Written};

/// The savepoint an Array DML call inside an Explicit transaction rolls back to.
const SAVEPOINT: &str = "oracledb_run_many";

/// What one Array DML execute gave back: the DML count and, per OUT bind,
/// one list of values per row.
pub struct Outcome {
    pub row_count: u64,
    pub out_values: ZBox<ZendHashTable>,
}

/// Runs `sql` once per row of `rows` (see the module docs).
pub fn execute(
    state: &Arc<ConnState>,
    sql: &str,
    rows: &ZendHashTable,
    options: &Options,
) -> Result<Outcome, Error> {
    // Types are described here, outside the Session's lock: reading the
    // binds may run PHP code.
    let mut rows = Rows::read(rows, &|what, lookup| state.describe(what, lookup))?;
    if rows.0.is_empty() {
        state.ensure_usable()?;
        return Ok(Outcome {
            row_count: 0,
            out_values: ZendHashTable::new(),
        });
    }
    state.timed(options.timeout, || {
        // Building parses the SQL on the client: no Round trip.
        let build = |commit: bool| {
            state.with(|session| {
                let builder = session.statement(sql)?.batch_errors().fetch_lobs();
                Ok(if commit { options.commit(builder) } else { builder }.build()?)
            })
        };
        let mut statement = build(options.autocommit)?;
        if statement.is_query() {
            return Err(Error::usage(
                "runMany() runs DML or PL/SQL, and this statement is a query; read rows with select().",
            ));
        }
        let (is_plsql, is_dml) = (statement.is_plsql(), statement.is_dml());
        // A PL/SQL block can fail after its rows ran (Implicit results, a
        // LOB OUT value): under Autocommit it runs without the commit, which
        // follows only once the call succeeded (one Round trip more).
        let commit_after = options.autocommit && is_plsql;
        if commit_after {
            drop(statement);
            statement = build(false)?;
        }
        // Every check that needs no Round trip, before the LOB writes and
        // the savepoint, which do.
        rows.matched(statement.bind_names())?;
        rows.write_lobs(state)?;
        if !options.autocommit {
            state.with(|session| {
                session.execute(&format!("savepoint {SAVEPOINT}"), &[])?;
                Ok(())
            })?;
        }
        // Under the Session's lock, as every Round trip.
        let executed = state.with(|_| {
            let placeholders = statement.bind_names().to_vec();
            let values = rows.positional(&placeholders);
            let params: Vec<&[&dyn ToDbValue]> = values.iter().map(Vec::as_slice).collect();
            statement
                .execute_batch(params.as_slice().into())
                .map_err(|e| executed_error(e, values.len()))
        });
        let mut result = match executed {
            Ok(result) => result,
            // The crate's own checks of the binds fail before the execute's
            // Round trip: nothing to undo.
            Err(e) if matches!(e.kind(), Kind::Usage | Kind::Unsupported) => return Err(e),
            Err(e) => return Err(undo(state, options, e)),
        };
        if !result.implicit_results().is_empty() {
            // Seen only once the block ran, which never committed.
            return Err(undo(
                state,
                options,
                Error::usage(
                    "runMany() cannot return Implicit results (DBMS_SQL.RETURN_RESULT); run the block with run() for each row. The block's rows are undone.",
                ),
            ));
        }
        let failures: Vec<RowFailure> = result
            .batch_errors()
            .iter()
            .map(|e| RowFailure {
                index: e.offset(),
                code: i32::try_from(e.code()).unwrap_or(0),
                message: e.message().to_string(),
            })
            .collect();
        if !failures.is_empty() {
            return Err(undo(state, options, Error::array_dml(failures, rows.0.len())));
        }
        // What the execute gave back may point at the temporary LOBs its
        // binds were written into: everything born of it keeps them.
        let options = &options.keeping(rows.take_written(state));
        let out_values = rows.out_values(&mut result, is_plsql, state, options);
        let out_values = if commit_after {
            let committed =
                out_values.and_then(|values| state.with(|session| Ok(session.commit()?)).map(|()| values));
            committed.map_err(|e| undo(state, options, e))?
        } else {
            out_values?
        };
        Ok(Outcome {
            // The server counts 1 per row for a PL/SQL block; only DML counts.
            row_count: if is_dml { result.rows_affected() } else { 0 },
            out_values,
        })
    })
}

/// The crate's error for an Array DML call of `total` rows. Past 65535
/// failing rows the server lists none, so there is no ArrayDmlException to
/// give. A placeholder written twice in SQL needs one value per occurrence,
/// which named rows cannot give yet. Types differing down a column are
/// caught before, by [`Rows::read`].
fn executed_error(e: oracledb::Error, total: usize) -> Error {
    match e.kind() {
        ErrorKind::TooManyBatchErrors(failed) => Error::new(
            Kind::Database,
            format!(
                "{failed} of {total} rows failed, more than the {} the server lists; none was applied.",
                u16::MAX
            ),
        ),
        ErrorKind::WrongNumPositionalBinds(..) => Error::unsupported(
            "runMany() cannot bind a placeholder written more than once in the SQL yet; give each occurrence a name of its own.",
        ),
        ErrorKind::DifferentTypes(..) => Error::usage(format!("runMany(): {e}")),
        _ => Error::from(e),
    }
}

/// Undoes what the rows did, then gives back `e`: a rollback under
/// Autocommit, a rollback to the savepoint inside a transaction. A Session
/// already lost has nothing to undo.
fn undo(state: &ConnState, options: &Options, e: Error) -> Error {
    if e.is_connection_lost() {
        return e;
    }
    let undone = state.with(|session| {
        if options.autocommit {
            session.rollback()?;
        } else {
            session.execute(&format!("rollback to savepoint {SAVEPOINT}"), &[])?;
        }
        Ok(())
    });
    match undone {
        Ok(()) => e,
        // The rows may still be applied: that failure is the one to report.
        Err(failure) => failure,
    }
}

/// The rows of one Array DML call, checked and made one type per column.
struct Rows(Vec<Binds>);

/// The type a column binds as, set by its first non-null value.
#[derive(Clone, PartialEq, Eq)]
enum Column {
    Number,
    Text,
    /// Text with a string over 32767 bytes somewhere.
    Clob,
    Bool,
    /// Typed binds of this signature.
    Typed(String),
}

impl Column {
    fn of(value: &Value) -> Option<Self> {
        match value {
            Value::Null(_) | Value::NullOf(_) => None,
            Value::Int(_) | Value::Number(_) => Some(Self::Number),
            Value::Text(_) => Some(Self::Text),
            Value::Clob(_) => Some(Self::Clob),
            Value::Bool(_) => Some(Self::Bool),
            Value::Typed(typed) => Some(Self::Typed(typed.signature())),
        }
    }

    /// What a column of this type takes, for messages.
    fn described(&self) -> String {
        match self {
            Self::Number => "a NUMBER (int or float)".to_string(),
            Self::Text | Self::Clob => "a string".to_string(),
            Self::Bool => "a bool".to_string(),
            Self::Typed(signature) => signature.clone(),
        }
    }
}

/// A value, for messages: its kind, never the value itself.
fn described(value: &Value) -> String {
    match value {
        Value::Null(_) | Value::NullOf(_) => "null".to_string(),
        Value::Int(_) => "an int".to_string(),
        Value::Number(_) => "a float".to_string(),
        Value::Text(_) | Value::Clob(_) => "a string".to_string(),
        Value::Bool(_) => "a bool".to_string(),
        Value::Typed(typed) => typed.signature(),
    }
}

impl Rows {
    /// Reads every row, then checks and unifies the columns.
    fn read(rows: &ZendHashTable, describe: &Describe<'_>) -> Result<Self, Error> {
        let mut read = Vec::with_capacity(rows.len());
        let classes = Classes::default();
        for (index, (_, row)) in entries(rows).enumerate() {
            let Some(row) = row.dereference().array() else {
                return Err(Error::usage(format!(
                    "Row {index} is not an array; each row is an array of binds keyed by placeholder name."
                )));
            };
            let binds = Binds::read_with(row, describe, &classes, read.first())
                .map_err(|e| e.prefixed(format!("Row {index}")))?;
            read.push(binds);
        }
        let mut rows = Self(read);
        rows.check_keys()?;
        rows.unify()?;
        Ok(rows)
    }

    fn first(&self) -> &Binds {
        &self.0[0]
    }

    /// Whether `binds` has the first row's keys, written the same and in
    /// the same order: then each column is at the same position. The usual
    /// case, checked without comparing names case-insensitively.
    fn like_first(&self, binds: &Binds) -> bool {
        let first = self.first();
        binds.0.len() == first.0.len()
            && binds.0.iter().zip(&first.0).all(|((a, _), (b, _))| a == b)
    }

    /// Each row's values in the order of the statement's placeholders, for
    /// a positional execute (see [`Binds::positional`]).
    fn positional(&self, placeholders: &[String]) -> Vec<Vec<&dyn ToDbValue>> {
        let first = self.first();
        // Where each placeholder's value is in the first row.
        let order: Vec<usize> = placeholders
            .iter()
            .filter_map(|placeholder| {
                first
                    .0
                    .iter()
                    .position(|(name, _)| same_name(name, placeholder))
            })
            .collect();
        self.0
            .iter()
            .map(|binds| {
                if self.like_first(binds) {
                    order.iter().map(|&i| binds.0[i].1.as_db_value()).collect()
                } else {
                    binds.positional(placeholders)
                }
            })
            .collect()
    }

    /// Every row has the keys of the first, ignoring case and order.
    fn check_keys(&self) -> Result<(), Error> {
        let Some(first) = self.0.first() else {
            return Ok(());
        };
        let keys = |binds: &Binds| -> HashMap<String, Name> {
            binds
                .0
                .iter()
                .map(|(name, _)| (name.to_uppercase(), name.clone()))
                .collect()
        };
        let mut expected = None;
        for (index, binds) in self.0.iter().enumerate().skip(1) {
            if self.like_first(binds) {
                continue;
            }
            let expected = expected.get_or_insert_with(|| keys(first));
            let given = keys(binds);
            if let Some((_, name)) = expected.iter().find(|(key, _)| !given.contains_key(*key)) {
                return Err(Error::usage(format!(
                    "Row {index} has no bind '{name}', which row 0 has; every row needs the same keys."
                )));
            }
            if let Some((_, name)) = given.iter().find(|(key, _)| !expected.contains_key(*key)) {
                return Err(Error::usage(format!(
                    "Row {index} has a bind '{name}', which row 0 has not; every row needs the same keys."
                )));
            }
        }
        Ok(())
    }

    /// Gives each column one type: fails for a mix, makes a column with a
    /// long string CLOB, and makes each NULL one of its column's type.
    fn unify(&mut self) -> Result<(), Error> {
        let Some(first) = self.0.first() else {
            return Ok(());
        };
        let names: Vec<Name> = first.0.iter().map(|(name, _)| Rc::clone(name)).collect();
        for (column_index, name) in names.iter().enumerate() {
            let cell = |binds: &Binds| match binds.0.get(column_index) {
                Some((n, _)) if n == name => Some(column_index),
                _ => binds.0.iter().position(|(n, _)| same_name(n, name)),
            };
            // The column's type and the row that set it, and the first
            // plain null.
            let mut column: Option<(Column, usize)> = None;
            let mut first_null = None;
            let mut bind = Name::from("");
            for (index, binds) in self.0.iter().enumerate() {
                let Some(position) = cell(binds) else {
                    continue;
                };
                let (name, value) = &binds.0[position];
                bind.clone_from(name);
                if let Value::Typed(typed) = value
                    && typed.is_cursor()
                {
                    return Err(Error::usage(format!(
                        "Row {index}, bind '{name}': runMany() cannot bring back a REF CURSOR; run the block with run() for each row."
                    )));
                }
                let Some(this) = Column::of(value) else {
                    first_null.get_or_insert(index);
                    continue;
                };
                column = match column {
                    None => Some((this, index)),
                    Some((current, set_by)) => match (&current, &this) {
                        (a, b) if a == b => Some((current, set_by)),
                        (Column::Text, Column::Clob) => Some((Column::Clob, set_by)),
                        (Column::Clob, Column::Text) => Some((current, set_by)),
                        _ => {
                            return Err(Error::usage(format!(
                                "Row {index}, bind '{name}': {}, but row {set_by} made the column {}; a column takes one type (an int and a float mix as NUMBER).",
                                described(value),
                                current.described()
                            )));
                        }
                    },
                };
            }
            if let (Some((Column::Typed(signature), _)), Some(row)) = (&column, first_null) {
                return Err(Error::usage(format!(
                    "Row {row}, bind '{bind}': a plain null in a column of {signature}; bind the NULL with that typed bind too."
                )));
            }
            let null = match column.map(|(column, _)| column) {
                Some(Column::Number) => Some(DB_TYPE_NUMBER),
                Some(Column::Bool) => Some(DB_TYPE_BOOLEAN),
                Some(Column::Clob) => Some(DB_TYPE_CLOB),
                Some(Column::Text | Column::Typed(_)) | None => None,
            };
            let clob = null == Some(DB_TYPE_CLOB);
            for binds in &mut self.0 {
                let Some(position) = cell(binds) else {
                    continue;
                };
                let value = &mut binds.0[position].1;
                match value {
                    Value::Null(_) => {
                        if let Some(null) = null {
                            *value = Value::NullOf(null);
                        }
                    }
                    // '' is NULL in VARCHAR2: it stays NULL in a CLOB column.
                    Value::Text(text) if clob && text.is_empty() => {
                        *value = Value::NullOf(DB_TYPE_CLOB);
                    }
                    Value::Text(text) if clob => *value = Value::Clob(TempLob::text(text)?),
                    _ => {}
                }
            }
        }
        Ok(())
    }

    /// Checks the first row's binds against the statement's placeholders
    /// (every row has its keys).
    fn matched(&self, placeholders: &[String]) -> Result<(), Error> {
        self.first().matched(placeholders).map(|_| ())
    }

    fn write_lobs(&mut self, state: &ConnState) -> Result<(), Error> {
        for (index, binds) in self.0.iter_mut().enumerate() {
            binds
                .write_lobs(state)
                .map_err(|e| e.prefixed(format!("Row {index}")))?;
        }
        Ok(())
    }

    fn take_written(&mut self, state: &ConnState) -> Option<Arc<Written>> {
        let lobs = self.0.iter_mut().flat_map(Binds::take_lobs).collect();
        Written::keep(state, lobs)
    }

    /// Per OUT or IN OUT bind, one list per row: the value a PL/SQL block
    /// gave back, or the rows `RETURNING` gave back.
    fn out_values(
        &self,
        result: &mut ExecBatchResult,
        is_plsql: bool,
        state: &Arc<ConnState>,
        options: &Options,
    ) -> Result<ZBox<ZendHashTable>, Error> {
        let mut out = ZendHashTable::new();
        let first = self.first();
        if !first.has_out() {
            return Ok(out);
        }
        let mut rows: Vec<Vec<Row>> = if is_plsql {
            result
                .out_bind_data()
                .into_iter()
                .map(|row| vec![row])
                .collect()
        } else {
            result.returned_data()
        };
        for (name, value) in first.0.iter().filter(|(_, value)| value.is_out()) {
            let mut lists = ZendHashTable::with_capacity(u32::try_from(rows.len()).unwrap_or(0));
            for returned in &mut rows {
                lists
                    .push(out_list(returned, name, value, state, options)?)
                    .map_err(put)?;
            }
            out.insert(&**name, lists).map_err(put)?;
        }
        Ok(out)
    }
}
