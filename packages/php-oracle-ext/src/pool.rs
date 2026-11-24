//! Where Sessions come from (spec #32 §11): one Pool per process per Pool
//! key, held in a `static` registry, or a new Session per `connect()` for a
//! Standalone Connection.
//!
//! - A Pool is created lazily, on the first `connect()` for its key, never in
//!   MINIT: FPM forks its workers after MINIT.
//! - Every lookup checks the PID. A forked child never touches the Pool it
//!   inherited (its manager thread does not exist in the child, and its
//!   sockets belong to the parent): it forgets it and creates its own.
//! - MSHUTDOWN closes every Pool of this process and joins its manager
//!   thread, so no thread runs code of an unloaded library (FrankenPHP
//!   reloads the module in the same process). A Pool that fails to close is
//!   logged and leaked.
//! - The crate's `acquire()` waits forever at the ceiling (C13), so a timed
//!   counting gate with `maxConnections` permits sits in front of it.
//! - `min` and `increment` are 1 (C12); a Session idle for 60 s or more is
//!   pinged on acquire and dropped if the ping fails.

use std::collections::HashMap;
use std::sync::{Arc, Condvar, LazyLock, Mutex, MutexGuard};
use std::time::{Duration, Instant};

use crate::connect;
use crate::error::{Error, Kind};

/// The minimum idle time after which an acquired Session is pinged first.
const PING_INTERVAL: Duration = Duration::from_secs(60);

/// Run on every new Session, before its first use: ORA-00001 would
/// otherwise carry an ORA-03301 line with the row's values (spec #32,
/// amendment to §11 / §12).
const SESSION_SETUP: &str = "alter session set error_message_details = off";

/// The Pool key: exactly the fields of `$pool` (spec #32 §3). Durations are
/// in milliseconds.
#[derive(Clone, PartialEq, Eq, Hash)]
pub struct PoolKey {
    pub dsn: String,
    pub user: String,
    pub password: String,
    pub proxy_user: Option<String>,
    pub wallet_location: Option<String>,
    pub wallet_password: Option<String>,
    pub config_dir: Option<String>,
    pub connect_timeout: i64,
    pub drcp_connection_class: Option<String>,
    pub max_connections: i64,
    pub acquire_timeout: i64,
    pub statement_cache_size: i64,
    pub standalone: bool,
    /// PHP's default time zone when the Pool was created
    /// (`date_default_timezone_get()`), by name: every Session of the Pool
    /// gets it as its Session time zone at logon (C20). Not in `$pool`: the
    /// Extension reads it itself. A later `date_default_timezone_set()`
    /// makes the next `connect()` use another Pool.
    pub time_zone: String,
}

impl PoolKey {
    fn max_connections(&self) -> Result<usize, Error> {
        usize::try_from(self.max_connections)
            .ok()
            .filter(|n| *n >= 1)
            .ok_or_else(|| Error::new(Kind::Configuration, "'maxConnections' must be at least 1."))
    }

    fn acquire_timeout(&self) -> Result<Duration, Error> {
        u64::try_from(self.acquire_timeout)
            .map(Duration::from_millis)
            .map_err(|_| {
                Error::new(
                    Kind::Configuration,
                    "'acquireTimeout' must not be negative.",
                )
            })
    }

    fn statement_cache_size(&self) -> Result<usize, Error> {
        usize::try_from(self.statement_cache_size).map_err(|_| {
            Error::new(
                Kind::Configuration,
                "'statementCacheSize' must not be negative.",
            )
        })
    }

    /// Fails before any network I/O if the `dsn` or the wallet is one the
    /// crate would mishandle (spec #32 §11, "Connect parameters").
    fn check(&self) -> Result<Option<String>, Error> {
        let config_dir = connect::config_dir(self.config_dir.as_deref());
        connect::check_dsn(&self.dsn, config_dir.as_deref())?;
        if let Some(wallet) = &self.wallet_location {
            connect::check_wallet(wallet)?;
        }
        Ok(config_dir)
    }

