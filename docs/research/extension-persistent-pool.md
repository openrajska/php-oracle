# How can an extension keep a Pool alive across requests under FPM and share it across threads under ZTS?

Research for issue #19 (child of map #16). Researched 2026-11-20 against
php-src `PHP-8.4` ([`8748e22`][php-src]), pecl-database-oci8
([`38cb9f2`][oci8]), ext-php-rs 0.16.5 ([`ext-php-rs-v0.16.5`][ephprs]),
rust-oracledb `v26.0.0-beta.6` (the spike's version; `main` at `b1adfff`
behaves the same for everything below) and FrankenPHP `main`
([`31e8811`][fphp]).

Terms follow the [Core glossary](../../packages/php-oracle/GLOSSARY.md): a
**Pool** hands out **Connections**, each bound to one **Session**.
Claims marked **(verified)** are read from the cited source. Claims marked
**(inference)** are my reading and have not been tested.

## Answer

**Keep one Pool per process in a Rust `static` registry, create it lazily on
the first Connection request, check the PID on every lookup, and close it in
MSHUTDOWN.** The same code path serves every target:

| Runtime | Where the Pool lives | Lifetime | Why |
|---|---|---|---|
| PHP-FPM (NTS) | Rust `static` in each worker process, created on the first request that asks for it | Until the worker exits (`pm.max_requests`, idle kill, reload) | MINIT runs once in the master, **before** `fork()`, so the Pool must not exist yet at MINIT. Each worker serves one request at a time, so the Pool behaves like an oci8 persistent connection. |
| FrankenPHP worker mode (ZTS) | One Rust `static` per process, shared by every PHP thread | Until FrankenPHP reboots its threads (`workers/restart`, file watcher, `opcache_reset()`) or stops | Module globals and `EG(persistent_list)` are **per thread** under ZTS. Only a Rust `static` is process-wide. The Pool is `Send + Sync` and the spike shared one across 64 threads. |
| Laravel Octane | Same as the server underneath it (FrankenPHP: as above; RoadRunner and Swoole: one Pool per worker process) | Per Octane worker | Octane boots the app once per worker; the lazy, PID-checked registry needs nothing Octane-specific. |

The hazards, in order of severity:

1. **`fork()` after a Pool exists.** The crate's Pool runs a background
   manager thread and owns sockets. A child gets neither the thread nor
   private sockets. FPM forks after MINIT, so a Pool created in MINIT (or by
   `opcache.preload` code in the master) would be shared by every worker.
   Rust-oracledb says it plainly: pools "must be created, used, and closed
   within the same process" ([rust-oracledb guide][rodb-process]).
2. **The module is unloaded after MSHUTDOWN, and under FrankenPHP the process
   keeps running.** PHP `dlclose()`s shared extensions after MSHUTDOWN, and
   FrankenPHP then runs MINIT again in the same process. A Pool left in a
   `static` keeps its manager thread running code from the unloaded library.
   The Pool must be closed (its thread joined) in MSHUTDOWN, and closing fails
   while any Connection is still out.
3. **No per-request RSHUTDOWN in FrankenPHP worker mode.** End-of-request
   cleanup (roll back, release the Connection: C14) cannot hang on RSHUTDOWN.
   It has to hang on the PHP object's lifetime, or on the
   `sapi_activate_function` hook that ext-php-rs 0.16.5 provides.

ext-php-rs 0.16.5 gives MINIT, MSHUTDOWN, RINIT, RSHUTDOWN, post-deactivate
and a per-request SAPI hook, plus per-thread module globals. It wraps neither
persistent resources nor `EG(persistent_list)`, and nothing needs them:
a Rust `static` holding a `Mutex` is sound, and it is the only process-wide
option under ZTS.

## 1. How oci8 keeps Sessions across requests (FPM / NTS)

