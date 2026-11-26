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
use std::mem::{ManuallyDrop, offset_of};
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::ptr::{self, NonNull};
use std::slice;
use std::sync::{Arc, Mutex, OnceLock, Weak};
use std::time::Duration;

use ext_php_rs::alloc::{efree, emalloc};
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::ffi::{_zend_refcounted_h__bindgen_ty_1, zend_refcounted_h};
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendStr, Zval};
use ext_php_rs::zend::ProcessGlobals;
use oracledb::{DB_TYPE_BLOB, DB_TYPE_CLOB, DB_TYPE_NCLOB, DbType, Row};

use crate::bind::MAX_VARCHAR2;
use crate::connection::ConnState;
use crate::error::{Error, run};
use crate::options::{LobMode, Options};
use crate::pool::lock;
use crate::temp_lob::Written;
use crate::type_name;

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

/// `Lob\Type`'s backing value for a LOB type, or `None` for any other type
/// (BFILE included).
pub fn lob_type(db_type: &DbType) -> Option<&'static str> {
    is_lob(db_type).then(|| type_name::of(db_type)).flatten()
}

pub fn is_lob(db_type: &DbType) -> bool {
    db_type == DB_TYPE_CLOB || db_type == DB_TYPE_NCLOB || db_type == DB_TYPE_BLOB
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
    let refuse = |left| too_large(what, character, size, left);
    // A CLOB / NCLOB takes at least one UTF-8 byte per code unit.
    fits(size).map_err(refuse)?;
    // One read of the whole value, from the prefetched data when it holds it.
    let mut target = Target::new(!character, size, |len| fits(len).map_err(refuse))?;
    let filled = target.fill(&mut lob)?;
    target.finish(filled, |len| fits(len).map_err(refuse))
}

/// Where one read of a LOB lands, allocated before the read: a new PHP
/// string for a BLOB, filled in place (no copy after the crate's); a buffer
/// for a CLOB / NCLOB, whose UTF-8 length is known only once read.
enum Target {
    Bytes(NewString),
    Text(Vec<u8>),
}

impl Target {
    /// Room for a LOB's next `units` bytes (a BLOB) or UCS-2 code units (a
    /// CLOB / NCLOB). `fits` checks a PHP allocation first.
    fn new(
        blob: bool,
        units: usize,
        fits: impl FnOnce(usize) -> Result<(), Error>,
    ) -> Result<Self, Error> {
        if blob {
            fits(units)?;
            return Ok(Self::Bytes(NewString::new(units)?));
        }
        // Up to 3 UTF-8 bytes a code unit; room for 2, a surrogate pair, at
        // least (C9). Only the pages written are touched.
        Ok(Self::Text(vec![0u8; units.max(2).saturating_mul(3)]))
    }

    /// One read: as many bytes as fit, or as many code units as a third of
    /// the buffer holds (C9), up to the LOB's end; the count of bytes read.
    /// One Round trip, or none when the data came with the locator.
    fn fill(&mut self, lob: &mut oracledb::Lob) -> Result<usize, Error> {
        let buffer = match self {
            Self::Bytes(string) => string.bytes(),
            Self::Text(buffer) => buffer.as_mut_slice(),
        };
        lob.read(buffer).map_err(|e| io_error(e, "read"))
    }

    /// The `filled` bytes read, as a PHP string. `fits` checks the copy of
    /// a CLOB / NCLOB's text into one first.
    fn finish(
        self,
        filled: usize,
        fits: impl FnOnce(usize) -> Result<(), Error>,
    ) -> Result<Zval, Error> {
        match self {
            Self::Bytes(string) => Ok(string.finish(filled)),
            Self::Text(mut buffer) => {
                buffer.truncate(filled);
                fits(buffer.len())?;
                Ok(binary(buffer))
            }
        }
    }
}

/// A PHP string being filled: allocated and headed as `zend_string_alloc()`
/// makes a request string, handed to PHP only once its bytes and length are
/// final, and freed if it never is.
struct NewString {
    string: NonNull<ZendStr>,
    capacity: usize,
}