    fn user(&self) -> String {
        connect::user(&self.user, self.proxy_user.as_deref())
    }

    fn connection_class(&self) -> String {
        connect::connection_class(self.drcp_connection_class.as_deref(), &self.user)
    }

    fn connect_timeout(&self) -> Result<Option<Duration>, Error> {
        connect::connect_timeout(self.connect_timeout).map(Some)
    }

    fn config(&self) -> Result<oracledb::Config, Error> {
        let config_dir = self.check()?;
        let mut config = oracledb::Config::default()
            .set_credentials(&self.user(), &self.password)
            .set_stmtcachesize(self.statement_cache_size()?)
            .set_time_zone(&self.time_zone)
            .set_cclass(self.connection_class())
            .set_transport_connect_timeout(self.connect_timeout()?);
        if let Some(dir) = &config_dir {
            config = config.set_config_dir(dir);
        }
        if let Some(wallet) = &self.wallet_location {
            config = config.set_wallet_location(wallet);
        }
        if let Some(password) = &self.wallet_password {
            config = config.set_wallet_password(password);
        }
        config.set_connect_string(&self.dsn).map_err(Error::connect)
    }

    fn pool_config(&self) -> Result<oracledb::PoolConfig, Error> {
        let config_dir = self.check()?;
        let mut config = oracledb::PoolConfig::default()
            .set_credentials(&self.user(), &self.password)
            .set_stmtcachesize(self.statement_cache_size()?)
            .set_min_connections(1)
            .set_connection_increment(1)
            .set_max_connections(self.max_connections()?)
            .set_ping_interval(Some(PING_INTERVAL))
            .set_time_zone(&self.time_zone)
            .set_cclass(self.connection_class())
            .set_transport_connect_timeout(self.connect_timeout()?);
        if let Some(dir) = &config_dir {
            config = config.set_config_dir(dir);
        }
        if let Some(wallet) = &self.wallet_location {
            config = config.set_wallet_location(wallet);
        }
        if let Some(password) = &self.wallet_password {
            config = config.set_wallet_password(password);
        }
        config.set_connect_string(&self.dsn).map_err(Error::connect)
    }

    /// A crate error while opening a Session: a transport connect that ran
    /// out of `connectTimeout` is ORA-12170.
    fn connect_error(&self, e: oracledb::Error) -> Error {
        match e.kind() {
            oracledb::ErrorKind::CallTimeoutExceeded => {
                Error::connect_timeout(self.connect_timeout)
            }
            _ => Error::connect(e),
        }
    }
}

impl std::fmt::Debug for PoolKey {
    /// Never shows the secrets.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("PoolKey")
            .field("dsn", &self.dsn)
            .field("user", &self.user)
            .field("standalone", &self.standalone)
            .field("time_zone", &self.time_zone)
            .finish_non_exhaustive()
    }
}

/// A Session on loan to one Connection. Dropping it ends the loan: a pooled
/// Session goes back to its Pool (then its permit frees), a standalone one
/// logs off.
pub struct Lease {
    // Ended in Drop, before `permit` frees.
    session: Option<oracledb::Connection>,
    permit: Option<Permit>,
    notes: Arc<SessionNotes>,
}

/// What the Extension learns about a Session while using it, kept with the
/// Session across loans, as the crate keeps its type descriptions (spec #32
/// §7: described on first use, cached per Session, never invalidated).
#[derive(Default)]
pub struct SessionNotes {
    /// Packaged Collection types already checked, by canonical name:
    /// whether each is indexed by strings (`INDEX BY VARCHAR2`), which the
    /// crate cannot tell from a nested table.
    pub string_indexed: Mutex<HashMap<String, bool>>,
}

impl Lease {
    /// What the Extension learned about this Session so far.
    pub fn notes(&self) -> &SessionNotes {
        &self.notes
    }

    pub fn session(&self) -> &oracledb::Connection {
        // Only Drop and discard() take it.
        self.session
            .as_ref()
            .expect("a Lease holds its Session until dropped")
    }

