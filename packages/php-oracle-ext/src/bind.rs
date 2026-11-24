//! Binds, read from the `$binds` array as Core passes them: keyed by
//! placeholder name, holding plain PHP values.
//!
//! For now: `int` binds as NUMBER and `string` as VARCHAR2; any other value
//! is `unsupported`.

use ext_php_rs::types::ZendHashTable;
use oracledb::ToDbValue;

use crate::error::Error;

enum Value {
    Int(i64),
    Text(String),
}

impl Value {
    fn as_db_value(&self) -> &dyn ToDbValue {
        match self {
            Value::Int(v) => v,
            Value::Text(v) => v,
        }
    }
}

/// Owned bind values, alive for the whole execute.
pub struct Binds(Vec<(String, Value)>);

impl Binds {
    pub fn read(binds: &ZendHashTable) -> Result<Self, Error> {
        let mut values = Vec::with_capacity(binds.len());
        for (key, zval) in binds {
            let name = key.to_string();
            let value = if let Some(v) = zval.long() {
                Value::Int(v)
            } else if let Some(v) = zval.str() {
                Value::Text(v.to_string())
            } else {
                return Err(Error::unsupported(format!(
                    "Bind '{name}': a {} value cannot be bound yet.",
                    zval.get_type()
                )));
            };
            values.push((name, value));
        }
        Ok(Self(values))
    }

    /// The binds as the crate takes them.
    pub fn params(&self) -> Vec<(&str, &dyn ToDbValue)> {
        self.0
            .iter()
            .map(|(name, value)| (name.as_str(), value.as_db_value()))
            .collect()
    }
}
