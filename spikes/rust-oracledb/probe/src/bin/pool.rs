//! Throwaway probe for the "One Pool shared across threads" ticket (#11) of the
//! rust-oracledb spike.
//!
//! Shares one `oracledb::Pool` between OS threads (as FrankenPHP ZTS workers
//! would), runs Calls against `fx_pool` / `fx_scalar` (fixtures/sql/07_pool.sql,
//! 01_scalar.sql) and records: thread safety of the types, how the Pool grows,
//! correctness under load (results, one holder per Session, package state),
//! what happens when the Pool is exhausted, `set_call_timeout`, and DRCP
//! (`(SERVER=POOLED)`). Same tags as the other probes:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! DRCP is observed from a second connection as SYS AS SYSDBA on the CDB
//! (`v$cpool_stats`, `v$cpool_conn_info`, `v$session`). The ddev database is
//! recreated on every restart with DRCP stopped, so the probe starts the default
//! DRCP pool (`dbms_connection_pool.start_pool`) when it finds it INACTIVE.
//! Overrides: ORACLE_CDB_CONNECT_STRING (default oracle:1521/FREE) and
//! ORACLE_SYS_PASSWORD (default oracle).
//!
//! Scenarios that might hang run under a watchdog; a hang is reported and its
//! threads are left behind (the process exits at the end anyway).
//!
//! Run inside ddev: `cd spikes/rust-oracledb/probe && ddev cargo run -q --release --bin pool`.

use oracledb::{Connection, Pool, PoolConfig};
use std::collections::{HashMap, HashSet};
use std::fmt::Debug;
use std::marker::PhantomData;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering::SeqCst};
use std::sync::{Arc, Condvar, Mutex, mpsc};
use std::thread;
use std::time::{Duration, Instant};

type R<T> = Result<T, Box<dyn std::error::Error + Send + Sync>>;

struct Tally {
    pass: u32,
    fail: u32,
    err: u32,
    obs: u32,
}

impl Tally {
    fn check<T: Debug>(&mut self, id: &str, what: &str, expected: &str, got: R<T>) {
        match got {
            Ok(v) => {
                let s = format!("{v:?}");
                if s == expected {
                    self.pass += 1;
                    println!("PASS {id:<5} {what} => {s}");
                } else {
                    self.fail += 1;
                    println!("FAIL {id:<5} {what} => {s} (expected {expected})");
                }
            }
            Err(e) => {
                self.err += 1;
                println!("ERR  {id:<5} {what} => {}", one_line(&e.to_string()));
            }
        }
    }

    fn observe<T: Debug>(&mut self, id: &str, what: &str, got: R<T>) {
        self.obs += 1;
        match got {
            Ok(v) => println!("OBS  {id:<5} {what} => Ok({v:?})"),
            Err(e) => println!("OBS  {id:<5} {what} => Err({})", one_line(&e.to_string())),
        }
    }
}

fn one_line(s: &str) -> String {
    s.lines().next().unwrap_or("").to_string()
}

fn env(k: &str) -> String {
    std::env::var(k).unwrap_or_else(|_| panic!("{k} is not set"))
}

fn env_or(k: &str, default: &str) -> String {
    std::env::var(k).unwrap_or_else(|_| default.to_string())
}

/// Host, port and service name of ORACLE_CONNECT_STRING (`host:port/service`).
fn host_port_service() -> (String, String, String) {
    let cs = env("ORACLE_CONNECT_STRING");
    let (hp, svc) = cs.split_once('/').expect("host:port/service");
    let (h, p) = hp.split_once(':').unwrap_or((hp, "1521"));
    (h.to_string(), p.to_string(), svc.to_string())
}

fn dedicated_cs() -> String {
    env("ORACLE_CONNECT_STRING")
}

/// Easy Connect with the `:pooled` server type (DRCP).
fn pooled_cs() -> String {
    format!("{}:pooled", env("ORACLE_CONNECT_STRING"))
}

/// Full descriptor with `(SERVER=POOLED)` and an optional extra CONNECT_DATA part.
fn pooled_descriptor(extra: &str) -> String {
    let (h, p, s) = host_port_service();
    format!(
        "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST={h})(PORT={p}))\
         (CONNECT_DATA=(SERVICE_NAME={s})(SERVER=POOLED){extra}))"
    )
}