    /// Ends the loan of a Session that must never be used again (Broken).
    ///
    /// A standalone Session logs off (or is simply gone). A pooled one is
    /// dropped from the crate's Pool, never returned, even when its
    /// transport is still open (a panic, ORA-00028 from a kill without
    /// `immediate`, ORA-00600): the Pool's manager logs it off and the Pool
    /// can open another one in its place. The permit then frees as usual.
    pub fn discard(mut self) {
        let Some(mut session) = self.session.take() else {
            return;
        };
        if let Some(permit) = &self.permit
            && let (Ok(sid), Ok(serial)) = (session.session_id(), session.serial_num())
        {
            lock(&permit.pool.set_up).remove(&(sid, serial));
        }
        let _ = session.close_and_drop();
    }
}

impl Drop for Lease {
    fn drop(&mut self) {
        if let Some(mut session) = self.session.take() {
            // A Session that fails to go back or log off is gone either way.
            let _ = session.close();
        }
        self.permit.take();
    }
}

/// Gets a Session for `key`: from the process's Pool for it, or a new
/// standalone one.
pub fn acquire(key: &PoolKey) -> Result<Lease, Error> {
    if key.standalone {
        connect_standalone(key)
    } else {
        REGISTRY.pool(key)?.acquire()
    }
}

/// Closes every Pool of this process (MSHUTDOWN).
pub fn shutdown() {
    REGISTRY.shutdown(&mut log);
}

fn connect_standalone(key: &PoolKey) -> Result<Lease, Error> {
    let session = oracledb::connect(key.config()?).map_err(|e| key.connect_error(e))?;
    setup(&session)?;
    Ok(Lease {
        session: Some(session),
        permit: None,
        notes: Arc::default(),
    })
}

fn setup(session: &oracledb::Connection) -> Result<(), Error> {
    session
        .execute(SESSION_SETUP, &[])
        .map_err(Error::connect)?;
    Ok(())
}

/// One Pool of the registry, with the gate in front of it.
pub struct SharedPool {
    pool: oracledb::Pool,
    gate: Gate,
    max_connections: usize,
    acquire_timeout: Duration,
    /// `connectTimeout`, in ms, for the error when it runs out.
    connect_timeout: i64,
    /// The Sessions already set up, by (SID, serial#) from the logon
    /// handshake: a Session is set up once, when it is new. Each keeps its
    /// notes across loans.
    set_up: Mutex<HashMap<(usize, usize), Arc<SessionNotes>>>,
}

impl SharedPool {
    fn create(key: &PoolKey) -> Result<Self, Error> {
        let max_connections = key.max_connections()?;
        let acquire_timeout = key.acquire_timeout()?;
        let pool = oracledb::create_pool(key.pool_config()?).map_err(|e| key.connect_error(e))?;
        Ok(Self {
            pool,
            gate: Gate::new(max_connections),
            max_connections,
            acquire_timeout,
            connect_timeout: key.connect_timeout,
            set_up: Mutex::new(HashMap::new()),
        })
    }

    fn acquire(self: &Arc<Self>) -> Result<Lease, Error> {
        let permit = self.enter()?;
        let session = self.pool.acquire().map_err(|e| match e.kind() {
            oracledb::ErrorKind::CallTimeoutExceeded => {
                Error::connect_timeout(self.connect_timeout)
            }
            _ => Error::connect(e),
        })?;
        let mut lease = Lease {
            session: Some(session),
            permit: Some(permit),
            notes: Arc::default(),
        };
        let id = (
            lease.session().session_id().map_err(Error::connect)?,
            lease.session().serial_num().map_err(Error::connect)?,
        );
        let known = lock(&self.set_up).get(&id).cloned();
        match known {
            Some(notes) => lease.notes = notes,
            None => {
                setup(lease.session())?;
                lock(&self.set_up).insert(id, Arc::clone(&lease.notes));
            }
        }
        Ok(lease)
    }

