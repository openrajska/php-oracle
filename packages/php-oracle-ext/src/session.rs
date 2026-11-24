//! `$session` and `$tracing` (spec #32 §10, §14): what every acquire applies
//! to its Session, so nothing of the Session's previous Connection leaks into
//! the next one.
//!
//! - Tracing: the four attributes are all overwritten (empty where unset),
//!   with no Round trip of their own: they ride on the Session's next one,
//!   replacing anything still pending in the client.
//! - Call timeout: the limit for each Round trip, reapplied on every
//!   acquire. An execute's own `timeout` replaces it for that execute and
//!   its Cursors (see `ConnState::timed`).

use std::time::Duration;

use ext_php_rs::types::ZendHashTable;

use crate::error::Error;
use crate::input::Fields;

const SESSION_KEYS: &[&str] = &["tracing", "callTimeout"];
const TRACING_KEYS: &[&str] = &["module", "action", "clientIdentifier", "clientInfo"];

/// `$session`: applied on every acquire.
pub struct Settings {
    pub tracing: Tracing,
    /// `None` is no limit.
    pub call_timeout: Option<Duration>,
}

impl Settings {
    pub fn read(session: &ZendHashTable) -> Result<Self, Error> {
        let fields = Fields::new("$session", session, SESSION_KEYS)?;
        Ok(Self {
            tracing: Tracing::read("$session['tracing']", fields.array("tracing")?)?,
            call_timeout: fields.optional_milliseconds("callTimeout")?,
        })
    }

    /// Applies both to a Session just acquired, with no Round trip.
    pub fn apply(&self, session: &oracledb::Connection) -> Result<(), Error> {
        self.tracing.apply(session)?;
        session.set_call_timeout(self.call_timeout)?;
        Ok(())
    }
}

/// The tracing set a DBA sees in `V$SESSION`: each attribute empty where
/// unset.
pub struct Tracing {
    module: String,
    action: String,
    client_identifier: String,
    client_info: String,
}

impl Tracing {
    pub fn read(what: &'static str, tracing: &ZendHashTable) -> Result<Self, Error> {
        let fields = Fields::new(what, tracing, TRACING_KEYS)?;
        let read = |key| -> Result<String, Error> {
            Ok(fields.optional_string(key)?.unwrap_or_default().to_string())
        };
        Ok(Self {
            module: read("module")?,
            action: read("action")?,
            client_identifier: read("clientIdentifier")?,
            client_info: read("clientInfo")?,
        })
    }

    /// Replaces the whole set on the client: it reaches the server with the
    /// Session's next Round trip, and makes none of its own.
    pub fn apply(&self, session: &oracledb::Connection) -> Result<(), Error> {
        session.set_module(&self.module)?;
        session.set_action(&self.action)?;
        session.set_client_identifier(&self.client_identifier)?;
        session.set_client_info(&self.client_info)?;
        Ok(())
    }
}