fn connect(cs: &str) -> R<Connection> {
    let config = oracledb::Config::default()
        .set_credentials(&env("ORACLE_USER"), &env("ORACLE_PASSWORD"))
        .set_connect_string(cs)?;
    Ok(oracledb::connect(config)?)
}

fn pool_config(cs: &str, min: usize, max: usize, inc: usize) -> R<PoolConfig> {
    Ok(PoolConfig::default()
        .set_credentials(&env("ORACLE_USER"), &env("ORACLE_PASSWORD"))
        .set_connect_string(cs)?
        .set_min_connections(min)
        .set_max_connections(max)
        .set_connection_increment(inc))
}

fn pool(cs: &str, min: usize, max: usize, inc: usize) -> R<Arc<Pool>> {
    Ok(Arc::new(oracledb::create_pool(pool_config(cs, min, max, inc)?)?))
}

fn select_one(c: &Connection) -> R<i64> {
    Ok(c.query_row("select 1 from dual", &[])?.get(0)?)
}

fn sid(c: &Connection) -> R<i64> {
    Ok(c.query_row("select fx_pool.session_id from dual", &[])?.get(0)?)
}

fn secs(d: Duration) -> String {
    format!("{:.3}", d.as_secs_f64())
}

/// Runs `f` on its own thread and gives up after `limit`. A hang is an error;
/// the thread is left behind.
fn watchdog<T: Send + 'static>(limit: Duration, f: impl FnOnce() -> R<T> + Send + 'static) -> R<T> {
    let (tx, rx) = mpsc::channel();
    thread::spawn(move || {
        let _ = tx.send(f());
    });
    match rx.recv_timeout(limit) {
        Ok(r) => r,
        Err(_) => Err(format!("HANG: no result after {} s", limit.as_secs()).into()),
    }
}

// ---------------------------------------------------------------------------
// Thread safety, read at compile time without failing the build: the inherent
// `get` exists only when the bound holds and wins over the blanket trait one.

struct Probe<T: ?Sized>(PhantomData<T>);
trait NotSend {
    fn send(&self) -> bool {
        false
    }
}
trait NotSync {
    fn sync(&self) -> bool {
        false
    }
}
impl<T: ?Sized> NotSend for Probe<T> {}
impl<T: ?Sized> NotSync for Probe<T> {}
impl<T: ?Sized + Send> Probe<T> {
    fn send(&self) -> bool {
        true
    }
}
impl<T: ?Sized + Sync> Probe<T> {
    fn sync(&self) -> bool {
        true
    }
}

// ---------------------------------------------------------------------------
// Holding Connections at the same time.

#[derive(Debug)]
struct Held {
    /// Threads that got a Connection before the deadline.
    acquired: usize,
    open: usize,
    busy: usize,
    /// Threads that, once released, ran `select 1` successfully.
    ok_after_release: usize,
    secs_to_all: Option<String>,
    open_after_release: usize,
}

/// Starts `n` threads that each acquire a Connection and hold it until told to
/// release it. Waits up to `wait` for all of them, takes the Pool's counts,
/// then releases them and waits for every thread to run one Call and finish.
fn hold(pool: &Arc<Pool>, n: usize, wait: Duration) -> R<Held> {
    let acquired = Arc::new(AtomicUsize::new(0));
    let release = Arc::new(AtomicBool::new(false));
    let (tx, rx) = mpsc::channel::<bool>();
    let t0 = Instant::now();
    for _ in 0..n {
        let (pool, acquired, release, tx) = (pool.clone(), acquired.clone(), release.clone(), tx.clone());
        thread::spawn(move || {
            let ok = match pool.acquire() {
                Ok(c) => {
                    acquired.fetch_add(1, SeqCst);
                    while !release.load(SeqCst) {
                        thread::sleep(Duration::from_millis(5));
                    }
                    select_one(&c).is_ok()
                }
                Err(_) => false,
            };
            let _ = tx.send(ok);
        });
    }
    drop(tx);
    let deadline = t0 + wait;
    while acquired.load(SeqCst) < n && Instant::now() < deadline {
        thread::sleep(Duration::from_millis(2));
    }
    let all = acquired.load(SeqCst) == n;
    let secs_to_all = all.then(|| secs(t0.elapsed()));
    let (acq, open, busy) = (acquired.load(SeqCst), pool.open_count()?, pool.busy_count()?);
    release.store(true, SeqCst);
    let end = Instant::now() + Duration::from_secs(60);
    let mut ok = 0;
    for _ in 0..n {
        match rx.recv_timeout(end.saturating_duration_since(Instant::now())) {
            Ok(true) => ok += 1,
            Ok(false) => {}
            Err(_) => break,
        }
    }
    Ok(Held { acquired: acq, open, busy, ok_after_release: ok, secs_to_all, open_after_release: pool.open_count()? })
}

