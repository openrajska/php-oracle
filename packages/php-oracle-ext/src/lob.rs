//! `Rajska\Oracle\Extension\Lob`: a CLOB, NCLOB or BLOB locator, read chunk
//! by chunk; and `LobMode::Inline`, which reads the whole value through the
//! locator at fetch (spec #32 §8, §14).
//!
//! The locator arrives with the value's size, so `size()` and an empty LOB
//! cost no Round trip. The fetch of a query, a REF CURSOR, a nested Cursor or
//! an Implicit result also brings the first [`PREFETCH`] characters or bytes
//! of each LOB with its locator (fork patches #74, #75), so a LOB no larger
//! than that reads with no Round trip of its own, under `LobMode::Inline` and
//! through `readChunk()` alike. A larger one costs one Round trip per
//! `readChunk()`, and one under `LobMode::Inline`; so does every non-empty LOB
//! of an OUT bind, which comes without prefetched data.

use std::alloc::Layout;
use std::io::{self, Read, Seek};
use std::mem::offset_of;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::{Arc, Mutex, Weak};
use std::time::Duration;
use std::{ptr, slice};

use ext_php_rs::alloc::emalloc;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::ffi::{
    _zend_refcounted_h__bindgen_ty_1, GC_FLAGS_SHIFT, IS_STRING, zend_refcounted_h,
};
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendStr, Zval};
use ext_php_rs::zend::Function;
use oracledb::{DB_TYPE_BLOB, DB_TYPE_CLOB, DB_TYPE_NCLOB, DbType, Row};

use crate::bind::MAX_VARCHAR2;
use crate::connection::ConnState;
use crate::error::{Error, run};
use crate::options::{LobMode, Options};
use crate::pool::lock;
use crate::temp_lob::Written;

/// Where a native LOB lives: held by its `Lob` object and, weakly, by its
/// Connection, which empties it on Release.
pub type LobSlot = Mutex<Option<oracledb::Lob>>;

/// The LOB prefetch size of every statement: how many characters (UCS-2
/// code units) of a CLOB / NCLOB, or bytes of a BLOB, the fetch of its rows,
/// or of the REF CURSORs, nested Cursors and Implicit results it returns,
/// brings with each locator. It is the largest VARCHAR2 / RAW, so any LOB a
/// VARCHAR2 or RAW could have held reads with no Round trip of its own. It
/// costs up to that much more data per LOB cell of every fetched row, read or
/// not (twice that in bytes for a CLOB / NCLOB, sent as UTF-16), never a Round
/// trip.
pub const PREFETCH: u32 = MAX_VARCHAR2 as u32;

/// What one `readChunk()` asks for: this many bytes of a BLOB, or UCS-2
/// code units of a CLOB / NCLOB (up to 3 UTF-8 bytes each).
const CHUNK: usize = 1 << 20;

/// Zend's GC_NOT_COLLECTABLE flag, part of a string's GC_STRING type info.
const GC_NOT_COLLECTABLE: u32 = 1 << 4;

/// `LobType`'s backing value for a LOB type, or `None` for any other type
/// (BFILE included).
pub fn lob_type(db_type: &DbType) -> Option<&'static str> {
    if db_type == DB_TYPE_CLOB {
        Some("clob")
    } else if db_type == DB_TYPE_NCLOB {
        Some("nClob")
    } else if db_type == DB_TYPE_BLOB {
        Some("blob")
    } else {
        None
    }
}

/// The LOB at `index` of a row, taken out of it: NULL gives `null`; under
/// `LobMode::Locator`, a native `Lob` of the Connection; under
/// `LobMode::Inline`, the whole value as a string (UTF-8 text, or bytes).
/// `what` names the value in an error ("Column C", "OUT bind 'x'").
pub fn cell(
    row: &mut Row,
    index: usize,
    state: &Arc<ConnState>,
    options: &Options,
    what: &str,
) -> Result<Zval, Error> {
    let Some(lob) = row.take::<Option<oracledb::Lob>>(index)? else {
        return Ok(Zval::null());
    };
    match options.lobs {
        LobMode::Locator => ZendClassObject::new(NativeLob::new(state, lob, options)?)
            .into_zval(false)
            .map_err(|e| Error::unsupported(e.to_string())),
        LobMode::Inline => inline(lob, what),
    }
}

