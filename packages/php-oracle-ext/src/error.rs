//! Errors, and how they cross into PHP as `Rajska\Oracle\Extension\Exception`.
//!
//! Every native method body runs through [`run`]: it catches panics, and turns
//! an [`Error`] into the native exception, whose `kind` tells Core's
//! `ExceptionTranslator` which public class to throw (spec #32 §12, §14).

use std::any::Any;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::time::Duration;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::class::RegisteredClass;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::{PhpException, PhpResult, throw_object};
use ext_php_rs::ffi::zend_class_entry;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendHashTable};
use ext_php_rs::zend::{ClassEntry, ExecutorGlobals};

/// What went wrong, as the native exception's `kind` names it.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum Kind {
    /// A server error raised by a statement.
    #[default]
    Database,
    /// No Session was obtained.
    Connection,
    /// The Session is gone (Broken Connection).
    ConnectionLost,
    /// No Session of the Pool became free within `acquireTimeout`.
    PoolExhausted,
    /// A Round trip took longer than the Call timeout.
    Timeout,
    /// A config value the Backend cannot use.
    Configuration,
    /// A feature that is not available (yet) was reached.
    Unsupported,
    /// A programmer mistake, detected before any Round trip.
    Usage,
}

impl Kind {
    pub fn as_str(self) -> &'static str {
        match self {
            Kind::Database => "database",
            Kind::Connection => "connection",
            Kind::ConnectionLost => "connection_lost",
            Kind::PoolExhausted => "pool_exhausted",
            Kind::Timeout => "timeout",
            Kind::Configuration => "configuration",
            Kind::Unsupported => "unsupported",
            Kind::Usage => "usage",
        }
    }
}

/// An error on its way to PHP.
#[derive(Debug)]
pub struct Error {
    kind: Kind,
    code: i32,
    message: String,
    offset: Option<i64>,
    /// The Session is lost although the kind is not `connection_lost`: a
    /// Call timeout whose break the server ignored.
    lost: bool,
    /// A PHP exception is already pending (thrown by user code the
    /// Extension called): it goes to the caller as is.
    pending: bool,
}

impl Error {
    pub fn new(kind: Kind, message: impl Into<String>) -> Self {
        Self {
            kind,
            code: 0,
            message: message.into(),
            offset: None,
            lost: false,
            pending: false,
        }
    }

    pub fn usage(message: impl Into<String>) -> Self {
        Self::new(Kind::Usage, message)
    }

    pub fn unsupported(message: impl Into<String>) -> Self {
        Self::new(Kind::Unsupported, message)
    }

    /// User code the Extension called threw: its exception is pending, and
    /// it reaches the caller unchanged.
    pub fn php_exception() -> Self {
        Self {
            pending: true,
            ..Self::new(Kind::Usage, "")
        }
    }

    /// An error of the crate while opening a Session: `connection`, keeping the
    /// ORA code (1017 for a wrong password).
    ///
    /// A connect string, alias or wallet the crate cannot use is
    /// `configuration` instead.
    pub fn connect(e: oracledb::Error) -> Self {
        use oracledb::ErrorKind as E;
        let kind = match e.kind() {
            E::InvalidConnectString(..)
            | E::InvalidDescriptorNode(..)
            | E::InvalidNetworkName(_)
            | E::TnsAliasNotFound(..)
            | E::NoConfigDir
            | E::NoConnectString
            | E::NoCredentials
            | E::IfileCycleDetected(..)
            | E::WalletPasswordMissingOrInvalid(..)
            | E::WalletPrivateKeyInvalid(_)
            | E::WalletUnreadable(_)
            | E::PemFileOperation => Kind::Configuration,
            _ => Kind::Connection,
        };
        Self {
            kind,
            ..Self::from(e)
        }
    }

    /// The transport connect ran out of `connectTimeout` (ms).
    pub fn connect_timeout(milliseconds: i64) -> Self {
        Self {
            code: 12170,
            ..Self::new(
                Kind::Connection,
                format!(
                    "ORA-12170: Cannot connect. The connectTimeout of {milliseconds} ms went by before the transport connected."
                ),
            )
        }
    }