**Storage: `EG(persistent_list)`.** (verified) `oci_pconnect()` hashes the
credentials and connect string into a key, looks it up in
`EG(persistent_list)`, and on a miss registers the new connection with
`zend_register_persistent_resource_ex()` ([oci8.c L921–L928][oci8-lookup],
[L1164–L1174][oci8-register]). The OCI session pool behind it is cached the
same way ([L2012–L2028][oci8-spool]). `EG(persistent_list)` is a hash table
allocated with `persistent = 1` ([zend_list.c L209–L212][zl-plist],
[L351–L362][zl-register]); it survives `php_request_shutdown()` and is destroyed
only in `zend_shutdown()` ([zend.c L1161–L1169][zend-shutdown]).

**Per-request hooks.** (verified)

- RINIT only resets counters ([oci8.c L296–L303][oci8-hooks]).
- When the PHP resource for a persistent connection goes out of scope, its
  non-persistent destructor releases the connection: rolls back the open
  transaction, frees descriptors, and stamps `idle_expiry` and the next ping
  time ([oci8.c L414–L470][oci8-np-dtor],
  [`php_oci_connection_release` L1390–L1440][oci8-release]).
- RSHUTDOWN walks `EG(persistent_list)` with `php_oci_persistent_helper`, which
  removes connections not used in this request whose `idle_expiry` has passed
  ([oci8.c L314–L326][oci8-hooks], [L1795–L1830][oci8-helper]).
- MSHUTDOWN only unregisters INI entries; GSHUTDOWN frees the OCI environment
  handles ([oci8.c L254–L275][oci8-ginit], [L305–L312][oci8-hooks]). The
  connections themselves are closed by the persistent-list destructor during
  `zend_shutdown()`.

**INI knobs.** (verified)

- `oci8.max_persistent` is "the maximum number of persistent OCI8 connections
  per PHP process". When it is reached, oci8 first tries to evict idle ones,
  then falls back to a non-persistent connection with a notice
  ([manual][oci8-ini], [oci8.c L1061–L1076][oci8-max]).
- `oci8.persistent_timeout` is not timer-based. The manual says idle
  connections "can only be closed when there is some activity … in the PHP
  process", and "each [process] must individually be activated"
  ([manual][oci8-ini]). The code matches: expiry runs only from RSHUTDOWN or
  when `max_persistent` is hit.
- `oci8.ping_interval` pings a reused connection at most every N seconds
  during `oci_pconnect()` ([manual][oci8-ini], [oci8.c L975–L1000][oci8-lookup]).

**Under ZTS these are per thread.** (verified) `executor_globals_ctor()` calls
`zend_init_rsrc_plist()` for every thread's executor globals
([zend.c L801–L805][zend-eg-ctor]), and `zend_shutdown()` destroys each thread's
list separately ([zend.c L855–L862][zend-eg-plist-dtor],
[L1165–L1167][zend-shutdown]). oci8's counters live in module globals, which are
also per thread. So oci8 "persistent" connections under ZTS are per thread, not
shared. FrankenPHP has had to work around this list on PHP 8.4, where a thread
that exits before the process leaked its persistent resources
([frankenphp.c L1884–L1907][fphp-plist]).

**Takeaway for `rajska_oracle`.** The oci8 model (a persistent handle per
process, released at the end of each use, expired lazily) maps onto "one Pool
per process, Connection released when the PHP object dies". `EG(persistent_list)`
itself is not worth copying: under ZTS it cannot share a Pool between threads,
and ext-php-rs has no wrapper for it (§3).

## 2. The PHP lifecycle around a Pool

### PHP-FPM (NTS)

(verified)