fn held4(h: &Held) -> (usize, usize, usize, usize) {
    (h.acquired, h.open, h.busy, h.ok_after_release)
}

// ---------------------------------------------------------------------------
// Load: many threads, many Calls, each on a freshly acquired Connection.

#[derive(Debug, Default)]
struct Load {
    calls: usize,
    /// add_numbers returned something other than a + b.
    wrong: usize,
    errors: usize,
    /// A Session was seen in use by two holders at once.
    overlaps: usize,
    /// bump_counter did not continue from the last value seen on that Session.
    counter_breaks: usize,
    sids: usize,
    max_open: usize,
    max_busy: usize,
    secs: String,
    calls_per_sec: u64,
    first_error: Option<String>,
}

fn load(pool: &Arc<Pool>, threads: usize, calls: usize) -> R<Load> {
    let in_use = Arc::new(Mutex::new(HashSet::<i64>::new()));
    let last = Arc::new(Mutex::new(HashMap::<i64, i64>::new()));
    let (tx, rx) = mpsc::channel::<Load>();
    let t0 = Instant::now();
    for t in 0..threads {
        let (pool, in_use, last, tx) = (pool.clone(), in_use.clone(), last.clone(), tx.clone());
        thread::spawn(move || {
            let mut l = Load::default();
            for i in 0..calls {
                let a = (t * 1_000_000 + i) as i64;
                let b = (i * 7) as i64;
                let r = (|| -> R<()> {
                    let c = pool.acquire()?;
                    let row = c.query_row(
                        "select fx_pool.session_id, fx_pool.bump_counter, fx_scalar.add_numbers(:1, :2) from dual",
                        &[&a, &b],
                    )?;
                    let (sid, n, sum): (i64, i64, i64) = (row.get(0)?, row.get(1)?, row.get(2)?);
                    if sum != a + b {
                        l.wrong += 1;
                    }
                    let clash = !in_use.lock().unwrap().insert(sid);
                    if clash {
                        l.overlaps += 1;
                    }
                    let prev = last.lock().unwrap().insert(sid, n);
                    if n != prev.map_or(1, |p| p + 1) {
                        l.counter_breaks += 1;
                    }
                    if !clash {
                        in_use.lock().unwrap().remove(&sid);
                    }
                    drop(c);
                    Ok(())
                })();
                l.calls += 1;
                if let Err(e) = r {
                    l.errors += 1;
                    l.first_error.get_or_insert_with(|| one_line(&e.to_string()));
                }
            }
            let _ = tx.send(l);
        });
    }
    drop(tx);
    let mut total = Load::default();
    let mut done = 0;
    let end = t0 + Duration::from_secs(300);
    while done < threads {
        if Instant::now() > end {
            return Err(format!("HANG: {done}/{threads} threads finished after 300 s").into());
        }
        total.max_open = total.max_open.max(pool.open_count()?);
        total.max_busy = total.max_busy.max(pool.busy_count()?);
        while let Ok(l) = rx.try_recv() {
            done += 1;
            total.calls += l.calls;
            total.wrong += l.wrong;
            total.errors += l.errors;
            total.overlaps += l.overlaps;
            total.counter_breaks += l.counter_breaks;
            if total.first_error.is_none() {
                total.first_error = l.first_error;
            }
        }
        thread::sleep(Duration::from_millis(2));
    }
    let el = t0.elapsed();
    total.sids = last.lock().unwrap().len();
    total.secs = secs(el);
    total.calls_per_sec = (total.calls as f64 / el.as_secs_f64()) as u64;
    Ok(total)
}

