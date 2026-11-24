//! `Rajska\Oracle\Extension\Connection`: one Session on loan from the
//! process's Pool for its key (or a Standalone one), and the statements run
//! on it (spec #32 §14).

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
use crate::options::Options;
use crate::pool::{self, Lease, PoolKey};

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

/// What a Connection and its Cursors share: the Session, on loan until the
/// Connection is closed or the last of them goes.
pub struct ConnState {
    session: Mutex<Option<Lease>>,
}

impl ConnState {
    fn session(&self) -> MutexGuard<'_, Option<Lease>> {
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
            Some(lease) => f(lease.session()),
            None => Err(Error::usage("The Connection is closed.")),
        }
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

/// Reads `$pool` into the Pool key, and checks `$session`.
fn read_pool(pool: &ZendHashTable, session: &ZendHashTable) -> Result<PoolKey, Error> {
    let fields = Fields::new("$pool", pool, POOL_KEYS)?;
    let optional = |key| -> Result<Option<String>, Error> {
        Ok(fields.optional_string(key)?.map(str::to_string))
    };
    let key = PoolKey {
        dsn: fields.string("dsn")?.to_string(),
        user: fields.string("user")?.to_string(),
        password: fields.string("password")?.to_string(),
        proxy_user: optional("proxyUser")?,
        wallet_location: optional("walletLocation")?,
        wallet_password: optional("walletPassword")?,
        config_dir: optional("configDir")?,
        connect_timeout: fields.int("connectTimeout")?,
        drcp_connection_class: optional("drcpConnectionClass")?,
        max_connections: fields.int("maxConnections")?,
        acquire_timeout: fields.int("acquireTimeout")?,
        statement_cache_size: fields.int("statementCacheSize")?,
        standalone: fields.bool("standalone")?,
    };
    read_session(session)?;
    Ok(key)
}

#[php_class]
#[php(name = "Rajska\\Oracle\\Extension\\Connection")]
#[php(flags = ClassFlags::Final)]
pub struct NativeConnection {
    state: Arc<ConnState>,
}

#[php_impl]
impl NativeConnection {
    /// Gets a Session from the process's Pool for `$pool`, the whole array
    /// being the Pool key (created on first use), or opens a Standalone one
    /// when `standalone` is true. `$pool` holds the Pool-key fields of
    /// `ConnectionConfig` (durations in ms); `$session` holds `tracing` and
    /// `callTimeout`.
    #[php(sensitive(pool))]
    pub fn connect(pool: &ZendHashTable, session: &ZendHashTable) -> PhpResult<Self> {
        run(|| {
            let session = pool::acquire(&read_pool(pool, session)?)?;
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
            let (row_count, cursor, warning) = self.state.with(|session| {
                // The crate keeps the Session's last warning for the
                // Session's whole life, across pooled Connections, so a
                // warning belongs to this execute only if it changed during
                // it. The same text twice in a row is
                // missed the second time: that needs a per-execute warning
                // in the fork.
                let before = session.last_warning()?;
                let mut statement = session
                    .statement(sql)?
                    .fetch_array_size(options.fetch_size)
                    // One row more than a batch: the server reports the end
                    // of the rows only when asked for more than there are,
                    // so a query of up to fetchSize rows costs one Round
                    // trip. Every batch after it is still one Round trip.
                    .prefetch_rows(options.fetch_size.saturating_add(1))
                    // LOB columns as locators: otherwise the crate redefines
                    // them as LONG / LONG RAW and the describe loses the type.
                    .fetch_lobs()
                    .build()?;
                // Building parses the SQL on the client: no Round trip yet.
                let matched = binds.matched(statement.bind_names())?;
                let params: Vec<_> = matched.iter().map(|(n, v)| (n.as_str(), *v)).collect();
                let (row_count, cursor) = if statement.is_query() {
                    let cursor = statement.query_named(&params)?;
                    let cursor = NativeCursor::new(Arc::clone(&self.state), cursor, &options)?;
                    (0, Some(cursor))
                } else {
                    let result = statement.execute_named(&params)?;
                    // The server counts 1 for a PL/SQL block; only DML counts.
                    let count = if statement.is_dml() {
                        result.rows_affected()
                    } else {
                        0
                    };
                    (count, None)
                };
                let warning = session
                    .last_warning()?
                    .filter(|w| before.as_ref() != Some(w));
                Ok((row_count, cursor, warning))
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
            result
                .insert("outValues", ZendHashTable::new())
                .map_err(put)?;
            result
                .insert("implicitResults", ZendHashTable::new())
                .map_err(put)?;
            result.insert("warning", warning.as_deref()).map_err(put)?;
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

    /// Ends the loan of the Session. Idempotent.
    pub fn close(&self) -> PhpResult<()> {
        run(|| {
            self.state.session().take();
            Ok(())
        })
    }
}