- The master runs `cgi_sapi_module.startup()` (that is `php_module_startup()`,
  so every extension's MINIT) **before** `fpm_run()` forks the workers
  ([fpm_main.c L1761][fpm-startup], [L1845][fpm-run-call];
  [fpm.c L91–L125][fpm-run]; [fpm_children.c L462][fpm-fork]).
- Each worker loops: accept, `php_request_startup()` (RINIT), run,
  `php_request_shutdown()` (RSHUTDOWN), until `pm.max_requests` is reached; then
  it calls `php_module_shutdown()` (MSHUTDOWN) and exits
  ([fpm_main.c L1860–L1994][fpm-loop]).
- In a worker, SIGQUIT is a soft quit (finish the request, leave the loop, run
  MSHUTDOWN). SIGTERM and SIGINT keep their default action, so the process dies
  without MSHUTDOWN ([fpm_signals.c L225–L247][fpm-signals]). The master sends
  SIGQUIT to idle workers it scales down, and SIGKILL if one was already asked
  ([fpm_process_ctl.c L316–L324][fpm-idle-kill]). It sends SIGTERM when
  `request_terminate_timeout` fires ([fpm_request.c L274][fpm-terminate]) and
  escalates SIGQUIT → SIGTERM → SIGKILL on shutdown
  ([fpm_process_ctl.c L175–L200][fpm-escalate]).

Consequences (inference):

- MINIT runs in the master, whose memory every worker inherits. Anything
  created there is shared by all workers. A Pool must therefore be created
  lazily, from the first request that asks for a Connection.
- A worker serves one request at a time, so it never needs more than one
  Connection unless a single request opens several. Total Sessions on the
  database = `pm.max_children` × the Pool's `max`. This is the per-process
  arithmetic that node-oracledb shows for 10 processes × `poolMax` 50 = 500
  server processes ([node-oracledb guide][node-drcp]).
- MSHUTDOWN is the clean close point on `pm.max_requests`, idle scale-down and
  graceful reload. On SIGTERM or SIGKILL the process dies with its sockets and
  the database cleans the Sessions up on its own; nothing in the extension can
  run.
- Nothing runs between requests, so an idle worker keeps its Sessions until it
  exits. This is the same "paradox" the oci8 manual describes. With
  `pm = ondemand` or `dynamic`, `pm.process_idle_timeout` kills idle workers
  with SIGQUIT, which runs MSHUTDOWN and closes the Pool.
- `opcache.preload` runs PHP code in the master at startup
  ([manual][opcache-preload]). If that code opens a Connection, the Pool would
  be created before the fork. The PID check below covers this.

### FrankenPHP (ZTS)

(verified)

- One process, many PHP threads. Each thread allocates its own TSRM storage
  with `ts_resource(0)` and frees it with `ts_free_thread()` when it stops
  ([frankenphp.c L1928–L1929][fphp-thread-start],
  [L2060–L2071][fphp-thread-end]). Threads are started and stopped at runtime
  (scaling, `max_requests`, restarts).
- In worker mode, the requests served by `frankenphp_handle_request()` do
  **not** run the extensions' RINIT/RSHUTDOWN. Only `filter` (and `session` in
  some builds) are re-run ([frankenphp.c L87–L95][fphp-reload-list],
  [L637–L651][fphp-worker-shutdown], [L783–L793][fphp-worker-startup]).
  ext-php-rs documents the same: "In worker mode, PHP does not call the request
  startup function of an extension for each request. This is also true for a
  FrankenPHP worker" ([worker_mode.md][ephprs-worker]).
- The FrankenPHP docs say everything except the request's own state persists
  between the requests a thread handles, "as in a long-running CLI script"
  ([worker.md, State persistence][fphp-state]), and recommend restarting a
  worker after N requests to contain leaks ([worker.md][fphp-maxreq]).
- `RestartWorkers()` (admin `POST /frankenphp/workers/restart`, the file
  watcher, `opcache_reset()`) reboots **all** PHP threads and the main thread
  ([worker.go L236–L266][fphp-restart], [phpmainthread.go L138–L220][fphp-reboot]).
  The main thread runs the SAPI shutdown (MSHUTDOWN) and `tsrm_shutdown()`, and
  then starts again with a fresh MINIT, in the same OS process
  ([frankenphp.c L2089–L2194][fphp-main]). ext-php-rs has a test for exactly
  this restart ([module_restart.rs][ephprs-restart-test]) and warns that class
  entries do not survive it ([minit_memory.md, Module restarts][ephprs-minit]).