fn load_correct(l: &Load) -> (usize, usize, usize, usize) {
    (l.calls, l.wrong, l.errors, l.overlaps)
}

// ---------------------------------------------------------------------------
// Workaround for the missing acquire timeout: a counting gate in front of the
// Pool, sized to max_connections, with a timed wait.

struct Gate {
    used: Mutex<usize>,
    cv: Condvar,
    max: usize,
}

impl Gate {
    fn new(max: usize) -> Self {
        Gate { used: Mutex::new(0), cv: Condvar::new(), max }
    }

    fn enter(&self, wait: Duration) -> bool {
        let g = self.used.lock().unwrap();
        let (mut g, res) = self.cv.wait_timeout_while(g, wait, |u| *u >= self.max).unwrap();
        if res.timed_out() {
            return false;
        }
        *g += 1;
        true
    }

    fn leave(&self) {
        *self.used.lock().unwrap() -= 1;
        self.cv.notify_one();
    }
}

// ---------------------------------------------------------------------------
// DRCP observer: SYS AS SYSDBA on the CDB.

fn observer() -> R<Connection> {
    let config = oracledb::Config::default()
        .set_credentials("sys", &env_or("ORACLE_SYS_PASSWORD", "oracle"))
        .set_connect_string(&env_or("ORACLE_CDB_CONNECT_STRING", "oracle:1521/FREE"))?
        .set_auth_mode(oracledb::AUTH_MODE_SYSDBA);
    Ok(oracledb::connect(config)?)
}

fn drcp_status(o: &Connection) -> R<String> {
    Ok(o.query_row(
        "select status from dba_cpool_info where connection_pool = 'SYS_DEFAULT_CONNECTION_POOL'",
        &[],
    )?
    .get(0)?)
}

fn ensure_drcp(o: &Connection) -> R<String> {
    let before = drcp_status(o)?;
    if before != "ACTIVE" {
        o.execute("begin dbms_connection_pool.start_pool(); end;", &[])?;
    }
    Ok(format!("{before} -> {}", drcp_status(o)?))
}

#[derive(Debug, Clone, Copy)]
struct Cp {
    open: i64,
    busy: i64,
    requests: i64,
    hits: i64,
    misses: i64,
}

fn cpool(o: &Connection) -> R<Cp> {
    let r = o.query_row(
        "select num_open_servers, num_busy_servers, num_requests, num_hits, num_misses \
         from v$cpool_stats where pool_name = 'SYS_DEFAULT_CONNECTION_POOL'",
        &[],
    )?;
    Ok(Cp { open: r.get(0)?, busy: r.get(1)?, requests: r.get(2)?, hits: r.get(3)?, misses: r.get(4)? })
}

/// DRCP busy servers, read after a short pause so the broker has settled.
fn busy(o: &Connection) -> R<i64> {
    thread::sleep(Duration::from_millis(300));
    Ok(cpool(o)?.busy)
}

fn server_type(o: &Connection, sid: i64) -> R<String> {
    Ok(o.query_row("select server from v$session where sid = :1 and username = 'RAJSKA'", &[&sid])?.get(0)?)
}

fn conn_info(o: &Connection) -> R<Vec<(String, String)>> {
    let rows = o.query(
        "select cclass_name, connection_status from v$cpool_conn_info where username = 'RAJSKA' order by 1, 2",
        &[],
    )?;
    let mut v = Vec::new();
    for r in rows {
        let r = r?;
        v.push((r.get::<String>(0)?, r.get::<String>(1)?));
    }
    Ok(v)
}

/// Collapses repeated (cclass, status) rows into counts, shortening RSO uuids.
fn conn_info_summary(o: &Connection) -> R<Vec<(String, String, usize)>> {
    let mut v: Vec<(String, String, usize)> = Vec::new();
    for (cc, st) in conn_info(o)? {
        let cc = match cc.find("RSO:") {
            Some(i) => format!("{}RSO:<uuid>", &cc[..i]),
            None => cc,
        };
        match v.iter_mut().find(|(a, b, _)| *a == cc && *b == st) {
            Some(e) => e.2 += 1,
            None => v.push((cc, st, 1)),
        }
    }
    Ok(v)
}

