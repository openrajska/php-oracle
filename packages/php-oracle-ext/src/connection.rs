//! `Rajska\Oracle\Extension\Connection`: one Session, and the statements run
//! on it (spec #32 §14).
//!
//! For now every `connect()` opens a standalone Session that ends with the
//! Connection; the Pool comes later.

use std::sync::{Arc, Mutex, MutexGuard};

use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendHashTable};

use crate::bind::Binds;
use crate::cursor::NativeCursor;
use crate::error::{Error, run};
use crate::input::Fields;
use crate::value::Numbers;

/// The keys of `$pool`: the Pool-key fields of `ConnectionConfig`.
const POOL_KEYS: &[&str] = &[
    "dsn",
    "user",
    "password",
    "proxyUser",
    "walletLocation",
    "walletPassword",
    "configDir",
    "connectTimeout",
    "drcpConnectionClass",
    "maxConnections",
    "acquireTimeout",
    "statementCacheSize",
    "standalone",
];
const SESSION_KEYS: &[&str] = &["tracing", "callTimeout"];
const TRACING_KEYS: &[&str] = &["module", "action", "clientIdentifier", "clientInfo"];
const OPTION_KEYS: &[&str] = &[
    "autocommit",
    "timeout",
    "fetchSize",
    "numbers",
    "temporals",
    "lobs",
    "rowShape",
    "keyCase",
];

/// What a Connection and its Cursors share: the Session.
pub struct ConnState {
    session: Mutex<Option<oracledb::Connection>>,
}

impl ConnState {
    fn session(&self) -> MutexGuard<'_, Option<oracledb::Connection>> {
        // A panic while holding the lock leaves the Session as it was.
        self.session
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }

    /// Fails if the Connection is closed.
    pub fn ensure_open(&self) -> Result<(), Error> {
        self.with(|_| Ok(()))
    }

    /// Runs `f` on the Session, or fails if the Connection is closed.
    fn with<T>(
        &self,
        f: impl FnOnce(&oracledb::Connection) -> Result<T, Error>,
    ) -> Result<T, Error> {
        match self.session().as_ref() {
            Some(session) => f(session),
            None => Err(Error::usage("The Connection is closed.")),
        }
    }
}

impl Drop for ConnState {
    /// The Session ends when the Connection and every Cursor of it are gone.
    fn drop(&mut self) {
        if let Some(mut session) = self.session().take() {
            // A Session that fails to log off is gone either way.
            let _ = session.close();
        }
    }
}

/// The options of one execute, read from `$options`.
pub struct Options {
    pub fetch_size: u32,
    pub numbers: Numbers,
}

impl Options {
    fn read(options: &ZendHashTable) -> Result<Self, Error> {
        let fields = Fields::new("$options", options, OPTION_KEYS)?;
        fields.bool("autocommit")?;
        fields.optional_int("timeout")?;
        let numbers = Numbers::from_option(fields.choice("numbers", &["exact", "float"])?);
        fields.choice("temporals", &["object", "string"])?;
        fields.choice("lobs", &["locator", "inline"])?;
        fields.choice("rowShape", &["assoc", "list"])?;
        fields.choice("keyCase", &["native", "lower"])?;
        let fetch_size = fields.int("fetchSize")?;
        let fetch_size = u32::try_from(fetch_size)
            .ok()
            .filter(|n| *n >= 1)
            .ok_or_else(|| Error::usage("'fetchSize' in $options must be at least 1."))?;
        Ok(Self {
            fetch_size,
            numbers,
        })
    }
}

fn read_session(session: &ZendHashTable) -> Result<(), Error> {
    let fields = Fields::new("$session", session, SESSION_KEYS)?;
    fields.optional_int("callTimeout")?;
    let tracing = Fields::new(
        "$session['tracing']",
        fields.array("tracing")?,
        TRACING_KEYS,
    )?;
    for key in TRACING_KEYS {
        tracing.optional_string(key)?;
    }
    Ok(())
}

fn open(pool: &ZendHashTable, session: &ZendHashTable) -> Result<oracledb::Connection, Error> {
    let fields = Fields::new("$pool", pool, POOL_KEYS)?;
    let (dsn, user, password) = (
        fields.string("dsn")?,
        fields.string("user")?,
        fields.string("password")?,
    );
    for key in [
        "proxyUser",
        "walletLocation",
        "walletPassword",
        "configDir",
        "drcpConnectionClass",
    ] {
        fields.optional_string(key)?;
    }
    for key in [
        "connectTimeout",
        "maxConnections",
        "acquireTimeout",
        "statementCacheSize",
    ] {
        fields.int(key)?;
    }
    fields.bool("standalone")?;
    read_session(session)?;

    let config = oracledb::Config::default()
        .set_credentials(user, password)
        .set_connect_string(dsn)
        .map_err(Error::connect)?;
    oracledb::connect(config).map_err(Error::connect)
}