- After MSHUTDOWN, PHP calls `DL_UNLOAD` (`dlclose()`) on every shared
  extension unless `ZEND_DONT_UNLOAD_MODULES` is set
  ([zend_API.c L3400–L3409][zapi-unload]).

Consequences (inference):

- One Pool per process, shared by every thread, sized to roughly the number of
  PHP threads that use the database. The spike measured this design at 8
  threads under FrankenPHP and found it free compared with one Connection per
  thread (FINDINGS, Performance).
- Thread start and stop need no Pool work. A stopping thread destroys its PHP
  objects, so its Connections go back to the Pool before `ts_free_thread()`.
  Per-thread module globals see GINIT/GSHUTDOWN per thread
  ([module_globals.md][ephprs-globals]) and must not own the Pool.
- A reboot runs MSHUTDOWN while the process lives on. Rust statics are not
  dropped, so the Pool's manager thread would keep running after the library
  is `dlclose()`d. Whether glibc really unmaps a Rust `cdylib` at that point
  is not verified; the extension should not depend on it either way. Closing
  the Pool in MSHUTDOWN joins the thread and removes the question. The next
  request after the reboot creates a fresh Pool.
- FrankenPHP does not fork, so the fork hazard only reaches it through
  `pcntl_fork()` from PHP code.

### Laravel Octane

(verified) Octane runs on FrankenPHP, Swoole / Open Swoole or RoadRunner,
"boots your application once, keeps it in memory", runs service providers'
`register` and `boot` once per worker, and restarts a worker after 500 requests
by default ([Octane docs][octane]).

(inference) Octane adds no lifecycle of its own below PHP. On FrankenPHP it is
the ZTS case above. RoadRunner and Swoole run several PHP worker processes;
how each starts its workers (exec or fork, and whether the fork happens after
user code has run) is not verified here. The lazy, PID-checked registry is
correct in either case.

## 3. What ext-php-rs 0.16.5 offers

(verified)

| Need | ext-php-rs 0.16.5 | Source |
|---|---|---|
| MINIT / MSHUTDOWN | `ModuleBuilder::startup_function`, `shutdown_function`; plain `extern "C" fn(i32, i32) -> i32`, copied into `module_startup_func` / `module_shutdown_func` | [module.rs L148–L160, L740, L818–L821][ephprs-hooks] |
| RINIT / RSHUTDOWN | `request_startup_function`, `request_shutdown_function` | [module.rs L168–L180][ephprs-hooks] |
| After request shutdown | `post_deactivate_function`, documented for "persistent resources that last beyond a single request" | [module.rs L182–L195][ephprs-hooks] |
| Per request in worker mode | `sapi_activate_function`, called "at the start of each request. This includes each request of a FrankenPHP worker". PHP code cannot run in it. | [worker_mode.md][ephprs-worker] |
| Module globals | `ModuleGlobals<T>` + `ModuleGlobal` (`ginit` / `gshutdown`): one copy per thread under ZTS, one static under NTS; one struct per module | [module_globals.md][ephprs-globals], [module.rs L244–L287][ephprs-globals-src], [module_globals.rs L29–L40][ephprs-globals-trait] |
| Persistent resources / `EG(persistent_list)` | No wrapper. Searching `src/` for `zend_register_persistent_resource`, `zend_register_list_destructors_ex` or `persistent_list` finds nothing. Only raw FFI remains. | [ext-php-rs src][ephprs] |
| Persistent allocation | Only for zvals and strings (`persistent: bool` on `ZendStr::new`, `set_zval`); no general `pemalloc` API. | [string/mod.rs L83][ephprs-zstr] |
| Module restarts | Supported and tested; class entries must not be cached in a `static`, `OnceLock` or thread-local across a restart. | [minit_memory.md][ephprs-minit] |

**Are Rust statics sound?** (inference, backed by the cited facts)

- Yes for ownership and threads. A `static` in the `cdylib` is one per process
  under both NTS and ZTS. `Pool` is `Send + Sync` (it holds an
  `Arc<Mutex<PoolContents>>` and a `JoinHandle`, [pool/mod.rs L48–L82][rodb-pool]).
  The spike already used `static POOL: OnceLock<Pool>` and served 22,400 Calls
  from 16–64 threads.