    /// A later call on a Broken Connection: the error that broke it, with
    /// its code, after `Connection is broken: `.
    pub fn broken(code: i32, message: &str) -> Self {
        Self {
            code,
            ..Self::new(
                Kind::ConnectionLost,
                format!("Connection is broken: {message}"),
            )
        }
    }

    /// A Round trip over the Call timeout `limit`. With `lost`, the server
    /// ignored the break, and the Session is gone with it.
    pub fn timeout(limit: Duration, lost: bool) -> Self {
        let message = format!("Call timeout of {} ms exceeded.", limit.as_millis());
        Self {
            lost,
            ..Self::new(
                Kind::Timeout,
                if lost {
                    format!("{message} The server did not stop the call, so the Session is lost.")
                } else {
                    message
                },
            )
        }
    }

    /// Whether the Session is lost: the Connection is Broken.
    pub fn is_connection_lost(&self) -> bool {
        self.kind == Kind::ConnectionLost || self.lost
    }

    pub fn kind(&self) -> Kind {
        self.kind
    }

    pub fn code(&self) -> i32 {
        self.code
    }

    pub fn message(&self) -> &str {
        &self.message
    }

    /// The same error, its message after `what` ("Bind 'x': ").
    pub fn prefixed(self, what: impl std::fmt::Display) -> Self {
        Self {
            message: format!("{what}: {}", self.message),
            ..self
        }
    }

    /// A panic caught at the PHP boundary.
    fn panic(payload: Box<dyn Any + Send>) -> Self {
        let text = payload
            .downcast_ref::<&str>()
            .map(|s| (*s).to_string())
            .or_else(|| payload.downcast_ref::<String>().cloned())
            .unwrap_or_else(|| "non-string panic payload".to_string());
        Self::new(
            Kind::ConnectionLost,
            format!("internal error in rajska_oracle: {text}"),
        )
    }

    /// Throws this error in PHP as the native exception.
    ///
    /// The engine creates the exception (so message and code are set the way
    /// PHP sets them), then the Rust side of the object gets its `kind`,
    /// `offset` and `rows`. The returned value is only there to satisfy
    /// `PhpResult`: with an exception pending, throwing it does nothing.
    fn throw(self) -> PhpException {
        if self.pending && ExecutorGlobals::has_exception() {
            return PhpException::from_message(String::new());
        }
        let ce = <NativeException as RegisteredClass>::get_metadata().ce();
        PhpException::new(self.message, self.code, ce).throw();
        if let Some(mut object) = ExecutorGlobals::take_exception() {
            if let Some(native) = ZendClassObject::<NativeException>::from_zend_obj_mut(&mut object)
            {
                native.initialize(NativeException {
                    kind: self.kind,
                    offset: self.offset,
                });
            }
            // Neither step fails for an object.
            if let Ok(zval) = object.into_zval(false) {
                let _ = throw_object(zval);
            }
        }
        PhpException::from_message(String::new())
    }
}

impl From<oracledb::Error> for Error {
    fn from(e: oracledb::Error) -> Self {
        match e.kind() {
            oracledb::ErrorKind::DbError(db) => Self {
                kind: kind_of_code(db.code()),
                code: i32::try_from(db.code()).unwrap_or(0),
                message: db.message().to_string(),
                offset: i64::try_from(db.offset()).ok(),
                lost: false,
                pending: false,
            },
            other => Self::new(kind_of(other), described(&e)),
        }
    }
}

/// A crate error's message with its causes: "stream operation failed" alone
/// does not tell an unknown certificate issuer from a refused connection.
fn described(e: &oracledb::Error) -> String {
    let mut message = e.to_string();
    let mut cause = std::error::Error::source(e);
    while let Some(c) = cause {
        message = format!("{message}: {c}");
        cause = c.source();
    }
    message
}

/// The server errors that mean the Session is lost: the Broken set of spec
/// #32 §11.
const BROKEN_CODES: [usize; 28] = [
    22, 28, 31, 45, 378, 600, 602, 603, 609, 1012, 1041, 1043, 1089, 1092, 2396, 3113, 3114, 3122,
    3135, 12153, 12537, 12547, 12570, 12572, 12583, 27146, 28511, 56600,
];

/// ORA-12170 (connect timeout): a connect error, never Broken.
const CONNECT_TIMEOUT: usize = 12170;