#[php_class]
#[php(name = "Rajska\\Oracle\\Extension\\Connection")]
#[php(flags = ClassFlags::Final)]
pub struct NativeConnection {
    state: Arc<ConnState>,
}

#[php_impl]
impl NativeConnection {
    /// Opens a Session. `$pool` holds the Pool-key fields of
    /// `ConnectionConfig` (durations in ms); `$session` holds `tracing` and
    /// `callTimeout`.
    #[php(sensitive(pool))]
    pub fn connect(pool: &ZendHashTable, session: &ZendHashTable) -> PhpResult<Self> {
        run(|| {
            let session = open(pool, session)?;
            Ok(Self {
                state: Arc::new(ConnState {
                    session: Mutex::new(Some(session)),
                }),
            })
        })
    }

    /// Runs one statement:
    /// `['rowCount' => int, 'cursor' => ?Cursor, 'outValues' => array,
    ///   'implicitResults' => list<Cursor>, 'warning' => ?string]`.
    #[php(sensitive(binds))]
    pub fn execute(
        &self,
        sql: &str,
        binds: &ZendHashTable,
        options: &ZendHashTable,
    ) -> PhpResult<ZBox<ZendHashTable>> {
        run(|| {
            let options = Options::read(options)?;
            let binds = Binds::read(binds)?;
            let (row_count, cursor, out_values) = self.state.with(|session| {
                let mut statement = session
                    .statement(sql)?
                    .fetch_array_size(options.fetch_size)
                    .prefetch_rows(options.fetch_size)
                    .build()?;
                // Building parses the SQL on the client: no Round trip yet.
                let matched = binds.matched(statement.bind_names())?;
                let params: Vec<_> = matched.iter().map(|(n, v)| (n.as_str(), *v)).collect();
                if statement.is_query() {
                    let cursor = statement.query_named(&params)?;
                    Ok((
                        0,
                        Some(NativeCursor::new(Arc::clone(&self.state), cursor, &options)),
                        binds.out_values(None, false, options.numbers)?,
                    ))
                } else {
                    let mut result = statement.execute_named(&params)?;
                    let out_values = binds.out_values(
                        Some(&mut result),
                        statement.is_plsql(),
                        options.numbers,
                    )?;
                    Ok((result.rows_affected(), None, out_values))
                }
            })?;

            let mut result = ZendHashTable::with_capacity(5);
            let put = |e: ext_php_rs::error::Error| Error::unsupported(e.to_string());
            result
                .insert("rowCount", i64::try_from(row_count).unwrap_or(i64::MAX))
                .map_err(put)?;
            match cursor {
                Some(cursor) => result
                    .insert("cursor", ZendClassObject::new(cursor))
                    .map_err(put)?,
                None => result.insert("cursor", ()).map_err(put)?,
            }
            result.insert("outValues", out_values).map_err(put)?;
            result
                .insert("implicitResults", ZendHashTable::new())
                .map_err(put)?;
            result.insert("warning", ()).map_err(put)?;
            Ok(result)
        })
    }

    /// Runs one statement once per row of binds:
    /// `['rowCount' => int, 'outValues' => array]`.
    #[php(sensitive(rows))]
    pub fn execute_many(
        &self,
        sql: &str,
        rows: &ZendHashTable,
        options: &ZendHashTable,
    ) -> PhpResult<ZBox<ZendHashTable>> {
        let _ = (sql, rows, options);
        run(|| {
            Err(Error::unsupported(
                "Connection::executeMany() is not implemented yet.",
            ))
        })
    }

    pub fn commit(&self) -> PhpResult<()> {
        run(|| self.state.with(|session| Ok(session.commit()?)))
    }

    pub fn rollback(&self) -> PhpResult<()> {
        run(|| self.state.with(|session| Ok(session.rollback()?)))
    }

    /// Replaces the whole tracing set (`module`, `action`,
    /// `clientIdentifier`, `clientInfo`), with no Round trip.
    pub fn trace(&self, tracing: &ZendHashTable) -> PhpResult<()> {
        let _ = tracing;
        run(|| {
            Err(Error::unsupported(
                "Connection::trace() is not implemented yet.",
            ))
        })
    }

    /// The Server version from the connect handshake: five parts.
    pub fn server_version(&self) -> PhpResult<String> {
        run(|| {
            self.state
                .with(|session| Ok(session.version()?.to_string()))
        })
    }

    /// Ends the Session. Idempotent.
    pub fn close(&self) -> PhpResult<()> {
        run(|| {
            if let Some(mut session) = self.state.session().take() {
                // A Session that fails to log off is gone either way.
                let _ = session.close();
            }
            Ok(())
        })
    }
}