- Not as a bare `OnceLock<Pool>`. A `OnceLock` can neither be emptied nor
  dropped, so the Pool could not be closed at MSHUTDOWN or replaced after a
  fork or a reboot. Use `static POOLS: Mutex<HashMap<PoolKey, PoolSlot>>` (or
  `Mutex<Option<…>>` for a single Pool), where `PoolSlot` keeps the
  `Arc<Pool>` and the PID that created it.
- The registry holds no PHP values (no zvals, no class entries). PHP-facing
  objects stay per thread and per request.

## 4. The Pool itself: threads, fork and close

(verified)

- `create_pool()` spawns a background thread (`PoolManager::run`) that grows
  the Pool, pings Connections and closes dropped ones
  ([pool/mod.rs L66–L82][rodb-pool], [manager.rs L45–L103][rodb-manager]).
- `Pool::close()` fails with `pool_has_busy_connections` while any Connection
  is acquired; otherwise it tells the manager to stop and joins its thread.
  `Drop` calls `close()` and ignores the error
  ([pool/mod.rs L104–L141][rodb-pool], [contents.rs L208–L219][rodb-close]).
- `PoolConfig` has `min` / `max` / `increment`, `ping_interval` and
  `ping_timeout`, but no idle timeout, no maximum Session lifetime and no
  acquire timeout ([pool_config.rs][rodb-poolconfig]; acquire timeout is C13).
- Docs: "Rust-oracledb driver connection pools must be created, used, and
  closed within the same process. Sharing pools or connections across
  processes has unpredictable behavior. Using connection pools in
  multi-threaded architectures is supported." ([guide][rodb-process]).
  python-oracledb carries the same note, and adds that "multi-process
  architectures that cannot be converted to threading may get some benefit
  from DRCP" ([python-oracledb guide][py-process]).
- POSIX: the child of `fork()` "shall be created with a single thread", a
  replica of the calling one, with the parent's address space "possibly
  including the states of mutexes", and its file descriptors "refer to the
  same open file description" as the parent's ([POSIX fork][posix-fork]).

Consequences (inference):

- A Pool inherited across `fork()` has no manager thread in the child. A
  request that needs the Pool to grow or ping waits for a thread that does not
  exist. Its Sessions' sockets are shared with the parent and every sibling,
  so two processes would interleave TTC packets on one Session. Its `Mutex`
  may have been copied in a locked state.
- The child must not close or drop an inherited Pool: closing would join a
  thread that does not exist in the child, and dropping the free Connections
  would write on sockets the parent still uses. The child should leak it
  (`std::mem::forget`) and create its own.
- At MSHUTDOWN every Connection has already been released (requests and
  threads end first), so `close()` should succeed. If it fails, logging it and
  leaking is safer than blocking shutdown.

## 5. How the other Oracle drivers frame pool lifetime in pre-fork servers

(verified)

- python-oracledb and rust-oracledb: one Pool per process, created, used and
  closed in that process; threads may share it ([py][py-process], [rust][rodb-process]).
  "Pools are created with `oracledb.create_pool()` at application initialization
  time" ([py guide, introduction][py-guide]).
- python-oracledb recommends **fixed-size** Pools (`min` = `max`) to avoid
  connection storms, and small Pools ([py guide, Connection Pool Sizing][py-sizing]).
  Idle shrink (`timeout`) and `max_lifetime_session` are pool features there
  ([py guide][py-sizing]); rust-oracledb has neither yet.
- node-oracledb: "Each node-oracledb process can use one or more connection
  pools", and DRCP is the answer when processes × `poolMax` exceeds what the
  database host can hold ([node guide][node-pools], [DRCP][node-drcp]).
- gunicorn, the reference pre-fork server, loads the app in each worker unless
  `preload_app` is set, and gives `post_fork` / `post_worker_init` hooks for
  per-worker setup ([gunicorn settings][gunicorn-preload], [hooks][gunicorn-hooks]).
  The usual pattern follows from the Oracle note: create the Pool after the
  fork. PHP-FPM has no `post_fork` hook for extensions, so lazy creation on the
  first request is the equivalent.

