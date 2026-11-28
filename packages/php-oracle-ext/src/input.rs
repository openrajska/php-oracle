//! The arrays of scalars that cross the seam (`$pool`, `$session`,
//! `$options`, `$tracing`; spec #32 §14), read strictly: exactly the
//! documented keys, each with its documented type. Anything else is a `usage`
//! error, raised before any Round trip.

use std::time::Duration;

use ext_php_rs::flags::DataType;
use ext_php_rs::types::{ArrayKey, ZendHashTable, ZendStr, Zval};

use crate::error::Error;

/// `HASH_FLAG_PACKED` (Zend/zend_hash.h): a list, stored as bare zvals
/// keyed by position.
const HASH_FLAG_PACKED: u32 = 1 << 2;

/// Every entry of a PHP array, in order, with its key borrowed from the
/// array. ext-php-rs's own iterator copies every string key into a `String`
/// first; this one reads the buckets, as `ZEND_HASH_FOREACH` does, and gives
/// a UTF-8 key as [`ArrayKey::Str`] (other bytes as
/// [`ArrayKey::ZendString`], integer keys as [`ArrayKey::Long`]).
pub fn entries(table: &ZendHashTable) -> impl Iterator<Item = (ArrayKey<'_>, &Zval)> {
    // SAFETY: reading the flags of a live array.
    let packed = unsafe { table.u.flags } & HASH_FLAG_PACKED != 0;
    let used = table.nNumUsed as usize;
    (0..used).filter_map(move |index| {
        // SAFETY: the first `nNumUsed` slots of a live array are
        // initialised (a removed one is IS_UNDEF), as zvals when packed and
        // as buckets otherwise; the array outlives the borrow, and PHP code
        // run meanwhile cannot change it (it is shared, so a write copies).
        let (key, value) = unsafe {
            if packed {
                let value = &*table.__bindgen_anon_1.arPacked.add(index);
                (ArrayKey::Long(index as i64), value)
            } else {
                let bucket = &*table.__bindgen_anon_1.arData.add(index);
                let key = match bucket.key.as_ref() {
                    #[allow(clippy::cast_possible_wrap)]
                    None => ArrayKey::Long(bucket.h as i64),
                    Some(key) => {
                        let key: &ZendStr = key;
                        match key.as_str() {
                            Ok(key) => ArrayKey::Str(key),
                            Err(_) => ArrayKey::ZendString(key),
                        }
                    }
                };
                (key, &bucket.val)
            }
        };
        (!matches!(value.get_type(), DataType::Undef)).then_some((key, value))
    })
}

/// A strict view of one array: every key known, every known key present.
pub struct Fields<'a> {
    what: &'static str,
    keys: &'a [&'a str],
    /// The value of each of `keys`, read in one pass over the array.
    values: Vec<&'a Zval>,
}

impl<'a> Fields<'a> {
    pub fn new(
        what: &'static str,
        table: &'a ZendHashTable,
        keys: &'a [&'a str],
    ) -> Result<Self, Error> {
        let mut values: Vec<Option<&'a Zval>> = vec![None; keys.len()];
        for (key, value) in entries(table) {
            let index = match &key {
                ArrayKey::String(k) => keys.iter().position(|known| *known == k.as_str()),
                ArrayKey::Str(k) => keys.iter().position(|known| known == k),
                ArrayKey::ZendString(k) => k
                    .as_str()
                    .ok()
                    .and_then(|k| keys.iter().position(|known| *known == k)),
                ArrayKey::Long(_) => None,
            };
            let Some(index) = index else {
                return Err(Error::usage(format!(
                    "Unknown key '{key}' in {what}; the keys are: {}.",
                    keys.join(", ")
                )));
            };
            values[index] = Some(value);
        }
        let values = values
            .into_iter()
            .zip(keys)
            .map(|(value, key)| {
                value.ok_or_else(|| Error::usage(format!("Missing key '{key}' in {what}.")))
            })
            .collect::<Result<_, _>>()?;
        Ok(Self { what, keys, values })
    }

    fn value(&self, key: &str) -> &'a Zval {
        let index = self.keys.iter().position(|known| *known == key);
        self.values[index.expect("key checked in Fields::new")]
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

    /// A duration in milliseconds, at least 1; `null` is no limit.
    pub fn optional_milliseconds(&self, key: &str) -> Result<Option<Duration>, Error> {
        match self.optional_int(key)? {
            None => Ok(None),
            Some(ms) => u64::try_from(ms)
                .ok()
                .filter(|ms| *ms >= 1)
                .map(|ms| Some(Duration::from_millis(ms)))
                .ok_or_else(|| self.wrong(key, "at least 1 (ms), or null for no limit")),
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