/// The whole value of a LOB, in one Round trip (none when it is empty or
/// came whole with its locator, see [`PREFETCH`]). A
/// value PHP's `memory_limit` cannot hold fails with `unsupported` instead
/// of PHP's fatal error.
fn inline(mut lob: oracledb::Lob, what: &str) -> Result<Zval, Error> {
    // From the locator: no Round trip.
    let size = lob.get_size()?;
    let character = lob.db_type() != DB_TYPE_BLOB;
    let left = memory_left();
    // A CLOB / NCLOB takes at least one UTF-8 byte per code unit.
    if left.is_some_and(|left| size > left) {
        return Err(too_large(what, character, size, left));
    }
    // One read of the whole value, from the prefetched data when it holds it.
    let zval = read(&mut lob, size)?;
    if let Some(left) = memory_left()
        && zval.zend_str().is_some_and(|filled| filled.len() > left)
    {
        return Err(too_large(what, character, size, Some(left)));
    }
    Ok(zval)
}

/// The LOB's next `units` bytes (BLOB) or UCS-2 code units (CLOB / NCLOB,
/// as UTF-8), or fewer at its end, as a PHP string: one Round trip, or none
/// when the data came with the locator.
fn read(lob: &mut oracledb::Lob, units: usize) -> Result<Zval, Error> {
    if lob.db_type() == DB_TYPE_BLOB {
        return read_bytes(lob, units);
    }
    // Up to 3 UTF-8 bytes a code unit; room for 2, a surrogate pair, at
    // least (C9). Only the pages written are touched.
    let mut buffer = vec![0u8; units.max(2).saturating_mul(3)];
    let filled = fill(lob, &mut buffer)?;
    buffer.truncate(filled);
    Ok(binary(buffer))
}

/// A BLOB's next `len` bytes, or fewer at its end, read straight into a new
/// PHP string: no buffer of their own, no copy after the crate's.
fn read_bytes(lob: &mut oracledb::Lob, len: usize) -> Result<Zval, Error> {
    let header = offset_of!(ZendStr, val);
    let layout = Layout::from_size_align(header.saturating_add(len).saturating_add(1), 8)
        .map_err(|_| Error::unsupported("The LOB is too large to read into memory."))?;
    let string = emalloc(layout).cast::<ZendStr>();
    if string.is_null() {
        return Err(Error::unsupported(
            "The LOB is too large to read into memory.",
        ));
    }
    // SAFETY: `string` is a fresh allocation of `layout`: a zend_string
    // header and `len` + 1 bytes after it. The header is that of
    // zend_string_alloc(len, false); zeroing the bytes makes them a valid
    // `&mut [u8]`. ZBox frees the string if the read fails.
    unsafe {
        string.write(ZendStr {
            gc: zend_refcounted_h {
                refcount: 1,
                u: _zend_refcounted_h__bindgen_ty_1 {
                    type_info: IS_STRING | (GC_NOT_COLLECTABLE << GC_FLAGS_SHIFT),
                },
            },
            h: 0,
            len,
            val: [0],
        });
        let bytes = (&raw mut (*string).val).cast::<u8>();
        ptr::write_bytes(bytes, 0, len + 1);
        let boxed = ZBox::from_raw(string);
        let filled = fill(lob, slice::from_raw_parts_mut(bytes, len))?;
        (*string).len = filled;
        *bytes.add(filled) = 0;
        let mut zval = Zval::new();
        zval.set_zend_string(boxed);
        Ok(zval)
    }
}

/// One read into `buffer`: as many bytes as fit, or as many code units as a
/// third of it holds (C9), up to the LOB's end; the count of bytes read.
fn fill(lob: &mut oracledb::Lob, buffer: &mut [u8]) -> Result<usize, Error> {
    lob.read(buffer).map_err(|e| io_error(e, "read"))
}

/// A PHP string of these bytes.
fn binary(bytes: Vec<u8>) -> Zval {
    let mut zval = Zval::new();
    zval.set_binary(bytes);
    zval
}

fn too_large(what: &str, character: bool, size: usize, left: Option<usize>) -> Error {
    let unit = if character {
        "UCS-2 code units"
    } else {
        "bytes"
    };
    Error::unsupported(format!(
        "{what}: the LOB holds {size} {unit}, too large to read into memory under LobMode::Inline (memory_limit leaves {} bytes); read it as a Lob with LobMode::Locator, the default, and stream it with stream().",
        left.unwrap_or(0)
    ))
}

/// The bytes PHP may still allocate under `memory_limit`; `None` when there
/// is no limit (or it cannot be read).
fn memory_left() -> Option<usize> {
    let call = |name: &str, arguments: Vec<&dyn ext_php_rs::convert::IntoZvalDyn>| {
        Function::try_from_function(name).and_then(|function| function.try_call(arguments).ok())
    };
    let limit = call("ini_get", vec![&"memory_limit".to_string()])?.string()?;
    let limit = call("ini_parse_quantity", vec![&limit])?.long()?;
    if limit <= 0 {
        return None;
    }
    let used = call("memory_get_usage", vec![])?.long()?;
    Some(usize::try_from(limit.saturating_sub(used)).unwrap_or(0))
}