DRCP is the cross-process answer the Oracle docs give, but C15 (the crate never
releases the DRCP server) blocks its benefit under FPM today
(FINDINGS, Pool, DRCP and timeouts).

## 6. Recommended shape (input for the Connection and Pool lifecycle ticket)

(inference, built on §1–§5)

1. **Registry.** `static POOLS: Mutex<HashMap<PoolKey, PoolSlot>>` in the
   Extension. `PoolKey` hashes the connect string, user, a hash of the
   password and the Pool options, as oci8's hash key does. `PoolSlot` holds
   `Arc<Pool>` and `owner_pid`.
2. **Create lazily** on the first request that asks for a Connection, never in
   MINIT. MINIT only registers classes and INI entries.
3. **PID check on every lookup.** If `owner_pid != getpid()`, `mem::forget`
   the inherited Pool and create a new one. This covers `opcache.preload`,
   `pcntl_fork()` and any server that forks after user code has run.
4. **Release by object lifetime.** The PHP Connection object returns its
   Connection to the Pool in its destructor, after ending any open
   transaction itself (C14). Do not rely on RSHUTDOWN: FrankenPHP worker
   requests skip it. Whether Core also needs a per-request "did a Connection
   leak?" check (via `sapi_activate_function`) is for the lifecycle ticket to
   decide.
5. **Close in MSHUTDOWN.** Take every slot owned by this PID out of the
   registry and `close()` it, which joins the manager thread before PHP
   `dlclose()`s the extension. Leak slots owned by another PID.
6. **Sizing is per process.** FPM: `max` near 1 (a worker serves one request at
   a time); total Sessions = `pm.max_children` × `max`. FrankenPHP: `max` near
   the number of PHP threads. Keep `min` ≤ 1 and `increment` = 1 until C12 is
   fixed, and put the C13 acquire-timeout gate in front of `acquire()`.
7. **Idle Sessions.** The crate has no idle timeout and PHP has no timer, so an
   idle FPM worker keeps its Sessions until FPM retires it (as with oci8). An
   extension-level idle reaper is possible (the Pool already runs a thread) but
   is new work. The lifecycle ticket should decide whether v1 needs it or
   documents `pm.process_idle_timeout` / `pm.max_requests` instead.

**Candidate conformance cases.** These are Extension behaviours, so they belong
with the Extension rather than with the Backend-neutral Driver contract:

