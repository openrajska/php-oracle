//! LOB writes: the value of a CLOB, NCLOB or BLOB bind (`In\Clob`,
//! `In\NClob`, `In\Blob`, their `InOut\` twins, and a plain string over
//! 32767 bytes), written into a temporary LOB on the Session just before
//! the execute, which binds its locator (spec #32 §7, C11).
//!
//! The value is a string or a stream resource, read from its current
//! position to its end. It goes in chunks of about 1 MiB, one Round trip
//! each, after one to create the LOB. A chunk of text ends on a character
//! boundary, since the crate writes each character LOB call from whole
//! UTF-8 (C10). Nothing is read or written before the statement passed
//! every check that needs no Round trip.
//!
//! The LOB lives as long as the binds of its execute: dropped, its close
//! rides on the Session's next Round trip (the Release's rollback at the
//! latest), so the Session's next owner never finds it.

use std::io::Write;
use std::sync::Arc;

use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ExecutorGlobals, Function};
use oracledb::{DB_TYPE_BLOB, DB_TYPE_CLOB, DbType, ToDbValue};

use crate::connection::ConnState;
use crate::error::Error;
use crate::lob::{LobSlot, io_error};

/// What one write sends: this many bytes (of UTF-8, for text).
const CHUNK: usize = 1 << 20;

/// A LOB bind's value, until the execute writes it, then its temporary
/// LOB.
pub struct TempLob {
    db_type: &'static DbType,
    /// A string, or a stream resource; `None` once written.
    source: Option<Zval>,
    lob: Option<oracledb::Lob>,
}

impl TempLob {
    /// The value of a LOB bind of `db_type`, or `None` for NULL: a string
    /// (UTF-8, for CLOB / NCLOB) or a stream resource. No Round trip.
    pub fn read(name: &str, db_type: &'static DbType, value: &Zval) -> Result<Option<Self>, Error> {
        if value.is_null() {
            return Ok(None);
        }
        let character = db_type != DB_TYPE_BLOB;
        if value.is_string() {
            if character && value.str().is_none() {
                return Err(Error::usage(format!(
                    "Bind '{name}': the string is not valid UTF-8; bind binary data with In\\Raw or In\\Blob."
                )));
            }
        } else if !is_stream(value) {
            return Err(Error::usage(format!(
                "Bind '{name}': a LOB takes a string, an open stream resource or null."
            )));
        }
        Ok(Some(Self::of(db_type, value)))
    }

    /// A string or a stream resource already checked, as the value of a
    /// LOB bind of `db_type`. No Round trip.
    pub fn of(db_type: &'static DbType, value: &Zval) -> Self {
        Self {
            db_type,
            // Shares the string or the resource: nothing is copied.
            source: Some(value.shallow_clone()),
            lob: None,
        }
    }

    /// UTF-8 text as the value of a CLOB bind. No Round trip.
    pub fn text(text: &str) -> Result<Self, Error> {
        let mut source = Zval::new();
        source
            .set_string(text, false)
            .map_err(|e| Error::unsupported(e.to_string()))?;
        Ok(Self {
            db_type: DB_TYPE_CLOB,
            source: Some(source),
            lob: None,
        })
    }

    /// Creates the temporary LOB and writes the value into it: one Round
    /// trip to create it, then one per chunk. PHP reads a stream between
    /// the writes, while the Session is not held, so a stream wrapper may
    /// use the Connection.
    pub fn write(&mut self, state: &ConnState, name: &str) -> Result<(), Error> {
        let Some(source) = self.source.take() else {
            return Ok(());
        };
        let mut lob = state.with(|session| Ok(session.create_lob(self.db_type)?))?;
        let character = self.db_type != DB_TYPE_BLOB;
        let mut send =
            |chunk: &[u8]| state.with(|_| lob.write_all(chunk).map_err(|e| io_error(e, "write")));
        if let Some(bytes) = source.binary_slice::<u8>() {
            let mut rest = bytes;
            while !rest.is_empty() {
                let mut end = rest.len().min(CHUNK);
                // `read` checked the text is UTF-8: back off to a boundary.
                while character && end < rest.len() && is_continuation(rest[end]) {
                    end -= 1;
                }
                send(&rest[..end])?;
                rest = &rest[end..];
            }
        } else {
            let mut buffer = Vec::with_capacity(CHUNK);
            // Bytes of the stream sent so far, for the error.
            let mut sent = 0;
            let mut ended = false;
            while !ended {
                while buffer.len() < CHUNK {
                    let read = fread(name, &source, CHUNK - buffer.len())?;
                    if read.is_empty() {
                        ended = true;
                        break;
                    }
                    buffer.extend_from_slice(&read);
                }
                let end = if character {
                    whole_utf8(name, &buffer, sent, ended)?
                } else {
                    buffer.len()
                };
                if end > 0 {
                    send(&buffer[..end])?;
                }
                buffer.drain(..end);
                sent += end;
            }
        }
        self.lob = Some(lob);
        Ok(())
    }