/// A read or write error: the crate's own error inside, when there is one.
pub fn io_error(e: io::Error, operation: &str) -> Error {
    if e.get_ref()
        .is_some_and(|inner| inner.is::<oracledb::Error>())
    {
        if let Some(Ok(inner)) = e
            .into_inner()
            .map(|inner| inner.downcast::<oracledb::Error>())
        {
            return Error::from(*inner);
        }
        return Error::unsupported(format!("The LOB {operation} failed."));
    }
    Error::unsupported(format!("The LOB {operation} failed: {e}."))
}

#[php_class]
#[php(name = "Rajska\\Oracle\\Extension\\Lob")]
#[php(flags = ClassFlags::Final)]
pub struct NativeLob {
    /// Emptied by `close()` and by the Connection's Release.
    lob: Arc<LobSlot>,
    lob_type: &'static str,
    /// In UCS-2 code units for CLOB / NCLOB, in bytes for BLOB.
    size: i64,
    /// The Call timeout of the execute the LOB came from.
    timeout: Option<Duration>,
    /// Weak: a LOB does not keep the Session (spec #32 §14, Lifetimes).
    state: Weak<ConnState>,
    /// The temporary LOBs of the execute it came from, which it may be.
    _written: Option<Arc<Written>>,
}

impl NativeLob {
    fn new(
        state: &Arc<ConnState>,
        mut lob: oracledb::Lob,
        options: &Options,
    ) -> Result<Self, Error> {
        let lob_type = lob_type(lob.db_type()).ok_or_else(|| {
            Error::unsupported(format!(
                "{} values cannot be read yet.",
                lob.db_type().name()
            ))
        })?;
        // From the locator: no Round trip.
        let size = lob.get_size()?;
        Ok(Self {
            lob: state.register_lob(lob),
            lob_type,
            size: i64::try_from(size).unwrap_or(i64::MAX),
            timeout: options.timeout,
            state: Arc::downgrade(state),
            _written: options.written.clone(),
        })
    }

    /// Runs a method body on the LOB, or fails if its Connection is gone,
    /// released or Broken, or the LOB is closed.
    fn on_lob<T>(
        &self,
        body: impl FnOnce(&ConnState, &mut oracledb::Lob) -> Result<T, Error>,
    ) -> PhpResult<T> {
        let Some(state) = self.state.upgrade() else {
            return run(|| Err(Error::usage("The Connection is closed.")));
        };
        state.run(|| {
            state.ensure_usable()?;
            let mut slot = lock(&self.lob);
            let lob = slot
                .as_mut()
                .ok_or_else(|| Error::usage("The Lob is closed."))?;
            body(&state, lob)
        })
    }
}

#[php_impl]
impl NativeLob {
    /// `LobType`'s backing value: clob, nClob or blob.
    #[php(name = "type")]
    pub fn lob_type(&self) -> PhpResult<&'static str> {
        self.on_lob(|_, _| Ok(self.lob_type))
    }

    /// In UCS-2 code units for CLOB / NCLOB, in bytes for BLOB. No Round
    /// trip.
    pub fn size(&self) -> PhpResult<i64> {
        self.on_lob(|_, _| Ok(self.size))
    }

    /// The next chunk, UTF-8 for CLOB / NCLOB: '' at the end and on every
    /// later call. At most one Round trip, under the execute's Call timeout;
    /// none at the end. A chunk never splits a character (C9).
    pub fn read_chunk(&self) -> PhpResult<Zval> {
        self.on_lob(|state, lob| {
            // What is left, from the locator's size and the LOB's own
            // position: no Round trip.
            let size = lob.get_size()?;
            let next = lob.stream_position().map_err(|e| io_error(e, "read"))?;
            let left = size.saturating_sub(usize::try_from(next).unwrap_or(usize::MAX) - 1);
            if left == 0 {
                return Ok(binary(Vec::new()));
            }
            let units = left.min(CHUNK);
            state.timed(self.timeout, || read(lob, units))
        })
    }

    /// Frees the locator; a temporary LOB's close rides on the Session's
    /// next Round trip. Idempotent; never throws.
    pub fn close(&self) -> PhpResult<()> {
        run(|| {
            lock(&self.lob).take();
            Ok(())
        })
    }
}

impl Drop for NativeLob {
    /// A panic must not cross into PHP's free handler.
    fn drop(&mut self) {
        let _ = catch_unwind(AssertUnwindSafe(|| lock(&self.lob).take()));
    }
}