/// What a server error is, by its ORA code.
fn kind_of_code(code: usize) -> Kind {
    if BROKEN_CODES.contains(&code) {
        Kind::ConnectionLost
    } else if code == CONNECT_TIMEOUT {
        Kind::Connection
    } else {
        Kind::Database
    }
}

/// What a crate error is: the crate's errors for a lost or unreadable
/// connection are Broken too (spec #32 §11).
fn kind_of(kind: &oracledb::ErrorKind) -> Kind {
    use oracledb::ErrorKind as E;
    match kind {
        E::DeadConnection
        | E::UnableToRecover
        | E::StreamOperation
        | E::TlsOperation
        | E::UnexpectedError
        | E::UnknownTtcMessageType(..)
        | E::UnknownServerSidePiggyback(_)
        | E::OutOfData
        | E::ParseError(..) => Kind::ConnectionLost,
        E::DbError(db) => kind_of_code(db.code()),
        // A feature the crate lacks, such as a TSLTZ value under a named
        // DBTIMEZONE, or an Object with an attribute of a later type, or an
        // instance of a subtype: fails loudly, never with wrong data.
        E::NotImplemented(_)
        | E::UnsupportedDbType(_)
        | E::UnsupportedTdsType(_)
        | E::UnsupportedObjectMetadataType(_)
        | E::UnsupportedDbObjectAttributeType(..)
        | E::UnsupportedDbObjectSubtype(_) => Kind::Unsupported,
        // A value that does not fit an Object type, checked on the client
        // before any Round trip.
        E::WrongDbObjectTypeKind(..)
        | E::CannotStoreDbObjectValue(..)
        | E::MissingDbObjectType(_) => Kind::Usage,
        E::CallTimeoutExceeded => Kind::Timeout,
        _ => Kind::Database,
    }
}

/// Runs a native method body: an `Err` becomes the native exception, and a
/// panic becomes one of kind `connection_lost` instead of crossing into PHP.
pub fn run<T>(body: impl FnOnce() -> Result<T, Error>) -> PhpResult<T> {
    run_seeing(body, |_| {})
}

/// [`run`], showing `seen` the error (a caught panic included) before it is
/// thrown.
pub fn run_seeing<T>(
    body: impl FnOnce() -> Result<T, Error>,
    seen: impl FnOnce(&Error),
) -> PhpResult<T> {
    let error = match catch_unwind(AssertUnwindSafe(body)) {
        Ok(Ok(value)) => return Ok(value),
        Ok(Err(e)) => e,
        Err(payload) => Error::panic(payload),
    };
    seen(&error);
    Err(error.throw())
}

unsafe extern "C" {
    /// SPL's `RuntimeException`, registered before any shared extension loads.
    static spl_ce_RuntimeException: *mut zend_class_entry;
}

fn runtime_exception() -> &'static ClassEntry {
    // SAFETY: SPL is always compiled in and registers the class at startup,
    // before shared extensions; the pointer stays valid for the process.
    unsafe { spl_ce_RuntimeException.as_ref() }.expect("SPL registers RuntimeException")
}

/// `Rajska\Oracle\Extension\Exception`: thrown by every native method.
/// Only the Extension creates it; Core translates it and never shows it.
#[php_class]
#[php(name = "Rajska\\Oracle\\Extension\\Exception")]
#[php(extends(ce = runtime_exception, stub = "\\RuntimeException"))]
#[php(flags = ClassFlags::Final)]
// Default: the engine creates the object (with message and code) before
// throw() fills in the rest.
#[derive(Default)]
pub struct NativeException {
    kind: Kind,
    offset: Option<i64>,
}

#[php_impl]
impl NativeException {
    /// `database`, `connection`, `connection_lost`, `pool_exhausted`,
    /// `timeout`, `unsupported`, `usage`, `configuration` or `batch`.
    #[php(getter)]
    pub fn kind(&self) -> String {
        self.kind.as_str().to_string()
    }

    /// The server's error offset, for a `database` error.
    #[php(getter)]
    pub fn offset(&self) -> Option<i64> {
        self.offset
    }

    /// One entry per failing row of a `batch` error:
    /// `list<array{index: int, code: int, message: string}>`.
    #[php(getter)]
    pub fn rows(&self) -> ZBox<ZendHashTable> {
        ZendHashTable::new()
    }
}

