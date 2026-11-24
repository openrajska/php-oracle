//! Errors, and how they cross into PHP as `Rajska\Oracle\Extension\Exception`.
//!
//! Every native method body runs through [`run`]: it catches panics, and turns
//! an [`Error`] into the native exception, whose `kind` tells Core's
//! `ExceptionTranslator` which public class to throw (spec #32 §12, §14).

use std::any::Any;
use std::panic::{AssertUnwindSafe, catch_unwind};

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

    /// An error of the crate while opening a Session: always `connection`,
    /// keeping the ORA code (1017 for a wrong password).
    pub fn connect(e: oracledb::Error) -> Self {
        Self {
            kind: Kind::Connection,
            ..Self::from(e)
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
                kind: Kind::Database,
                code: i32::try_from(db.code()).unwrap_or(0),
                message: db.message().to_string(),
                offset: i64::try_from(db.offset()).ok(),
                pending: false,
            },
            _ => Self::new(Kind::Database, e.to_string()),
        }
    }
}

/// Runs a native method body: an `Err` becomes the native exception, and a
/// panic becomes one of kind `connection_lost` instead of crossing into PHP.
pub fn run<T>(body: impl FnOnce() -> Result<T, Error>) -> PhpResult<T> {
    match catch_unwind(AssertUnwindSafe(body)) {
        Ok(Ok(value)) => Ok(value),
        Ok(Err(e)) => Err(e.throw()),
        Err(payload) => Err(Error::panic(payload).throw()),
    }
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
