//! The arrays of scalars that cross the seam (`$pool`, `$session`,
//! `$options`, `$tracing`; spec #32 §14), read strictly: exactly the
//! documented keys, each with its documented type. Anything else is a `usage`
//! error, raised before any Round trip.

use ext_php_rs::types::{ArrayKey, ZendHashTable, Zval};

use crate::error::Error;

/// A strict view of one array: every key known, every known key present.
pub struct Fields<'a> {
    what: &'static str,
    table: &'a ZendHashTable,
}

impl<'a> Fields<'a> {
    pub fn new(what: &'static str, table: &'a ZendHashTable, keys: &[&str]) -> Result<Self, Error> {
        for (key, _) in table {
            let known = match &key {
                ArrayKey::String(k) => keys.contains(&k.as_str()),
                ArrayKey::Str(k) => keys.contains(k),
                ArrayKey::ZendString(k) => k.as_str().is_ok_and(|k| keys.contains(&k)),
                ArrayKey::Long(_) => false,
            };
            if !known {
                return Err(Error::usage(format!(
                    "Unknown key '{key}' in {what}; the keys are: {}.",
                    keys.join(", ")
                )));
            }
        }
        for key in keys {
            if table.get(*key).is_none() {
                return Err(Error::usage(format!("Missing key '{key}' in {what}.")));
            }
        }
        Ok(Self { what, table })
    }

    fn value(&self, key: &str) -> &'a Zval {
        // Every known key is present: checked in new().
        self.table.get(key).expect("key checked in Fields::new")
    }

    fn wrong(&self, key: &str, expected: &str) -> Error {
        Error::usage(format!("'{key}' in {} must be {expected}.", self.what))
    }

    pub fn string(&self, key: &str) -> Result<&'a str, Error> {
        self.value(key)
            .str()
            .ok_or_else(|| self.wrong(key, "a string"))
    }

    pub fn optional_string(&self, key: &str) -> Result<Option<&'a str>, Error> {
        let value = self.value(key);
        if value.is_null() {
            Ok(None)
        } else {
            self.string(key).map(Some)
        }
    }

    pub fn int(&self, key: &str) -> Result<i64, Error> {
        self.value(key)
            .long()
            .ok_or_else(|| self.wrong(key, "an int"))
    }

    pub fn optional_int(&self, key: &str) -> Result<Option<i64>, Error> {
        let value = self.value(key);
        if value.is_null() {
            Ok(None)
        } else {
            self.int(key).map(Some)
        }
    }

    pub fn bool(&self, key: &str) -> Result<bool, Error> {
        let value = self.value(key);
        if value.is_bool() {
            Ok(value.bool().unwrap_or(false))
        } else {
            Err(self.wrong(key, "a bool"))
        }
    }

    /// A string that must be one of `choices` (an enum's backing values).
    pub fn choice(&self, key: &str, choices: &[&'static str]) -> Result<&'static str, Error> {
        let value = self.string(key).ok();
        value
            .and_then(|v| choices.iter().find(|c| **c == v).copied())
            .ok_or_else(|| self.wrong(key, &format!("one of '{}'", choices.join("', '"))))
    }

    pub fn array(&self, key: &str) -> Result<&'a ZendHashTable, Error> {
        self.value(key)
            .array()
            .ok_or_else(|| self.wrong(key, "an array"))
    }
}
