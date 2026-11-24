//! `Rajska\Oracle\Extension\Connection`: one Session on loan from the
//! process's Pool for its key (or a Standalone one), and the statements run
//! on it (spec #32 §14).

use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::{Arc, Mutex, MutexGuard, Weak};
use std::time::{Duration, Instant};

use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpResult;
use ext_php_rs::flags::ClassFlags;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendClassObject, ZendHashTable};
use oracledb::DbObjectType;

use crate::batch;
use crate::bind::{Binds, put};
use crate::cursor::NativeCursor;
use crate::error::{Error, Kind, run, run_seeing};
use crate::input::Fields;
use crate::lob::LobSlot;
use crate::object::{self, Path};
use crate::options::Options;
use crate::pool::{self, Lease, PoolKey, lock};
use crate::session::{Settings, Tracing};

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
    /// The Connection's own Call timeout (`$session`), in effect between
    /// executes; `None` is no limit.
    call_timeout: Option<Duration>,
    /// Every native cursor opened on this Connection and still alive. A
    /// lock of its own: a cursor registers while the Session is in use.
    cursors: Mutex<Vec<Weak<CursorSlot>>>,
    /// Every native LOB read from this Connection and still alive.
    lobs: Mutex<Vec<Weak<LobSlot>>>,
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
    fn new(lease: Lease, call_timeout: Option<Duration>) -> Self {
        Self {
            inner: Mutex::new(Inner {
                lease: Some(lease),
                broken: None,
            }),
            call_timeout,
            cursors: Mutex::new(Vec::new()),
            lobs: Mutex::new(Vec::new()),
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

    /// Describes an Object or Collection type on the Session, which keeps
    /// the description (see [`object::describe`]).
    pub fn describe(&self, what: &Path<'_>, lookup: &str) -> Result<Arc<DbObjectType>, Error> {
        let inner = self.inner();
        let lease = Self::lease(&inner)?;
        object::describe(lease.session(), lease.notes(), what, lookup)
    }

    /// Fails if the Connection is released or Broken.
    pub fn ensure_usable(&self) -> Result<(), Error> {
        Self::lease(&self.inner()).map(|_| ())
    }

    /// Runs `f` on the Session, or fails if the Connection is released or
    /// Broken.
    pub fn with<T>(
        &self,
        f: impl FnOnce(&oracledb::Connection) -> Result<T, Error>,
    ) -> Result<T, Error> {
        let inner = self.inner();
        f(Self::lease(&inner)?.session())
    }

    /// Runs `f`, whose Round trips each get the Call timeout `limit`, then
    /// puts the Connection's own back (spec #32 §10). A Round trip over it
    /// fails as `timeout`; when the server ignored the break, the Session is
    /// lost too, and the Connection becomes Broken.
    pub fn timed<T>(
        &self,
        limit: Option<Duration>,
        f: impl FnOnce() -> Result<T, Error>,
    ) -> Result<T, Error> {
        self.with(|session| Ok(session.set_call_timeout(limit)?))?;
        let started = Instant::now();
        let result = f();
        // Fails only once the Session is gone: nothing left to restore.
        let _ = self.with(|session| Ok(session.set_call_timeout(self.call_timeout)?));
        result.map_err(|e| match limit {
            Some(limit) if e.kind() == Kind::Timeout => Error::timeout(limit, false),
            // The crate tells an ignored break only as "unable to recover":
            // a lost Session after the whole limit went by is that.
            Some(limit) if e.kind() == Kind::ConnectionLost && started.elapsed() >= limit => {
                Error::timeout(limit, true)
            }
            _ => e,
        })
    }

    /// Registers a native cursor of this Connection, so Release can drop it.
    pub fn register(&self, cursor: oracledb::Cursor) -> Arc<CursorSlot> {
        let slot = Arc::new(Mutex::new(Some(cursor)));
        let mut cursors = lock(&self.cursors);
        cursors.retain(|cursor| cursor.strong_count() > 0);
        cursors.push(Arc::downgrade(&slot));
        slot
    }

    /// Registers a native LOB of this Connection, so Release can drop it:
    /// its locator must not outlive the loan of the Session.
    pub fn register_lob(&self, lob: oracledb::Lob) -> Arc<LobSlot> {
        let slot = Arc::new(Mutex::new(Some(lob)));
        let mut lobs = lock(&self.lobs);
        lobs.retain(|lob| lob.strong_count() > 0);
        lobs.push(Arc::downgrade(&slot));
        slot
    }

    /// Runs a native method body of this Connection or of one of its
    /// Cursors or Lobs (see [`run`]). An error that lost the Session, or a panic,
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
    /// 1. drops every native cursor and LOB, so their closes queue on the
    ///    Session;
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
        let lobs = std::mem::take(&mut *lock(&self.lobs));
        for lob in lobs.iter().filter_map(Weak::upgrade) {
            let _ = catch_unwind(AssertUnwindSafe(|| lock(&lob).take()));
        }
        // A Broken Session is rolled back too, then dropped from its Pool.
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

/// Reads `$pool` into the Pool key.
fn read_pool(pool: &ZendHashTable) -> Result<PoolKey, Error> {
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
        time_zone: default_time_zone()?,
    };
    Ok(key)
}

/// PHP's default time zone, by name (`date_default_timezone_get()`): the
/// Session time zone (spec #32 §8, C20).
fn default_time_zone() -> Result<String, Error> {
    let zone = ext_php_rs::zend::Function::try_from_function("date_default_timezone_get")
        .and_then(|function| function.try_call(vec![]).ok())
        .and_then(|zone| zone.str().map(str::to_string))
        .ok_or_else(|| Error::unsupported("PHP's default time zone cannot be read."))?;
    Ok(zone)
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
            let key = read_pool(pool)?;
            let settings = Settings::read(session)?;
            let lease = pool::acquire(&key)?;
            // Overwrites what the Session's previous Connection left.
            settings.apply(lease.session())?;
            Ok(Self {
                state: Arc::new(ConnState::new(lease, settings.call_timeout)),
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
            // Types are described here, outside the Session's lock: reading
            // the binds may run PHP code.
            let mut binds = Binds::read(binds, &|what, lookup| self.state.describe(what, lookup))?;
            let mut implicit = Vec::new();
            let prepare = |session: &oracledb::Connection| {
                #[cfg(debug_assertions)]
                if sql.starts_with(PANIC_HOOK) {
                    panic!("injected through the test hook");
                }
                let statement = options
                    .commit(session.statement(sql)?)
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
                Ok(statement)
            };
            let mut execute = |session: &oracledb::Connection,
                               mut statement: oracledb::Statement,
                               binds: &mut Binds| {
                // The crate keeps the Session's last warning for the
                // Session's whole life, across pooled Connections, so a
                // warning belongs to this execute only if it changed during
                // it. The same text twice in a row is
                // missed the second time: that needs a per-execute warning
                // in the fork.
                let before = session.last_warning()?;
                let (is_plsql, is_dml) = (statement.is_plsql(), statement.is_dml());
                let (query, mut result) = {
                    let matched = binds.matched(statement.bind_names())?;
                    let params: Vec<_> = matched.iter().map(|(n, v)| (n.as_str(), *v)).collect();
                    if statement.is_query() {
                        (Some(statement.query_named(&params)?), None)
                    } else {
                        (None, Some(statement.execute_named(&params)?))
                    }
                };
                // What the execute gave back may point at the temporary LOBs
                // its binds were written into (an OUT parameter set to the
                // IN one, a `select :c`): everything born of it keeps them.
                let options = &options.keeping(binds.take_written(&self.state));
                let (row_count, cursor, out_values) = match query {
                    Some(cursor) => {
                        let cursor = NativeCursor::new(
                            Arc::clone(&self.state),
                            cursor,
                            options,
                            Vec::new(),
                        )?;
                        (
                            0,
                            Some(cursor),
                            binds.out_values(None, false, &self.state, options)?,
                        )
                    }
                    None => {
                        // The server counts 1 for a PL/SQL block; only DML
                        // counts.
                        let count = match &result {
                            Some(result) if is_dml => result.rows_affected(),
                            _ => 0,
                        };
                        let out_values =
                            binds.out_values(result.as_mut(), is_plsql, &self.state, options)?;
                        (count, None, out_values)
                    }
                };
                // Each DBMS_SQL.RETURN_RESULT, in production order,
                // described in this Round trip; rows come on first fetch.
                // Registered like any Cursor, so Release drops them.
                if let Some(result) = result.as_mut() {
                    for result in result.implicit_results() {
                        implicit.push(NativeCursor::new(
                            Arc::clone(&self.state),
                            result,
                            options,
                            Vec::new(),
                        )?);
                    }
                }
                let warning = session
                    .last_warning()?
                    .filter(|w| before.as_ref() != Some(w));
                Ok((row_count, cursor, warning, out_values))
            };
            let (row_count, cursor, warning, out_values) =
                self.state.timed(options.timeout, || {
                    // Building parses the SQL on the client: no Round trip.
                    let statement = self.state.with(prepare)?;
                    // Every check that needs no Round trip, before the LOB
                    // writes, which do.
                    binds.matched(statement.bind_names())?;
                    if !statement.is_query() && !statement.is_plsql() {
                        binds.refuse_returned_lobs()?;
                        binds.refuse_returned_objects()?;
                    }
                    binds.write_lobs(&self.state)?;
                    self.state
                        .with(|session| execute(session, statement, &mut binds))
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
            let mut results = ZendHashTable::new();
            for cursor in implicit {
                results.push(ZendClassObject::new(cursor)).map_err(put)?;
            }
            result.insert("implicitResults", results).map_err(put)?;
            result.insert("warning", warning.as_deref()).map_err(put)?;
            Ok(result)
        })
    }

    /// Runs one DML statement or PL/SQL block once per row of binds, every
    /// row in one execute (see [`batch`]):
    /// `['rowCount' => int, 'outValues' => array<string, list<list<mixed>>>]`,
    /// each OUT bind giving one list per row. When rows fail, none is
    /// applied, and the error (kind `batch`) lists every one of them.
    #[php(sensitive(rows))]
    pub fn execute_many(
        &self,
        sql: &str,
        rows: &ZendHashTable,
        options: &ZendHashTable,
    ) -> PhpResult<ZBox<ZendHashTable>> {
        self.state.run(|| {
            let options = Options::read(options)?;
            let outcome = batch::execute(&self.state, sql, rows, &options)?;
            let mut result = ZendHashTable::with_capacity(2);
            result
                .insert(
                    "rowCount",
                    i64::try_from(outcome.row_count).unwrap_or(i64::MAX),
                )
                .map_err(put)?;
            result
                .insert("outValues", outcome.out_values)
                .map_err(put)?;
            Ok(result)
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
        self.state.run(|| {
            let tracing = Tracing::read("$tracing", tracing)?;
            self.state.with(|session| tracing.apply(session))
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