impl NewString {
    /// Room for `capacity` bytes. The caller checked that `memory_limit`
    /// allows them ([`fits`]): PHP's allocator ends the request with a
    /// fatal error, through the Extension's frames, when it does not.
    fn new(capacity: usize) -> Result<Self, Error> {
        let too_large = || Error::unsupported("The LOB is too large to read into memory.");
        let size = offset_of!(ZendStr, val)
            .checked_add(capacity)
            .and_then(|size| size.checked_add(1))
            .ok_or_else(too_large)?;
        let layout =
            Layout::from_size_align(size, align_of::<ZendStr>()).map_err(|_| too_large())?;
        let string = NonNull::new(emalloc(layout).cast::<ZendStr>()).ok_or_else(too_large)?;
        // SAFETY: `string` is a fresh allocation of `layout`: a zend_string
        // header, then `capacity` + 1 bytes. The header is the one
        // zend_string_alloc(capacity, false) writes, its type info taken
        // from a string PHP made; zeroed, the bytes are a valid `[u8]`.
        unsafe {
            string.as_ptr().write(ZendStr {
                gc: zend_refcounted_h {
                    refcount: 1,
                    u: _zend_refcounted_h__bindgen_ty_1 {
                        type_info: request_string_type_info(),
                    },
                },
                h: 0,
                len: capacity,
                val: [0],
            });
            ptr::write_bytes(Self::val(string), 0, capacity + 1);
        }
        Ok(Self { string, capacity })
    }

    /// The string's first byte.
    fn val(string: NonNull<ZendStr>) -> *mut u8 {
        // SAFETY: a place projection, no read: the bytes follow the header
        // in the same allocation.
        unsafe { (&raw mut (*string.as_ptr()).val).cast::<u8>() }
    }

    fn bytes(&mut self) -> &mut [u8] {
        // SAFETY: `capacity` initialised bytes, borrowed through `self`.
        unsafe { slice::from_raw_parts_mut(Self::val(self.string), self.capacity) }
    }

    /// The string of its first `len` bytes, owned by PHP from here on.
    fn finish(self, len: usize) -> Zval {
        let len = len.min(self.capacity);
        let string = ManuallyDrop::new(self).string;
        // SAFETY: the length and the terminating NUL are set before ZBox
        // takes the string; nothing else points at it.
        let boxed = unsafe {
            (*string.as_ptr()).len = len;
            *Self::val(string).add(len) = 0;
            ZBox::from_raw(string.as_ptr())
        };
        let mut zval = Zval::new();
        zval.set_zend_string(boxed);
        zval
    }
}

impl Drop for NewString {
    /// Never handed to PHP: the read failed.
    fn drop(&mut self) {
        // SAFETY: emalloc()ed by `new`, and never boxed.
        unsafe { efree(self.string.as_ptr().cast::<u8>()) };
    }
}

/// The type info (`GC_STRING`: a string, not collectable, not persistent)
/// that `zend_string_alloc()` gives a request string, read once from one
/// PHP made rather than built from its header's constants.
fn request_string_type_info() -> u32 {
    static TYPE_INFO: OnceLock<u32> = OnceLock::new();
    *TYPE_INFO.get_or_init(|| {
        // Two bytes: zend_string_init(), not an interned one-byte string.
        let sample = ZendStr::new("ab", false);
        // SAFETY: every zend_string's refcount header holds its type info.
        unsafe { sample.gc.u.type_info }
    })
}

fn binary(bytes: Vec<u8>) -> Zval {
    let mut zval = Zval::new();
    zval.set_binary(bytes);
    zval
}

fn too_large(what: &str, character: bool, size: usize, left: usize) -> Error {
    let unit = if character {
        "UCS-2 code units"
    } else {
        "bytes"
    };
    Error::unsupported(format!(
        "{what}: the LOB holds {size} {unit}, too large to read into memory under LobMode::Inline (memory_limit leaves {left} bytes); read it as a Lob with LobMode::Locator, the default, and stream it with stream().",
    ))
}