    /// Waits up to `acquireTimeout` for one of the `maxConnections` permits.
    fn enter(self: &Arc<Self>) -> Result<Permit, Error> {
        let deadline = Instant::now() + self.acquire_timeout;
        let mut free = lock(&self.gate.free);
        while *free == 0 {
            let left = deadline.saturating_duration_since(Instant::now());
            if left.is_zero() {
                return Err(Error::new(
                    Kind::PoolExhausted,
                    format!(
                        "No Session of the Pool became free within the acquireTimeout of {} ms; all {} (maxConnections) are in use.",
                        self.acquire_timeout.as_millis(),
                        self.max_connections,
                    ),
                ));
            }
            free = self
                .gate
                .freed
                .wait_timeout(free, left)
                .unwrap_or_else(|poisoned| poisoned.into_inner())
                .0;
        }
        *free -= 1;
        Ok(Permit {
            pool: Arc::clone(self),
        })
    }
}

/// A counting gate: `free` permits left, `freed` signalled on each return.
struct Gate {
    free: Mutex<usize>,
    freed: Condvar,
}

impl Gate {
    fn new(permits: usize) -> Self {
        Self {
            free: Mutex::new(permits),
            freed: Condvar::new(),
        }
    }
}

/// The right to hold one Session of a Pool, returned on drop.
struct Permit {
    pool: Arc<SharedPool>,
}

impl Drop for Permit {
    fn drop(&mut self) {
        *lock(&self.pool.gate.free) += 1;
        self.pool.gate.freed.notify_one();
    }
}

/// A lock that a panic elsewhere does not make unusable.
pub fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
}

/// The process's Pools.
static REGISTRY: LazyLock<Registry> = LazyLock::new(Registry::default);

struct Entry {
    pool: Arc<SharedPool>,
    /// The process that created the Pool.
    pid: u32,
}

/// Pools by key, each tagged with the PID of the process that created it.
#[derive(Default)]
pub struct Registry {
    entries: Mutex<HashMap<PoolKey, Entry>>,
}

impl Registry {
    /// The Pool for `key`, created on first use; an inherited one (another
    /// PID) is forgotten, never closed, and replaced.
    pub fn pool(&self, key: &PoolKey) -> Result<Arc<SharedPool>, Error> {
        let pid = std::process::id();
        let mut entries = lock(&self.entries);
        if let Some(entry) = entries.get(key) {
            if entry.pid == pid {
                return Ok(Arc::clone(&entry.pool));
            }
            if let Some(inherited) = entries.remove(key) {
                std::mem::forget(inherited);
            }
        }
        let pool = Arc::new(SharedPool::create(key)?);
        entries.insert(
            key.clone(),
            Entry {
                pool: Arc::clone(&pool),
                pid,
            },
        );
        Ok(pool)
    }

    /// Closes every Pool this process created, joining its manager thread,
    /// and forgets inherited ones. A Pool that cannot close (a Session still
    /// out) is reported to `log` and leaked.
    pub fn shutdown(&self, log: &mut dyn FnMut(String)) {
        let pid = std::process::id();
        let entries = std::mem::take(&mut *lock(&self.entries));
        for (key, entry) in entries {
            if entry.pid != pid {
                std::mem::forget(entry);
                continue;
            }
            match Arc::try_unwrap(entry.pool) {
                Ok(mut shared) => {
                    if let Err(e) = shared.pool.close() {
                        log(format!(
                            "rajska_oracle: could not close the Pool for {}@{}, leaking it: {e}",
                            key.user, key.dsn
                        ));
                        std::mem::forget(shared);
                    }
                }
                Err(shared) => {
                    log(format!(
                        "rajska_oracle: could not close the Pool for {}@{}, leaking it: a Session is still in use",
                        key.user, key.dsn
                    ));
                    std::mem::forget(shared);
                }
            }
        }
    }
}