    /// The temporary LOB, once written, out of the bind.
    pub fn take(&mut self) -> Option<oracledb::Lob> {
        self.lob.take()
    }

    /// The locator once written; NULL of its type before.
    pub fn as_db_value(&self) -> &dyn ToDbValue {
        match &self.lob {
            Some(lob) => lob,
            None => &self.db_type,
        }
    }
}

/// Whether a UTF-8 byte continues a character (`10xxxxxx`).
fn is_continuation(byte: u8) -> bool {
    byte & 0b1100_0000 == 0b1000_0000
}

/// How much of `buffer` is whole UTF-8: up to a character cut by the
/// chunk's end, which waits for the next chunk. Invalid UTF-8, or a
/// character cut by the end of the stream, fails; `sent` bytes of the
/// stream came before `buffer`.
fn whole_utf8(name: &str, buffer: &[u8], sent: usize, ended: bool) -> Result<usize, Error> {
    match std::str::from_utf8(buffer) {
        Ok(_) => Ok(buffer.len()),
        Err(e) if e.error_len().is_none() && !ended => Ok(e.valid_up_to()),
        Err(e) => Err(Error::usage(format!(
            "Bind '{name}': the stream is not valid UTF-8 at byte {}; bind binary data with In\\Blob.",
            sent + e.valid_up_to()
        ))),
    }
}

/// Whether a value is an open stream resource.
fn is_stream(value: &Zval) -> bool {
    value.is_resource()
        && call("get_resource_type", vec![value]).is_some_and(|kind| kind.str() == Some("stream"))
}

/// Up to `length` bytes of the stream; empty at its end.
fn fread(name: &str, stream: &Zval, length: usize) -> Result<Vec<u8>, Error> {
    let length = i64::try_from(length).unwrap_or(i64::MAX);
    let read = call("fread", vec![stream, &length]);
    if ExecutorGlobals::has_exception() {
        return Err(Error::php_exception());
    }
    read.as_ref()
        .and_then(|read| read.binary_slice::<u8>())
        .map(<[u8]>::to_vec)
        .ok_or_else(|| Error::usage(format!("Bind '{name}': the stream cannot be read.")))
}

fn call(function: &str, arguments: Vec<&dyn ext_php_rs::convert::IntoZvalDyn>) -> Option<Zval> {
    Function::try_from_function(function).and_then(|function| function.try_call(arguments).ok())
}

/// The temporary LOBs an execute's binds were written into, freed when the
/// last Cursor or Lob born of that execute goes, or by the Connection's
/// Release, whichever comes first. What the execute gave back may point at
/// them: an OUT parameter set to the IN one, or a `select :c`.
pub struct Written {
    // Held for their drop: each frees its LOB.
    _lobs: Vec<Arc<LobSlot>>,
}

impl Written {
    /// Registers `lobs` with the Connection, so that its Release frees
    /// them; `None` when there is none.
    pub fn keep(state: &ConnState, lobs: Vec<oracledb::Lob>) -> Option<Arc<Self>> {
        if lobs.is_empty() {
            return None;
        }
        Some(Arc::new(Self {
            _lobs: lobs
                .into_iter()
                .map(|lob| state.register_lob(lob))
                .collect(),
        }))
    }
}