#[cfg(test)]
mod tests {
    //! Errors 7: the Broken set, classified from synthetic errors a real
    //! database cannot all provoke.

    use oracledb::ErrorKind;

    use super::{Kind, kind_of, kind_of_code};

    /// Spec #32 §11, as written there.
    const SPEC_BROKEN_CODES: [usize; 28] = [
        22, 28, 31, 45, 378, 600, 602, 603, 609, 1012, 1041, 1043, 1089, 1092, 2396, 3113, 3114,
        3122, 3135, 12153, 12537, 12547, 12570, 12572, 12583, 27146, 28511, 56600,
    ];

    #[test]
    fn every_broken_ora_code_classifies_as_connection_lost() {
        for code in SPEC_BROKEN_CODES {
            assert_eq!(kind_of_code(code), Kind::ConnectionLost, "ORA-{code:05}");
        }
    }

    #[test]
    fn ora_12170_classifies_as_connection() {
        assert_eq!(kind_of_code(12170), Kind::Connection);
    }

    #[test]
    fn other_ora_codes_classify_as_database() {
        for code in [1, 54, 904, 942, 1001, 1002, 1013, 1017, 2091, 22165] {
            assert_eq!(kind_of_code(code), Kind::Database, "ORA-{code:05}");
        }
    }

    /// `UnknownTtcMessageType` is not here: its payload type is private to
    /// the crate, so it cannot be built outside it.
    #[test]
    fn every_broken_error_kind_classifies_as_connection_lost() {
        for kind in [
            ErrorKind::DeadConnection,
            ErrorKind::UnableToRecover,
            ErrorKind::StreamOperation,
            ErrorKind::TlsOperation,
            ErrorKind::UnexpectedError,
            ErrorKind::UnknownServerSidePiggyback(0),
            ErrorKind::OutOfData,
            ErrorKind::ParseError("x".to_string(), 0),
        ] {
            assert_eq!(kind_of(&kind), Kind::ConnectionLost, "{kind:?}");
        }
    }

    /// Values 6: a TSLTZ value under a named DBTIMEZONE fails in the crate
    /// as not implemented (`DBTIMEZONE` is set at database creation, so the
    /// suite's database cannot provoke it).
    #[test]
    fn a_feature_the_crate_lacks_classifies_as_unsupported() {
        assert_eq!(
            kind_of(&ErrorKind::NotImplemented(
                "named database time zone".to_string()
            )),
            Kind::Unsupported
        );
    }

    /// Objects 12: what the crate cannot read or write in an Object fails
    /// as unsupported; a value that does not fit its type, as usage.
    #[test]
    fn object_errors_classify_as_unsupported_or_usage() {
        for kind in [
            ErrorKind::UnsupportedTdsType(9),
            ErrorKind::UnsupportedObjectMetadataType("X".to_string()),
            ErrorKind::UnsupportedDbObjectAttributeType(
                "attribute A".to_string(),
                "CLOB".to_string(),
            ),
            ErrorKind::UnsupportedDbObjectSubtype("HR.DOG".to_string()),
            ErrorKind::UnsupportedDbType(oracledb::DB_TYPE_XMLTYPE),
        ] {
            assert_eq!(kind_of(&kind), Kind::Unsupported, "{kind:?}");
        }
        for kind in [
            ErrorKind::WrongDbObjectTypeKind("HR.LIST".to_string(), "an object type"),
            ErrorKind::CannotStoreDbObjectValue("x".to_string(), "y".to_string(), "z".to_string()),
            ErrorKind::MissingDbObjectType("x".to_string()),
        ] {
            assert_eq!(kind_of(&kind), Kind::Usage, "{kind:?}");
        }
    }

    #[test]
    fn a_call_timeout_classifies_as_timeout() {
        assert_eq!(kind_of(&ErrorKind::CallTimeoutExceeded), Kind::Timeout);
    }

    #[test]
    fn other_error_kinds_classify_as_database() {
        for kind in [
            ErrorKind::NotConnected,
            ErrorKind::NoDataFound,
            ErrorKind::LockPoisoned,
            ErrorKind::InvalidColumnIndex(3),
        ] {
            assert_eq!(kind_of(&kind), Kind::Database, "{kind:?}");
        }
    }
}
