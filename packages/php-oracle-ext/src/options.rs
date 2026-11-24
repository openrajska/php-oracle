//! `$options`: what Core resolved for one execute (spec #32 §13, §14). Every
//! Cursor born of that execute keeps them for its whole life.

use ext_php_rs::types::ZendHashTable;

use crate::error::Error;
use crate::input::Fields;

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

/// The options of one execute, read from `$options`.
#[derive(Clone, Copy)]
pub struct Options {
    pub fetch_size: u32,
    pub numbers: NumberMode,
    pub row_shape: RowShape,
    pub key_case: KeyCase,
}

impl Options {
    pub fn read(options: &ZendHashTable) -> Result<Self, Error> {
        let fields = Fields::new("$options", options, OPTION_KEYS)?;
        fields.bool("autocommit")?;
        fields.optional_int("timeout")?;
        fields.choice("temporals", &["object", "string"])?;
        fields.choice("lobs", &["locator", "inline"])?;
        let numbers = match fields.choice("numbers", &["exact", "float"])? {
            "float" => NumberMode::Float,
            _ => NumberMode::Exact,
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
            fetch_size,
            numbers,
            row_shape,
            key_case,
        })
    }
}