fn main() {
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };
    let cs = dedicated_cs();

    println!("== Thread safety (compile time) ==");
    t.check("T1", "Pool is Send + Sync (can live in a static shared by ZTS threads)", "(true, true)",
        Ok((Probe::<Pool>(PhantomData).send(), Probe::<Pool>(PhantomData).sync())));
    t.observe("T2", "Connection is (Send, Sync)",
        Ok((Probe::<Connection>(PhantomData).send(), Probe::<Connection>(PhantomData).sync())));
    t.observe("T3", "PoolConfig defaults: (min, max, increment, ping_interval, ping_timeout)", (|| {
        let c = PoolConfig::default();
        Ok((c.min_connections(), c.max_connections(), c.connection_increment(), c.ping_interval(), c.ping_timeout()))
    })());

    println!("== Growth: min_connections and connection_increment ==");
    t.check("G1", "min=0 max=16 inc=1: 16 threads hold at once (acquired, open, busy, ok after release)",
        "(16, 16, 16, 16)",
        (|| {
            let p = pool(&cs, 0, 16, 1)?;
            let h = hold(&p, 16, Duration::from_secs(20))?;
            println!("~ G1  time until all 16 held: {:?} s, open after release: {}", h.secs_to_all, h.open_after_release);
            Ok(held4(&h))
        })());
    t.check("G2", "min=4 max=16 inc=1: open_count 1.5 s after create_pool", "4", (|| {
        let p = pool(&cs, 4, 16, 1)?;
        thread::sleep(Duration::from_millis(1500));
        Ok(p.open_count()?)
    })());
    {
        let cs = cs.clone();
        t.check("G3", "min=4 max=16 inc=1: 4 threads hold at once, 5 s deadline", "(4, 4, 4, 4)",
            watchdog(Duration::from_secs(90), move || {
                let p = pool(&cs, 4, 16, 1)?;
                thread::sleep(Duration::from_millis(500));
                Ok(held4(&hold(&p, 4, Duration::from_secs(5))?))
            }));
    }
    {
        let cs = cs.clone();
        t.check("G4", "min=0 max=16 inc=4: 16 threads hold at once, 5 s deadline", "(16, 16, 16, 16)",
            watchdog(Duration::from_secs(120), move || {
                let p = pool(&cs, 0, 16, 4)?;
                Ok(held4(&hold(&p, 16, Duration::from_secs(5))?))
            }));
    }
    {
        let cs = cs.clone();
        t.check("G5", "fixed size min=2 max=2 inc=0: (open after 1.5 s, then 2 threads hold at once)",
            "(2, (2, 2, 2, 2))",
            watchdog(Duration::from_secs(90), move || {
                let p = pool(&cs, 2, 2, 0)?;
                thread::sleep(Duration::from_millis(1500));
                let open = p.open_count()?;
                Ok((open, held4(&hold(&p, 2, Duration::from_secs(5))?)))
            }));
    }
    t.check("G6", "workaround for a warm Pool: min=1 inc=1, pre-acquire 8 then release (open after)", "8", (|| {
        let p = pool(&cs, 1, 8, 1)?;
        Ok(hold(&p, 8, Duration::from_secs(20))?.open_after_release)
    })());

    println!("== Load: one Pool, many threads, a fresh acquire per Call ==");
    println!("   (calls, wrong results, errors, overlapping holders of one Session)");
    let mut ok_l1 = false;
    {
        let cs = cs.clone();
        let r = watchdog(Duration::from_secs(320), move || load(&pool(&cs, 0, 8, 1)?, 16, 200));
        if let Ok(l) = &r {
            ok_l1 = l.counter_breaks == 0;
            println!("~ L1  {l:?}");
        }
        t.check("L1", "16 threads x 200 Calls on max=8", "(3200, 0, 0, 0)", r.map(|l| load_correct(&l)));
    }
    t.check("L1b", "package state follows its Session (bump_counter never skips or resets)", "true", Ok(ok_l1));
    {
        let cs = cs.clone();
        let r = watchdog(Duration::from_secs(320), move || load(&pool(&cs, 0, 16, 1)?, 64, 50));
        if let Ok(l) = &r {
            println!("~ L2  {l:?}");
        }
        t.check("L2", "64 threads x 50 Calls on max=16", "(3200, 0, 0, 0)", r.map(|l| load_correct(&l)));
    }
    {
        let cs = cs.clone();
        let r = watchdog(Duration::from_secs(320), move || load(&pool(&cs, 0, 16, 1)?, 16, 1000));
        if let Ok(l) = &r {
            println!("~ L3  {l:?}");
        }
        t.check("L3", "16 threads x 1000 Calls on max=16 (one Connection per thread)", "(16000, 0, 0, 0)",
            r.map(|l| load_correct(&l)));
    }

    println!("== Exhaustion ==");
    {
        let cs = cs.clone();
        t.check("X1", "max=1 held: (other acquire still waiting after 2 s, gets it within 1 s of the release)",
            "(true, true)",
            watchdog(Duration::from_secs(30), move || {
                let p = pool(&cs, 0, 1, 1)?;
                let c = p.acquire()?;
                let got = Arc::new(AtomicBool::new(false));
                let (p2, got2) = (p.clone(), got.clone());
                let w = thread::spawn(move || {
                    let c = p2.acquire();
                    got2.store(true, SeqCst);
                    c.map(|c| select_one(&c).is_ok()).unwrap_or(false)
                });
                thread::sleep(Duration::from_secs(2));
                let waiting = !got.load(SeqCst);
                drop(c);
                let t0 = Instant::now();
                while !got.load(SeqCst) && t0.elapsed() < Duration::from_secs(1) {
                    thread::sleep(Duration::from_millis(5));
                }
                let served = got.load(SeqCst) && w.join().unwrap_or(false);
                Ok((waiting, served))
            }));
    }
    t.observe("X2", "PoolConfig has no acquire wait timeout / NOWAIT (source: acquire() is an unbounded channel.recv())",
        Ok("Pool::acquire blocks until a Connection is returned or created"));
    {
        let cs = cs.clone();
        t.check("X3", "workaround: a timed gate sized to max in front of acquire, 500 ms wait while max=1 is held (entered, waited 0.45-1 s)",
            "(false, true)",
            watchdog(Duration::from_secs(30), move || {
                let p = pool(&cs, 0, 1, 1)?;
                let gate = Gate::new(1);
                assert!(gate.enter(Duration::ZERO));
                let c = p.acquire()?;
                let t0 = Instant::now();
                let entered = gate.enter(Duration::from_millis(500));
                let el = t0.elapsed();
                drop(c);
                gate.leave();
                Ok((entered, el >= Duration::from_millis(450) && el < Duration::from_secs(1)))
            }));
    }
    t.check("X4", "unreachable database: 4 threads acquire at once (errors, open, busy)", "(4, 0, 0)",
        watchdog(Duration::from_secs(60), || {
            let p = pool("127.0.0.1:1/nowhere", 0, 4, 1)?;
            let hs: Vec<_> = (0..4).map(|_| { let p = p.clone(); thread::spawn(move || p.acquire().is_err()) }).collect();
            let errs = hs.into_iter().map(|h| h.join().unwrap_or(false)).filter(|e| *e).count();
            Ok((errs, p.open_count()?, p.busy_count()?))
        }));

    println!("== set_call_timeout ==");
    {
        let sleep3 = "begin fx_pool.sleep(3); end;";
        // CPU-bound: the server is busy computing, not sleeping in a wait.
        let cpu = "select count(*) from all_objects a, all_objects b";
        for (id, what, sql, ms) in [
            ("C1", "500 ms timeout on a 3 s sleep", sleep3, 500u64),
            ("C1c", "2 s timeout on a 3 s sleep", sleep3, 2000),
            ("C1d", "500 ms timeout on a CPU-bound query", cpu, 500),
        ] {
            let cs = cs.clone();
            let r = watchdog(Duration::from_secs(60), move || {
                let p = pool(&cs, 0, 1, 1)?;
                let c = p.acquire()?;
                let sid0 = sid(&c)?;
                c.set_call_timeout(Some(Duration::from_millis(ms)))?;
                let t0 = Instant::now();
                let r = c.execute(sql, &[]);
                let el = t0.elapsed();
                let err = r.as_ref().err().map(|e| (format!("{:?}", e.kind()), e.db_error_code(), one_line(&e.to_string())));
                let after = select_one(&c).map(|v| (v, sid(&c).ok() == Some(sid0))).map_err(|e| one_line(&e.to_string()));
                drop(c);
                let c = p.acquire()?;
                let back = (c.call_timeout()?, select_one(&c)?, p.open_count()?, sid(&c)? == sid0);
                Ok((r.is_err(), el, err, after, back))
            });
            match r {
                Ok((is_err, el, err, after, back)) => {
                    let limit = Duration::from_millis(ms) + Duration::from_millis(1000);
                    t.check(id, &format!("{what}: (error, returned within timeout + 1 s)"), "(true, true)",
                        Ok((is_err, el < limit)));
                    t.observe(&format!("{id}b"), "the error (kind, ORA code, message), seconds", Ok((err, secs(el))));
                    t.check(&format!("{id}e"), "same Connection after the timeout: Ok((select 1, same Session))",
                        "Ok((1, true))", Ok(after));
                    t.check(&format!("{id}f"), "returned to the Pool and acquired again: (call_timeout, select 1, open_count)",
                        "(None, 1, 1)", Ok((back.0, back.1, back.2)));
                    t.observe(&format!("{id}g"), "same Session as before the timeout (false = the Pool replaced a dead one)", Ok(back.3));
                }
                Err(e) => t.check::<()>(id, what, "-", Err(e)),
            }
        }
    }
    {
        let cs = cs.clone();
        t.check("C4", "8 threads, 400 ms timeout, odd ones sleep 2 s, even 0.1 s: (right outcome, usable after)",
            "(8, 8)",
            watchdog(Duration::from_secs(60), move || {
                let p = pool(&cs, 0, 8, 1)?;
                let hs: Vec<_> = (0..8)
                    .map(|i| {
                        let p = p.clone();
                        thread::spawn(move || -> (bool, bool) {
                            let Ok(c) = p.acquire() else { return (false, false) };
                            let _ = c.set_call_timeout(Some(Duration::from_millis(400)));
                            let s = if i % 2 == 1 { 2.0 } else { 0.1 };
                            let timed_out = c.execute("begin fx_pool.sleep(:1); end;", &[&s]).is_err();
                            (timed_out == (i % 2 == 1), select_one(&c).is_ok())
                        })
                    })
                    .collect();
                let rs: Vec<(bool, bool)> = hs.into_iter().map(|h| h.join().unwrap_or((false, false))).collect();
                Ok((rs.iter().filter(|r| r.0).count(), rs.iter().filter(|r| r.1).count()))
            }));
    }
    t.observe("C5", "is the timeout per round trip? 500 ms timeout, 4 rows x 0.3 s fetched one row per round trip: (rows, seconds)",
        (|| {
            let c = connect(&cs)?;
            c.set_call_timeout(Some(Duration::from_millis(500)))?;
            let t0 = Instant::now();
            let sql = "with function slow(n number) return number is begin dbms_session.sleep(0.3); return n; end; \
                       select slow(level) from dual connect by level <= 4";
            let rows = c.statement(sql)?.fetch_array_size(1).prefetch_rows(1).build()?.query(&[])?;
            let mut n = 0;
            for r in rows {
                r?;
                n += 1;
            }
            Ok((n, secs(t0.elapsed())))
        })());

    println!("== DRCP ==");
    let o = match observer() {
        Ok(o) => o,
        Err(e) => {
            t.check::<()>("D0", "SYSDBA observer on the CDB", "-", Err(e));
            return summary(&t);
        }
    };
    t.observe("D0", "default DRCP pool status (started when INACTIVE)", ensure_drcp(&o));
    t.observe("D0b", "v$cpool_stats before the DRCP cases", cpool(&o));
    for (id, label, cs_d) in [
        ("D1", "Easy Connect host:port/service:pooled", pooled_cs()),
        ("D2", "descriptor with (SERVER=POOLED)", pooled_descriptor("")),
    ] {
        let r = (|| {
            let b0 = busy(&o)?;
            let c = connect(&cs_d)?;
            let row = c.query_row("select fx_scalar.add_numbers(2, 3), fx_pool.session_id from dual", &[])?;
            let (sum, s): (i64, i64) = (row.get(0)?, row.get(1)?);
            let st = server_type(&o, s)?;
            let b1 = busy(&o)?;
            let again = select_one(&c)?;
            drop(c);
            let b2 = busy(&o)?;
            Ok(((sum, st, again), (b0, b1, b2)))
        })();
        match r {
            Ok((call, b)) => {
                t.check(id, &format!("standalone over {label}: (add_numbers(2,3), v$session.server, select 1 after idle)"),
                    "(5, \"POOLED\", 1)", Ok(call));
                t.observe(&format!("{id}b"), "DRCP busy servers: (before connect, connected and idle, after close)", Ok(b));
            }
            Err(e) => t.check::<()>(id, &format!("standalone over {label}"), "-", Err(e)),
        }
    }
    {
        let r = (|| {
            let s0 = cpool(&o)?;
            let p = pool(&pooled_cs(), 0, 4, 1)?;
            let l = load(&p, 16, 100)?;
            let idle_busy = busy(&o)?;
            let info = conn_info_summary(&o)?;
            let s1 = cpool(&o)?;
            let open = p.open_count()?;
            drop(p);
            let after_drop = busy(&o)?;
            Ok((l, s0, s1, idle_busy, open, info, after_drop))
        })();
        match r {
            Ok((l, s0, s1, idle_busy, open, info, after_drop)) => {
                println!("~ D3  {l:?}");
                t.check("D3", "Pool over DRCP: 16 threads x 100 Calls on max=4 (calls, wrong, errors, overlaps)",
                    "(1600, 0, 0, 0)", Ok(load_correct(&l)));
                t.observe("D3b", "package state across acquires under DRCP: (Sessions seen, counter breaks)",
                    Ok((l.sids, l.counter_breaks)));
                t.observe("D3c", "v$cpool_stats delta over the load: (requests, hits, misses)",
                    Ok((s1.requests - s0.requests, s1.hits - s0.hits, s1.misses - s0.misses)));
                t.observe("D3d", "client Pool idle (all returned): (open_count, DRCP busy servers)", Ok((open, idle_busy)));
                t.observe("D3e", "v$cpool_conn_info while idle: (cclass, status, count)", Ok(info));
                t.observe("D3f", "DRCP busy servers after the client Pool is dropped", Ok(after_drop));
            }
            Err(e) => t.check::<()>("D3", "Pool over DRCP", "-", Err(e)),
        }
    }
    {
        let r = (|| {
            let b0 = busy(&o)?;
            let c = connect(&pooled_descriptor("(POOL_BOUNDARY=TRANSACTION)"))?;
            let s1 = sid(&c)?;
            let b1 = busy(&o)?;
            let s2 = sid(&c)?;
            let sum: i64 = c.query_row("select fx_scalar.add_numbers(2, 3) from dual", &[])?.get(0)?;
            drop(c);
            let b2 = busy(&o)?;
            Ok((sum, (b0, b1, b2), s1 == s2))
        })();
        t.observe("D4", "implicit connection pooling, (POOL_BOUNDARY=TRANSACTION): (Call result, busy before/idle/after close, same Session on the next Call)", r);
    }
    {
        t.check("D5", "call timeout over DRCP, CPU-bound query: (timed out, usable after)", "(true, 1)",
            watchdog(Duration::from_secs(30), move || {
                let c = connect(&pooled_cs())?;
                c.set_call_timeout(Some(Duration::from_millis(400)))?;
                let to = c.execute("select count(*) from all_objects a, all_objects b", &[]).is_err();
                Ok((to, select_one(&c)?))
            }));
    }

    summary(&t);
}

fn summary(t: &Tally) {
    println!("\n{} pass, {} fail, {} err, {} observed", t.pass, t.fail, t.err, t.obs);
    std::process::exit(0);
}