/// Zend's memory manager takes memory from the system 2 MiB at a time
/// (`ZEND_MM_CHUNK_SIZE`): any allocation may need one more such chunk.
const ZEND_MM_CHUNK: usize = 2 << 20;

unsafe extern "C" {
    /// What the request uses as `memory_limit` counts it, with `true`:
    /// `memory_get_usage(true)`.
    fn zend_memory_usage(real_usage: bool) -> usize;
}

/// Whether PHP can allocate `bytes` more under `memory_limit`; else the
/// bytes it leaves. Over the limit, PHP's allocator ends the request with
/// a fatal error that unwinds through the Extension's frames (and any lock
/// they hold): so the Extension checks first, with room for a new chunk.
fn fits(bytes: usize) -> Result<(), usize> {
    let limit = ProcessGlobals::get().memory_limit;
    // No limit: -1.
    let Some(limit) = usize::try_from(limit).ok().filter(|limit| *limit > 0) else {
        return Ok(());
    };
    // SAFETY: reads the memory manager's counters.
    let left = limit.saturating_sub(unsafe { zend_memory_usage(true) });
    if bytes.saturating_add(ZEND_MM_CHUNK) > left {
        Err(left)
    } else {
        Ok(())
    }
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

    /// Runs a method body, or fails if the LOB's Connection is gone,
    /// released or Broken, or the LOB is closed. The body reaches the LOB
    /// through [`Self::locked`].
    fn on_lob<T>(&self, body: impl FnOnce(&ConnState) -> Result<T, Error>) -> PhpResult<T> {
        let Some(state) = self.state.upgrade() else {
            return run(|| Err(Error::usage("The Connection is closed.")));
        };
        state.run(|| {
            state.ensure_usable()?;
            self.locked(|_| Ok(()))?;
            body(&state)
        })
    }

    /// Runs `f` on the LOB under its lock, which nothing that allocates
    /// PHP memory holds (see [`fits`]); fails if the LOB is closed.
    fn locked<T>(
        &self,
        f: impl FnOnce(&mut oracledb::Lob) -> Result<T, Error>,
    ) -> Result<T, Error> {
        let mut slot = lock(&self.lob);
        let lob = slot
            .as_mut()
            .ok_or_else(|| Error::usage("The Lob is closed."))?;
        f(lob)
    }
}

#[php_impl]
impl NativeLob {
    /// `Lob\Type`'s backing value: clob, nClob or blob.
    #[php(name = "type")]
    pub fn lob_type(&self) -> PhpResult<&'static str> {
        self.on_lob(|_| Ok(self.lob_type))
    }

    /// In UCS-2 code units for CLOB / NCLOB, in bytes for BLOB. No Round
    /// trip.
    pub fn size(&self) -> PhpResult<i64> {
        self.on_lob(|_| Ok(self.size))
    }

    /// The next chunk, UTF-8 for CLOB / NCLOB: '' at the end and on every
    /// later call. At most one Round trip, under the execute's Call timeout;
    /// none at the end. A chunk never splits a character (C9). A chunk
    /// PHP's `memory_limit` cannot hold fails with `unsupported`.
    pub fn read_chunk(&self) -> PhpResult<Zval> {
        self.on_lob(|state| {
            // What is left, from the locator's size and the LOB's own
            // position: no Round trip.
            let units = self.locked(|lob| {
                let size = lob.get_size()?;
                let next = lob.stream_position().map_err(|e| io_error(e, "read"))?;
                let left = size.saturating_sub(usize::try_from(next).unwrap_or(usize::MAX) - 1);
                Ok(left.min(CHUNK))
            })?;
            if units == 0 {
                return Ok(binary(Vec::new()));
            }
            let refuse = |left| {
                Error::unsupported(format!(
                    "The Lob's next chunk does not fit in memory_limit, which leaves {left} bytes; raise memory_limit to read it."
                ))
            };
            let blob = self.lob_type == "blob";
            let mut target = Target::new(blob, units, |len| fits(len).map_err(refuse))?;
            let filled =
                state.timed(self.timeout, || self.locked(|lob| target.fill(lob)))?;
            target.finish(filled, |len| fits(len).map_err(refuse))
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
