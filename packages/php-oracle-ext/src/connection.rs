//! `Rajska\Oracle\Extension\Connection`: one Session on loan from the
//! process's Pool for its key (or a Standalone one), and the statements run
//! on it (spec #32 §14).

use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::{Arc, Mutex, MutexGuard, Weak};

use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendHashTable};

use crate::bind::Binds;
use crate::cursor::NativeCursor;
use crate::error::{Error, run, run_seeing};
use crate::input::Fields;
use crate::options::Options;
use crate::pool::{self, Lease, PoolKey, lock};

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

/// SQL starting with this runs into a panic, in debug builds only: the
/// test-only hook for Errors 15 (spec #32 §12, Panics).
#[cfg(debug_assertions)]
const PANIC_HOOK: &str = "/* rajska_oracle: panic */";

/// Where a native cursor lives: shared by its `Cursor` object and the
/// Cursors nested in it and, weakly, by its Connection, which empties it on
/// Release. A query, a REF CURSOR and a nested `CURSOR()` each have one.
pub type CursorSlot = Mutex<Option<oracledb::Cursor>>;

/// What a Connection and its Cursors share: the Session, on loan until
/// Release (`close()`, or the last of them going).
pub struct ConnState {
    inner: Mutex<Inner>,
    /// Every native cursor opened on this Connection and still alive. A
    /// lock of its own: a cursor registers while the Session is in use.
    cursors: Mutex<Vec<Weak<CursorSlot>>>,
}

struct Inner {
    /// `None` once released.
    lease: Option<Lease>,
    /// Set by the first error that lost the Session, for good.
    broken: Option<Broken>,
}

/// The error that made the Connection Broken.
struct Broken {
    code: i32,
    message: String,
}

impl ConnState {
    fn new(lease: Lease) -> Self {
        Self {
            inner: Mutex::new(Inner {
                lease: Some(lease),
                broken: None,
            }),
            cursors: Mutex::new(Vec::new()),
        }
    }

    fn inner(&self) -> MutexGuard<'_, Inner> {
        // A panic while holding the lock leaves the state as it was; the
        // panic itself makes the Connection Broken.
        lock(&self.inner)
    }

    /// The Session, or why this Connection cannot be used: released
    /// (`usage`), then Broken (`connection_lost`).
    fn lease(inner: &Inner) -> Result<&Lease, Error> {
        let lease = inner
            .lease
            .as_ref()
            .ok_or_else(|| Error::usage("The Connection is closed."))?;
        match &inner.broken {
            Some(broken) => Err(Error::broken(broken.code, &broken.message)),
            None => Ok(lease),
        }
    }

    /// Fails if the Connection is released or Broken.
    pub fn ensure_usable(&self) -> Result<(), Error> {
        Self::lease(&self.inner()).map(|_| ())
    }

    /// Runs `f` on the Session, or fails if the Connection is released or
    /// Broken.
    fn with<T>(
        &self,
        f: impl FnOnce(&oracledb::Connection) -> Result<T, Error>,
    ) -> Result<T, Error> {
        let inner = self.inner();
        f(Self::lease(&inner)?.session())
    }

    /// Registers a native cursor of this Connection, so Release can drop it.
    pub fn register(&self, cursor: oracledb::Cursor) -> Arc<CursorSlot> {
        let slot = Arc::new(Mutex::new(Some(cursor)));
        let mut cursors = lock(&self.cursors);
        cursors.retain(|cursor| cursor.strong_count() > 0);
        cursors.push(Arc::downgrade(&slot));
        slot
    }

    /// Runs a native method body of this Connection or of one of its
    /// Cursors (see [`run`]). An error that lost the Session, or a panic,
    /// makes the Connection Broken.
    pub fn run<T>(&self, body: impl FnOnce() -> Result<T, Error>) -> PhpResult<T> {
        run_seeing(body, |e| {
            if e.is_connection_lost() {
                self.inner().broken.get_or_insert_with(|| Broken {
                    code: e.code(),
                    message: e.message().to_string(),
                });
            }
        })
    }

    /// Release (spec #32 §11), once; it never fails and never commits:
    /// 1. drops every native cursor, so their closes queue on the Session;
    /// 2. rolls back (C14: here, outside the Pool's mutex), carrying those
    ///    closes;
    /// 3. returns the Session to the Pool (or logs it off), or discards it
    ///    if the Connection is Broken or the rollback failed.
    ///
    /// A panic in it is swallowed: Release runs in `close()` and in PHP's
    /// free handler, and neither may throw.
    fn release(&self) {
        let _ = catch_unwind(AssertUnwindSafe(|| self.end_loan()));
    }

    fn end_loan(&self) {
        let (lease, broken) = {
            let mut inner = self.inner();
            let Some(lease) = inner.lease.take() else {
                return;
            };
            (lease, inner.broken.is_some())
        };
        let cursors = std::mem::take(&mut *lock(&self.cursors));
        for cursor in cursors.iter().filter_map(Weak::upgrade) {
            // The crate's statement drop panics on a poisoned lock: that
            // must not stop the Release.
            let _ = catch_unwind(AssertUnwindSafe(|| lock(&cursor).take()));
        }
        // A Broken Session is rolled back too: on a lost transport the
        // attempt closes it, which lets the Pool drop it.
        let rolled_back = lease.session().rollback().is_ok();
        if broken || !rolled_back {
            lease.discard();
        } else {
            drop(lease);
        }
    }
}