/// Writes to PHP's error log (the `error_log` INI setting, else the SAPI's
/// log), never as a PHP warning.
fn log(message: String) {
    unsafe extern "C" {
        fn php_log_err_with_severity(message: *const std::ffi::c_char, severity: i32);
    }
    // syslog's LOG_NOTICE.
    const LOG_NOTICE: i32 = 5;
    if let Ok(message) = std::ffi::CString::new(message) {
        // SAFETY: a NUL-terminated string that outlives the call; PHP's log
        // is usable during MSHUTDOWN.
        unsafe { php_log_err_with_severity(message.as_ptr(), LOG_NOTICE) };
    }
}

#[cfg(test)]
mod tests {
    //! What a real database cannot provoke from PHP: a fork, several threads
    //! of one process, and MSHUTDOWN. They need the database of the Core
    //! suite: ORACLE_CONNECT_STRING, ORACLE_USER and ORACLE_PASSWORD.

    use std::collections::HashSet;
    use std::sync::atomic::{AtomicI64, Ordering};
    use std::sync::{Arc, Barrier, Mutex, MutexGuard};
    use std::thread;
    use std::time::{Duration, Instant};

    use super::{Lease, PoolKey, Registry};

    /// A key of its own per call, so no two tests share a Pool.
    fn key() -> PoolKey {
        static NEXT: AtomicI64 = AtomicI64::new(1);
        let env = |name| std::env::var(name).unwrap_or_else(|_| panic!("set {name}"));
        PoolKey {
            dsn: env("ORACLE_CONNECT_STRING"),
            user: env("ORACLE_USER"),
            password: env("ORACLE_PASSWORD"),
            proxy_user: None,
            wallet_location: None,
            wallet_password: None,
            config_dir: None,
            connect_timeout: 20_000 + NEXT.fetch_add(1, Ordering::Relaxed),
            drcp_connection_class: None,
            max_connections: 4,
            acquire_timeout: 5_000,
            statement_cache_size: 20,
            standalone: false,
            time_zone: "UTC".to_string(),
        }
    }

    /// The Session behind a Lease: (SID, serial#).
    fn id(lease: &Lease) -> (usize, usize) {
        let session = lease.session();
        (session.session_id().unwrap(), session.serial_num().unwrap())
    }

