//! `$options`: what Core resolved for one execute (spec #32 §13, §14). Every
//! Cursor born of that execute keeps them for its whole life.

use std::sync::Arc;
use std::time::Duration;

use ext_php_rs::types::ZendHashTable;
use oracledb::StatementBuilder;

use crate::error::Error;
use crate::input::Fields;
use crate::temp_lob::Written;

const OPTION_KEYS: &[&str] = &[
    "autocommit",
    "timeout",
    "fetchSize",
    "numbers",
    "temporals",
    "lobs",
    "rowShape",
    "keyCase",
];

/// How a non-integral NUMBER reaches PHP.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum NumberMode {
    /// The exact canonical string.
    Exact,
    /// A `float`.
    Float,
}

/// How DATE, TIMESTAMP, TSTZ and TSLTZ values reach PHP.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum TemporalMode {
    /// A `DateTimeImmutable`.
    Object,
    /// NLS-independent ISO text at full precision.
    String,
}

/// How CLOB, NCLOB and BLOB values reach PHP.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum LobMode {
    /// A native `Lob` over the locator, read chunk by chunk.
    Locator,
    /// The whole value as a string, read through the locator at fetch.
    Inline,
}

/// Rows keyed by column name, or lists in column order.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum RowShape {
    Assoc,
    List,
}

/// Column names as Oracle reports them, or lowercase.
#[derive(Clone, Copy, PartialEq, Eq)]
pub enum KeyCase {
    Native,
    Lower,
}

/// The options of one execute, read from `$options`, and what the Cursors
/// and Lobs born of it keep alive with them.
#[derive(Clone)]
pub struct Options {
    /// Commit with the execute: Core sets it outside an Explicit transaction.
    pub autocommit: bool,
    /// The Call timeout for every Round trip of the execute and of the
    /// Cursors born of it; `None` is no limit.
    pub timeout: Option<Duration>,
    pub fetch_size: u32,
    pub numbers: NumberMode,
    pub temporals: TemporalMode,
    pub lobs: LobMode,
    pub row_shape: RowShape,
    pub key_case: KeyCase,
    /// The temporary LOBs the execute's binds were written into: what it
    /// gave back may point at them.
    pub written: Option<Arc<Written>>,
}

impl Options {
    pub fn read(options: &ZendHashTable) -> Result<Self, Error> {
        let fields = Fields::new("$options", options, OPTION_KEYS)?;
        let autocommit = fields.bool("autocommit")?;
        let timeout = fields.optional_milliseconds("timeout")?;
        let lobs = match fields.choice("lobs", &["locator", "inline"])? {
            "inline" => LobMode::Inline,
            _ => LobMode::Locator,
        };
        let numbers = match fields.choice("numbers", &["exact", "float"])? {
            "float" => NumberMode::Float,
            _ => NumberMode::Exact,
        };
        let temporals = match fields.choice("temporals", &["object", "string"])? {
            "string" => TemporalMode::String,
            _ => TemporalMode::Object,
        };
        let row_shape = match fields.choice("rowShape", &["assoc", "list"])? {
            "list" => RowShape::List,
            _ => RowShape::Assoc,
        };
        let key_case = match fields.choice("keyCase", &["native", "lower"])? {
            "lower" => KeyCase::Lower,
            _ => KeyCase::Native,
        };
        let fetch_size = fields.int("fetchSize")?;
        let fetch_size = u32::try_from(fetch_size)
            .ok()
            .filter(|n| *n >= 1)
            .ok_or_else(|| Error::usage("'fetchSize' in $options must be at least 1."))?;
        Ok(Self {
            autocommit,
            timeout,
            fetch_size,
            numbers,
            temporals,
            lobs,
            row_shape,
            key_case,
            written: None,
        })
    }

    /// These options, keeping the temporary LOBs an execute wrote.
    pub fn keeping(&self, written: Option<Arc<Written>>) -> Self {
        Self {
            written,
            ..self.clone()
        }
    }

    /// Under Autocommit, the statement commits in the execute's own Round
    /// trip (C21), and a failed execute commits nothing. Every statement an
    /// execute or an executeMany builds goes through it.
    pub fn commit<'sql>(&self, builder: StatementBuilder<'sql>) -> StatementBuilder<'sql> {
        if self.autocommit {
            builder.autocommit()
        } else {
            builder
        }
    }
}