impl Drop for ConnState {
    /// The last native reference is gone (the Connection and all its
    /// Cursors): Release, in the native free path.
    fn drop(&mut self) {
        self.release();
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
                state: Arc::new(ConnState::new(session)),
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
        self.state.run(|| {
            let options = Options::read(options)?;
            let binds = Binds::read(binds)?;
            let (row_count, cursor, warning, out_values) = self.state.with(|session| {
                #[cfg(debug_assertions)]
                if sql.starts_with(PANIC_HOOK) {
                    panic!("injected through the test hook");
                }
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
                let (row_count, cursor, out_values) = if statement.is_query() {
                    let cursor = statement.query_named(&params)?;
                    let cursor =
                        NativeCursor::new(Arc::clone(&self.state), cursor, &options, Vec::new())?;
                    (
                        0,
                        Some(cursor),
                        binds.out_values(None, false, &self.state, &options)?,
                    )
                } else {
                    let mut result = statement.execute_named(&params)?;
                    // The server counts 1 for a PL/SQL block; only DML counts.
                    let count = if statement.is_dml() {
                        result.rows_affected()
                    } else {
                        0
                    };
                    let out_values = binds.out_values(
                        Some(&mut result),
                        statement.is_plsql(),
                        &self.state,
                        &options,
                    )?;
                    (count, None, out_values)
                };
                let warning = session
                    .last_warning()?
                    .filter(|w| before.as_ref() != Some(w));
                Ok((row_count, cursor, warning, out_values))
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
        self.state.run(|| {
            self.state.ensure_usable()?;
            Err(Error::unsupported(
                "Connection::executeMany() is not implemented yet.",
            ))
        })
    }

    pub fn commit(&self) -> PhpResult<()> {
        self.state
            .run(|| self.state.with(|session| Ok(session.commit()?)))
    }

    pub fn rollback(&self) -> PhpResult<()> {
        self.state
            .run(|| self.state.with(|session| Ok(session.rollback()?)))
    }

    /// Replaces the whole tracing set (`module`, `action`,
    /// `clientIdentifier`, `clientInfo`), with no Round trip.
    pub fn trace(&self, tracing: &ZendHashTable) -> PhpResult<()> {
        let _ = tracing;
        self.state.run(|| {
            self.state.ensure_usable()?;
            Err(Error::unsupported(
                "Connection::trace() is not implemented yet.",
            ))
        })
    }

    /// The Server version from the connect handshake: five parts.
    pub fn server_version(&self) -> PhpResult<String> {
        self.state.run(|| {
            self.state
                .with(|session| Ok(session.version()?.to_string()))
        })
    }

    /// Release, now: the Connection's Cursors stop working, the transaction
    /// rolls back, and the Session goes back to its Pool (or is discarded,
    /// if Broken). Idempotent; never throws for a failed rollback.
    pub fn close(&self) -> PhpResult<()> {
        run(|| {
            self.state.release();
            Ok(())
        })
    }
}
