//! `Type`'s backing values (Core's `Rajska\Oracle\Type`): the name of each
//! Oracle type in a Column's `type` and a typed bind's `type`. The one table
//! every module reads them from. Objects, Collections and Associative arrays
//! share one crate type and are named apart by their Object type.

use oracledb::{
    DB_TYPE_BFILE, DB_TYPE_BINARY_DOUBLE, DB_TYPE_BINARY_FLOAT, DB_TYPE_BINARY_INTEGER,
    DB_TYPE_BLOB, DB_TYPE_BOOLEAN, DB_TYPE_CHAR, DB_TYPE_CLOB, DB_TYPE_CURSOR, DB_TYPE_DATE,
    DB_TYPE_INTERVAL_DS, DB_TYPE_INTERVAL_YM, DB_TYPE_JSON, DB_TYPE_LONG, DB_TYPE_LONG_RAW,
    DB_TYPE_NCHAR, DB_TYPE_NCLOB, DB_TYPE_NUMBER, DB_TYPE_NVARCHAR, DB_TYPE_RAW, DB_TYPE_ROWID,
    DB_TYPE_TIMESTAMP, DB_TYPE_TIMESTAMP_LTZ, DB_TYPE_TIMESTAMP_TZ, DB_TYPE_UROWID,
    DB_TYPE_VARCHAR, DB_TYPE_VECTOR, DB_TYPE_XMLTYPE, DbType,
};

static NAMES: [(&DbType, &str); 28] = [
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

/// The name of a type, or `None` for an Object type's or one the server
/// should never describe.
pub fn of(db_type: &DbType) -> Option<&'static str> {
    NAMES
        .iter()
        .find(|(candidate, _)| db_type == *candidate)
        .map(|(_, name)| *name)
}

/// The type a name names.
pub fn db_type(name: &str) -> Option<&'static DbType> {
    NAMES
        .iter()
        .find(|(_, candidate)| name == *candidate)
        .map(|(db_type, _)| *db_type)
}