    /// Runs the tests one at a time. A fork copies every lock as it is: a
    /// lock another test's thread holds at that moment (a lazy init in the
    /// crate, say) stays held forever in the child.
    fn one_at_a_time() -> MutexGuard<'static, ()> {
        static TESTS: Mutex<()> = Mutex::new(());
        TESTS
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
    }

    /// Runs `check` in a forked child and tells whether it returned true
    /// within 60 s. The child leaves with `_exit`, so it never drops what it
    /// inherited.
    #[cfg(unix)]
    fn in_child(check: impl FnOnce() -> bool) -> bool {
        // SAFETY: the child runs only `check`, then `_exit`s.
        match unsafe { libc::fork() } {
            -1 => panic!("fork failed"),
            0 => {
                let passed =
                    std::panic::catch_unwind(std::panic::AssertUnwindSafe(check)).unwrap_or(false);
                // SAFETY: ends the child without running the parent's
                // destructors.
                unsafe { libc::_exit(if passed { 0 } else { 1 }) }
            }
            child => {
                let deadline = Instant::now() + Duration::from_secs(60);
                let mut status = 0;
                loop {
                    // SAFETY: polls the child just forked.
                    match unsafe { libc::waitpid(child, &mut status, libc::WNOHANG) } {
                        0 if Instant::now() < deadline => {
                            thread::sleep(Duration::from_millis(20));
                        }
                        0 => {
                            // SAFETY: kills and reaps the child just forked.
                            unsafe {
                                libc::kill(child, libc::SIGKILL);
                                libc::waitpid(child, &mut status, 0);
                            }
                            panic!("the forked child did not finish within 60 s");
                        }
                        pid => {
                            assert_eq!(pid, child);
                            return libc::WIFEXITED(status) && libc::WEXITSTATUS(status) == 0;
                        }
                    }
                }
            }
        }
    }

    /// The threads of this process.
    #[cfg(target_os = "linux")]
    fn threads() -> usize {
        std::fs::read_dir("/proc/self/task").unwrap().count()
    }

    /// Pool and lifecycle 14: the child forgets the Pool it inherited (never
    /// closes it, which would log off the parent's Sessions over shared
    /// sockets) and creates its own.
    #[test]
    #[cfg(unix)]
    fn a_forked_child_forgets_the_inherited_pool_and_creates_its_own() {
        let _one = one_at_a_time();
        let registry = Registry::default();
        let key = key();
        let inherited = registry.pool(&key).unwrap();
        let parent_session = id(&inherited.acquire().unwrap());

        assert!(in_child(|| {
            let own = registry.pool(&key).unwrap();
            let lease = own.acquire().unwrap();
            let fresh = !Arc::ptr_eq(&own, &inherited)
                && lease.session().ping().is_ok()
                && id(&lease) != parent_session;
            drop(lease);
            drop(own);
            // The child's MSHUTDOWN closes its own Pool only.
            let mut logged = Vec::new();
            registry.shutdown(&mut |message| logged.push(message));
            fresh && logged.is_empty()
        }));

        let lease = registry.pool(&key).unwrap().acquire().unwrap();
        assert_eq!(id(&lease), parent_session);
        lease.session().ping().unwrap();
    }

    /// Under ZTS every thread shares the process's Pool, and the Pool grows
    /// under concurrent load to one Session per thread.
    #[test]
    fn threads_share_one_pool_and_never_a_session() {
        let _one = one_at_a_time();
        const THREADS: usize = 4;
        let registry = Registry::default();
        let key = key();
        let all_held = Barrier::new(THREADS);

        let seen: Vec<(usize, (usize, usize))> = thread::scope(|scope| {
            let handles: Vec<_> = (0..THREADS)
                .map(|_| {
                    scope.spawn(|| {
                        let pool = registry.pool(&key).unwrap();
                        let lease = pool.acquire().unwrap();
                        lease.session().ping().unwrap();
                        all_held.wait();
                        (Arc::as_ptr(&pool) as usize, id(&lease))
                    })
                })
                .collect();
            handles.into_iter().map(|h| h.join().unwrap()).collect()
        });

        let pools: HashSet<_> = seen.iter().map(|(pool, _)| pool).collect();
        let sessions: HashSet<_> = seen.iter().map(|(_, id)| id).collect();
        assert_eq!(pools.len(), 1, "one Pool");
        assert_eq!(sessions.len(), THREADS, "one Session per thread");
    }

    /// MSHUTDOWN joins every manager thread, so a FrankenPHP worker restart
    /// (MSHUTDOWN, unload, MINIT in the same process) leaves no thread
    /// running code of the unloaded library. Counted in a forked child,
    /// where no other test's threads run.
    #[test]
    #[cfg(target_os = "linux")]
    fn shutdown_closes_every_pool_and_joins_its_manager_thread() {
        let _one = one_at_a_time();
        assert!(in_child(|| {
            let registry = Registry::default();
            let before = threads();
            for _ in 0..2 {
                let lease = registry.pool(&key()).unwrap().acquire().unwrap();
                lease.session().ping().unwrap();
            }
            let with_pools = threads();
            let mut logged = Vec::new();
            registry.shutdown(&mut |message| logged.push(message));
            with_pools == before + 2 && threads() == before && logged.is_empty()
        }));
    }

    /// A Pool that cannot close at MSHUTDOWN (a Session still out) is logged
    /// and leaked, never waited for.
    #[test]
    #[cfg(target_os = "linux")]
    fn a_pool_that_cannot_close_is_logged_and_leaked() {
        let _one = one_at_a_time();
        assert!(in_child(|| {
            let registry = Registry::default();
            let before = threads();
            let lease = registry.pool(&key()).unwrap().acquire().unwrap();
            let mut logged = Vec::new();
            registry.shutdown(&mut |message| logged.push(message));
            let leaked = threads() == before + 1;
            drop(lease);
            leaked && logged.len() == 1 && logged[0].contains("leaking it")
        }));
    }
}