- Two requests in one FPM worker reuse the same Session (same `SID`).
- Under FrankenPHP worker mode with N threads, the Pool's open count never
  exceeds `max` and no Session is held by two threads (the spike's probe).
- After `pcntl_fork()`, the child gets a different Session than the parent and
  the parent's Connection still works.
- After `POST /frankenphp/workers/restart`, the next request gets a working
  Connection and the old Sessions are gone from `V$SESSION`.
- A Connection left with an open transaction at the end of a worker request is
  rolled back before another request can acquire its Session.

[php-src]: https://github.com/php/php-src/tree/8748e2265d12d1bbed8e153846adfbe823580831
[zl-plist]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_list.c#L209-L212
[zl-register]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_list.c#L351-L362
[zend-eg-ctor]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend.c#L801-L805
[zend-eg-plist-dtor]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend.c#L855-L862
[zend-shutdown]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend.c#L1161-L1169
[zapi-unload]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_API.c#L3400-L3409
[fpm-startup]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_main.c#L1761
[fpm-run-call]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_main.c#L1845
[fpm-loop]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_main.c#L1860-L1994
[fpm-run]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm.c#L91-L125
[fpm-fork]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_children.c#L462
[fpm-signals]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_signals.c#L225-L247
[fpm-idle-kill]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_process_ctl.c#L316-L324
[fpm-escalate]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_process_ctl.c#L175-L200
[fpm-terminate]: https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/sapi/fpm/fpm/fpm_request.c#L274
[oci8]: https://github.com/php/pecl-database-oci8/tree/38cb9f2bb602e65dd4acc418f3ad37f62e057797
[oci8-ginit]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L254-L275
[oci8-hooks]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L296-L326
[oci8-np-dtor]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L414-L470
[oci8-lookup]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L921-L1000
[oci8-max]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L1061-L1076
[oci8-register]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L1164-L1174
[oci8-release]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L1390-L1440
[oci8-helper]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L1795-L1830
[oci8-spool]: https://github.com/php/pecl-database-oci8/blob/38cb9f2bb602e65dd4acc418f3ad37f62e057797/oci8.c#L2012-L2028
[oci8-ini]: https://www.php.net/manual/en/oci8.configuration.php
[opcache-preload]: https://www.php.net/manual/en/opcache.preloading.php
[ephprs]: https://github.com/extphprs/ext-php-rs/tree/ext-php-rs-v0.16.5
[ephprs-hooks]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/builders/module.rs#L148-L195
[ephprs-globals-src]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/builders/module.rs#L244-L287
[ephprs-globals-trait]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/zend/module_globals.rs#L29-L40
[ephprs-globals]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/guide/src/advanced/module_globals.md
[ephprs-worker]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/guide/src/advanced/worker_mode.md
[ephprs-minit]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/guide/src/advanced/minit_memory.md#module-restarts
[ephprs-restart-test]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/tests/module_restart.rs#L1-L3
[ephprs-zstr]: https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/types/string/mod.rs#L83
[rodb-pool]: https://github.com/oracle/rust-oracledb/blob/v26.0.0-beta.6/src/pool/mod.rs#L48-L141
[rodb-manager]: https://github.com/oracle/rust-oracledb/blob/v26.0.0-beta.6/src/pool/manager.rs#L45-L103
[rodb-close]: https://github.com/oracle/rust-oracledb/blob/v26.0.0-beta.6/src/pool/contents.rs#L208-L219
[rodb-poolconfig]: https://github.com/oracle/rust-oracledb/blob/v26.0.0-beta.6/src/config/pool_config.rs
[rodb-process]: https://github.com/oracle/rust-oracledb/blob/v26.0.0-beta.6/doc/connection_handling.md
[fphp]: https://github.com/php/frankenphp/tree/31e881195c8f16daa1069c97febc1c7acff199bf
[fphp-reload-list]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L87-L95
[fphp-worker-shutdown]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L637-L651
[fphp-worker-startup]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L783-L793
[fphp-plist]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L1884-L1907
[fphp-thread-start]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L1926-L1930
[fphp-thread-end]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L2060-L2071
[fphp-main]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/frankenphp.c#L2089-L2194
[fphp-restart]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/worker.go#L236-L266
[fphp-reboot]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/phpmainthread.go#L138-L220
[fphp-state]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/docs/worker.md#state-persistence
[fphp-maxreq]: https://github.com/php/frankenphp/blob/31e881195c8f16daa1069c97febc1c7acff199bf/docs/worker.md#restart-the-worker-after-a-certain-number-of-requests
[octane]: https://laravel.com/docs/12.x/octane
[py-guide]: https://python-oracledb.readthedocs.io/en/latest/user_guide/connection_handling.html
[py-process]: https://python-oracledb.readthedocs.io/en/latest/user_guide/connection_handling.html#driverconnpool
[py-sizing]: https://python-oracledb.readthedocs.io/en/latest/user_guide/connection_handling.html#connpoolsize
[node-pools]: https://node-oracledb.readthedocs.io/en/latest/user_guide/connection_handling.html#connpooling
[node-drcp]: https://node-oracledb.readthedocs.io/en/latest/user_guide/connection_handling.html#drcp
[gunicorn-preload]: https://gunicorn.org/reference/settings/#preload_app
[gunicorn-hooks]: https://gunicorn.org/reference/settings/#post_fork
[posix-fork]: https://pubs.opengroup.org/onlinepubs/9799919799/functions/fork.html
