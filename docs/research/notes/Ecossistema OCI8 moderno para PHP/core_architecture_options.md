# Core Architecture Options for a New High-Performance, Plug-and-Play Oracle Driver for PHP (state as of Nov 2026)

> Method note: these notes come from about 20 web searches and fetches done on 2026-11-19. Some Oracle pages (instant-client download page, license page, a Medium blog) returned HTTP 403 and could not be read in full. Items marked **[background, not re-verified]** come from the researcher's prior knowledge (pre-2026). The report writer should treat them as plausible but unconfirmed. Every number marked "estimate" is a back-of-envelope figure, not a measurement.

---

## Q1. Option 1: Build on the existing ext-oci8 (PECL) with a userland PHP layer

### Takeaway
ext-oci8 is mature, maintained by Oracle and still released (3.4.1, Dec 2025, PHP 8.2–8.5). A userland layer on top is the lowest-risk and fastest way to ship. It inherits two problems: compiling against Instant Client headers and SDK, and a blocking API. It also lacks some modern features that ODPI-C/python-oracledb expose (AQ, SODA, VECTOR helpers, sessionless transactions, etc.). It does not address the "plug and play" goal.

### Cited Findings
- Latest OCI8 PECL release is **3.4.1 (2025-12-23)** and requires PHP ≥ 8.2. Earlier releases: 3.4.0 (2024-07-24), 3.3.0 (2023-04-20). Windows DLLs are published. Unversioned `pecl install oci8` targets PHP 8.2, 8.3, 8.4 and 8.5 — [PECL oci8](https://pecl.php.net/package/oci8)
- The PECL page itself now says "**PECL is deprecated**" and "PHP Installer for Extensions (PIE) is the replacement for PECL" — [PECL oci8](https://pecl.php.net/package/oci8)
- OCI8 "can be linked with Oracle Client libraries from Oracle Database 11.2 or later" — [PECL oci8](https://pecl.php.net/package/oci8)
- Oracle states PHP OCI8 and PDO_OCI are part of the PHP open-source project and maintained by Oracle — [Oracle PHP Developer Center](https://developer.oracle.com/php/) (via search summary)
- A php-src issue asked for OCI8 to be rebuilt on ODPI-C. The poster noted ODPI-C still needs Instant Client because it wraps OCI rather than replacing it — [php-src #10585](https://github.com/php/php-src/issues/10585)
- PDO_OCI is still described in parts of the manual as "EXPERIMENTAL" — [PHP manual PDO_OCI](https://www.php.net/manual/en/ref.pdo-oci.php)
- The common framework integration (Laravel) is built on OCI8 — [yajra/laravel-oci8](https://github.com/yajra/laravel-oci8)

### Inferences
- **[background, not re-verified]** OCI8 and PDO_OCI were unbundled from php-src in **PHP 8.4** under the RFC "Unbundle ext/imap, ext/pspell, ext/oci8, and ext/PDO_OCI" and now live only on PECL. This matches the PECL page carrying 8.4/8.5 builds.
- **[background]** OCI8 already supports REF CURSOR (`oci_new_cursor`), OUT binds, LOBs (`OCILob`), collections (`OCICollection`, only for simple element types), implicit results (`oci_get_implicit_resultset`, OCI8 2.0+), DRCP and connection pooling via `oci_pconnect`. It has **no general object-type (ADT) support**: you cannot bind or fetch arbitrary `CREATE TYPE ... AS OBJECT` instances. It also has no AQ, SODA, JSON-native or VECTOR-native APIs. A userland layer can work around object types with PL/SQL wrapper packages or JSON conversion, but that hurts both performance and developer experience.
- Effort estimate: userland layer (DBAL, typed binds, PL/SQL call helpers, ORM adapters) is about **1–3 engineer-months** to an MVP. Risk is low. Performance is the same as native OCI8, i.e. good: C fetch loop, prefetch and statement cache come from OCI.
- Plug-and-play score is poor: the user still needs Instant Client (runtime and SDK headers to compile), `pecl`/PIE build tools, and LD_LIBRARY_PATH / ldconfig setup. Docker users can hide this, shared-hosting users cannot.
- Strategic value: this is the best **Phase 0/1 compatibility backend** behind an abstraction (a driver interface), so the userland API can later swap to a better core.

### Gaps
- Whether OCI8 is already published as a PIE package on Packagist (PIE uses Composer metadata). The PECL page does not say, and I did not find a confirming source.
- Oracle's roadmap for OCI8 (feature additions vs maintenance only) was not found.

---

## Q2. Option 2: PHP FFI binding to ODPI-C or directly to OCI (libclntsh)

### Takeaway
FFI against **ODPI-C** is technically viable. ODPI-C has a small, stable C API covering nearly everything (objects, AQ, SODA, DRCP, pools, JSON, VECTOR) and loads libclntsh dynamically at runtime. Three things work against it. It still requires Instant Client. FFI is restricted by default (`ffi.enable=preload`), and per-call overhead is high enough to hurt row-by-row fetch loops. And ODPI-C itself must be compiled and shipped as a .so/.dylib/.dll. No existing PHP FFI–ODPI-C project was found. Binding FFI directly to OCI is strictly worse: the OCI API is huge and handle-heavy.

### Cited Findings
- ODPI-C features: SQL/PL/SQL execution, LOBs, REF CURSORs, array fetch/bind, statement caching, **User Defined Types**, **AQ**, **SODA**, **DRCP**, homogeneous and heterogeneous **session pools** with proxy auth and HA, **JSON**, **VECTOR**, CQN, Application Continuity (limited), two-phase commit, result cache, privileged connections — [ODPI-C home](https://oracle.github.io/odpi/)
- ODPI-C license: dual **UPL 1.0 / Apache 2.0** ("you may modify it and/or redistribute it") — [ODPI-C home](https://oracle.github.io/odpi/)
- ODPI-C is used by python-oracledb (thick), node-oracledb (thick), godror (Go), rust-oracle (Rust), ruby-ODPI, Oracle.jl, oranif (Erlang), nimodpi, oratcl, Haskell bindings. **PHP is not listed** — [ODPI-C home](https://oracle.github.io/odpi/)
- ODPI-C "uses the shared library loading mechanism available on each supported platform to load the Oracle Client library at runtime" (no link-time dependency on libclntsh) — [ODPI-C](https://oracle.github.io/odpi/)
- **ODPI-C 6.0.0 (2026-05-04)** dropped support for Oracle Client libraries older than 19c — [ODPI-C release notes](https://odpi-c.readthedocs.io/en/latest/releasenotes.html)
- No public project binding PHP FFI to ODPI-C was found (search returned only OCI8 docs and the php-src issue) — [php-src #10585](https://github.com/php/php-src/issues/10585)
- `ffi.enable` default is **"preload"**: FFI is allowed only in the CLI SAPI and in preloaded files. `"true"` enables it everywhere. Both `ffi.enable` and `ffi.preload` are `INI_SYSTEM` (php.ini only). `ffi.preload` loads bindings at startup and scripts reach them via `FFI::scope()`. `FFI\CData` objects created in preloaded files can be used from normal scripts. `ffi.preload` is the route when `opcache.preload_user` prevents `FFI::load()` — [PHP manual: FFI configuration](https://www.php.net/manual/en/ffi.configuration.php)
- FFI per-call overhead (anecdotal, not a rigorous benchmark): a trivial function called through FFI (PHP→C→Go) was **>300× slower than the equivalent native PHP function**. The author attributes this to boundary-crossing cost — [rblind.com post](https://rblind.com/post/9115605)
- For comparison in another language, per-call FFI cost varies widely by implementation: Perl FFI::Raw about 20 µs/call vs FFI::Platypus about 1 µs/call for the same `zmq_send` — [gist calid](https://gist.github.com/calid/17df5bcfb81c83786d6f)
- General guidance: FFI fits "less frequent, heavier operations where the cost of crossing the language boundary is amortized" — [Codefinity PHP FFI course](https://codefinity.com/courses/v2/386ded05-44b4-46bd-8646-5fe60513d035/26f97bb2-2d86-4402-9de4-e6ad358a24c9/d9946b44-abfd-4098-b5e0-3c3a0a8a8329)

### Inferences
- **Install story.** FFI avoids compiling a PHP extension, which is a real win: the code is `composer require`-able. It does **not** avoid (a) Instant Client, or (b) a native ODPI-C shared library. ODPI-C is about 30 C files and could be prebuilt per OS/arch and shipped in the Composer package (Linux x64/arm64 glibc and musl, macOS arm64/x64, Windows x64), since its license permits redistribution. Users must still flip `ffi.enable=true` or configure `ffi.preload` in php.ini. Many managed hosts and hardened images disable FFI. So it is "fewer steps", not "plug and play".
- **Performance shape (estimate).** A DB round trip costs about 100 µs–several ms, so per-statement FFI overhead (a handful of calls) is noise. The danger is **per-row × per-column** calls: `dpiStmt_fetch` plus `dpiStmt_getQueryValue` per column, then conversion of `dpiData` unions into PHP zvals via `FFI\CData` field access. 100k rows × 10 cols ≈ 1M boundary crossings plus CData accesses. At an assumed ~0.3–1 µs each, that is roughly 0.3–1 s of pure overhead, versus tens of ms in a C extension. Mitigations: `dpiStmt_fetchRows` (array fetch into ODPI-C-owned buffers), then read the buffer with a single `FFI::string()` or memcpy per column batch. A thin C "shim" could also pack rows into a PHP-friendly format. This needs a benchmark before committing.
- **[background, not re-verified]** PHP's JIT (8.0+) does not, as far as known, inline or specialize FFI calls the way LuaJIT does. There were php-src discussions/PRs about JIT-compiled FFI calls (Dmitry Stogov) but I could not confirm in this session whether any landed in 8.4/8.5. Treat FFI calls as an opaque, relatively expensive internal call.
- **[background]** FFI over OCI directly would mean porting OCI8's ~20k lines of C-level handle and descriptor management logic into PHP FFI code. That combines the high per-call cost with a much larger API surface. Not recommended. ODPI-C exists precisely to make this layer small.
- **Thread-safety and lifecycle risk:** FFI `CData` lifetimes interact with PHP request lifecycles. Persistent connections or pools across requests in PHP-FPM are hard with FFI (no persistent-resource API). They are easier in long-running runtimes (Swoole, RoadRunner, FrankenPHP worker mode).
- Role in the strategy: FFI+ODPI-C is an attractive **prototyping vehicle**. It can validate API design and ODPI-C coverage (objects, AQ, VECTOR) in weeks, without writing extension C. It is not a production core for heavy fetch workloads.

### Gaps
- No rigorous, published PHP 8.4/8.5 FFI per-call microbenchmark (e.g. ns/call for `FFI::cdef` of `abs()`) was found. The report should recommend running one.
- Whether PHP 8.5 added JIT support for FFI calls: unconfirmed.
- No data on what share of production PHP hosts allow `ffi.enable=true`.

---

## Q3. Option 3: A new native extension (C on ODPI-C, Rust via ext-php-rs, Zig/C++), distributed via PIE / prebuilt binaries

### Takeaway
A native extension gives the best performance and the most complete feature surface. Using ODPI-C, it inherits objects, AQ, SODA, pools, JSON and VECTOR. There is a second path that changed in 2026: **Oracle now maintains a pure-Rust thin driver (`oracledb` crate, 26.0.0-beta.6, Nov 2026)**. A Rust extension built with ext-php-rs on top of it could need **no Instant Client at all**. PIE (1.x, PECL officially deprecated) plus prebuilt-binary GitHub Actions make distribution far easier than in the PECL era. Still, on Linux/macOS PIE is mainly a build-from-source tool unless the author ships binaries.

### Cited Findings
**PIE status**
- PIE 1.0 was published in **early 2026**. PECL no longer accepts new packages and directs authors to PIE infrastructure — [php-dictionary: PIE](https://php-dictionary.readthedocs.io/en/latest/dictionary/pie.ini.html)
- PECL's own pages state "PECL is deprecated" and point to PIE — [PECL oci8](https://pecl.php.net/package/oci8)
- Releases visible in Nov 2026: **1.5.5 (30 Oct, marked "Latest")** fixing advisory GHSA-4538-pqqf-mv67. A 1.4.x maintenance line runs in parallel (1.4.12). **1.6.0-rc.1…rc.5 (4–17 Nov)**. Changes include fixes for "pie staticphp exe issues" (#783, #784, i.e. PIE itself shipped as a static-php-cli executable), "add self update for executables" (#776), and "update pie build to exclude pre-packaged-binary by default" (#774) — [php/pie releases](https://github.com/php/pie/releases)
- The original 2024 pre-release was a PHAR — [PHP Foundation blog: PIE pre-release (Nov 2024, older)](https://thephp.foundation/blog/2024/11/19/pie-pre-release/)
- PIE relies on extension authors for Windows pre-built DLLs (it does not compile on Windows). The design starts with precompiled Windows binaries attached to GitHub releases — [Vonage blog](https://developer.vonage.com/en/blog/managing-php-extensions-is-as-easy-as-pie); [PIE design](https://www.github.com/ThePHPF/pie-design)
- A php-org GitHub Action (**pie-ext-binary-builder**) builds `.so` binaries and attaches them to a GitHub release for extension maintainers — [php/pie-ext-binary-builder](https://github.com/php/pie-ext-binary-builder)
- Example of the ecosystem adopting this: v8js PR "Add PIE / Composer installability + CI for prebuilt binaries" — [v8js PR #547](https://github.com/phpv8/v8js/pull/547)

**Rust route**
- ext-php-rs is on the **0.15.x** line (0.15.13 dated 2026-05-11; 0.15.14 latest on docs.rs). Recent additions: ModuleGlobals (0.15.9), named-argument callables (0.15.7). Still pre-1.0 — [ext-php-rs CHANGELOG](https://docs.rs/crate/ext-php-rs/0.15.13/source/CHANGELOG.md); [docs.rs ext-php-rs](https://docs.rs/crate/ext-php-rs/latest); [GitHub extphprs/ext-php-rs](https://github.com/extphprs/ext-php-rs)
- Production-ish PHP extensions built on ext-php-rs and published to Packagist in 2026: a gRPC extension (v0.4.0, 2026-08-19), a Judy-array extension, flow-php-ext — [bsn4/grpc](https://packagist.org/packages/bsn4/grpc); [flow-php/flow-php-ext](https://packagist.org/packages/flow-php/flow-php-ext). An alternative binding crate, **phper** (0.17.x), also exists — [docs.rs phper](https://docs.rs/crate/phper/latest/builds/3559903)
- **Oracle-maintained pure-Rust driver**: crate `oracledb` **26.0.0-beta.6 (2026-11-12)**, "A pure Rust driver for Oracle Database without any Oracle Client libraries required, maintained by Oracle". Owners: anthony-tuininga, sharadraju. Repo github.com/oracle/rust-oracledb. Rust ≥ 1.89. DB 12–26. Lists: SQL/PL/SQL, compressed fetch, prefetch, client/server result cache, statement cache, encrypted network traffic, VECTOR, JSON, LOBs, array DML, connection pooling, **DRCP**, privileged connections, end-to-end tracing, optional Arrow. Docs coverage 45%. **Beta** — [docs.rs oracledb](https://docs.rs/crate/oracledb/latest)
- That crate replaced an earlier community `oracledb` crate (0.1–0.7, by Muhammed Durakovic). The earlier crate was a clean-room port of **python-oracledb v4.0.1 thin mode**, verified by running python-oracledb's own thin test suite against the Rust engine, and is now renamed `oraclemcp-driver-cx` — [docs.rs oracledb 0.7.0](https://docs.rs/crate/oracledb/0.7.0); [docs.rs oracledb latest](https://docs.rs/crate/oracledb/latest)
- Another pure-Rust TNS implementation, **oracle-rs** (0.1.x), offers Tokio async, TLS/wallet, and pooling via `deadpool-oracle` — [docs.rs oracle-rs](https://docs.rs/oracle-rs); [GitHub stiang/oracle-rs](https://github.com/stiang/oracle-rs)
- rust-oracle (the ODPI-C–based thick Rust crate) is listed among ODPI-C users — [ODPI-C home](https://oracle.github.io/odpi/)

### Inferences
- **C + ODPI-C extension.** Highest performance: fetch loop in C, zvals built directly, persistent pools via PHP persistent resources. Widest features: objects/collections, AQ, SODA, JSON, VECTOR, sessionless transactions as ODPI-C adds them. Because ODPI-C dlopen()s libclntsh at runtime, the extension **compiles without Instant Client SDK headers** (ODPI-C embeds its own OCI declarations). That makes CI-built prebuilt binaries for PIE straightforward, unlike OCI8, which needs the SDK at build time. Instant Client is still needed at runtime. Effort estimate: **6–12 engineer-months** to OCI8 parity plus objects/AQ, for an experienced PHP-internals C developer. Main risk: maintenance burden across PHP minor versions (ZTS/NTS × 8.2–8.5 × OS/arch matrix).
- **Rust + ext-php-rs + Oracle's `oracledb` (thin) crate.** This is the only path found that delivers **native speed without Instant Client**: the protocol runs in compiled Rust and is maintained by Oracle's driver team (the same people behind python-oracledb/node-oracledb). Plug-and-play becomes "`pie install vendor/oracle` and done", provided prebuilt binaries exist for each PHP version/ZTS/arch, since building from source needs a Rust toolchain. Risks: (1) the crate is **beta**, its feature list does not yet mention REF CURSOR, objects/collections or implicit results, and coverage must be verified against PL/SQL-heavy needs; (2) ext-php-rs is pre-1.0 with API churn; (3) Rust↔PHP zval conversion overhead must be designed carefully (bulk conversion, avoid per-cell callbacks); (4) thin-mode limitations would carry over (no NNE in python thin; see Q4). Effort estimate: **3–6 engineer-months** for an MVP extension wrapping the crate. Most protocol risk sits with Oracle.
- Fallback hybrid: rust-oracle (ODPI-C, thick) under the same ext-php-rs facade gives a "thick mode" for features thin lacks, mirroring python-oracledb's thin/thick duality.
- **Zig/C++**: no Oracle-specific prior art found. Zig could make cross-compiling the C/ODPI-C extension easier, but it has no ecosystem advantage over C or Rust here.
- **static-php-cli / FrankenPHP**: since PIE itself now ships as a static-php-cli executable, the toolchain clearly works. A statically linked PHP binary or FrankenPHP build that embeds a thin-mode Oracle extension would be a single-file deploy, the ultimate plug-and-play. With an ODPI-C/OCI extension this is much harder: Instant Client is proprietary, cannot be statically linked, and is glibc-only.
- **[background, not re-verified]** FrankenPHP's worker mode keeps PHP alive across requests. That makes connection pools (DRCP or client-side) effective with any long-lived native extension.

### Gaps
- Whether Oracle's Rust `oracledb` crate supports REF CURSOR, implicit results, object types/collections, AQ, and NNE. Its docs page did not list them. Check the GitHub repo and test suite.
- The exact PIE 1.x mechanism and metadata for **pre-packaged binaries on Linux/macOS** (naming convention, how PIE matches PHP version/ZTS/libc). The releases page mentions "pre-packaged-binary" but I could not read the docs.
- No numbers found on ext-php-rs overhead vs a hand-written C extension.
- Whether static-php-cli has an Oracle extension recipe (none found).

---

## Q4. Option 4: A pure-PHP "thin" implementation of Oracle Net/TNS + TTC

### Takeaway
There is substantial prior art: python-oracledb thin (Cython), node-oracledb thin (pure JS), go-ora (pure Go), Oracle's new Rust thin crate, oracle-rs, a Dart port. These prove the protocol can be reimplemented, and that Oracle's own thin drivers are open-source references under permissive licenses. **No PHP thin implementation exists.** A pure-PHP thin driver is the most "plug and play" option possible (Composer only, works with Fibers/AMPHP/ReactPHP/Swoole for async). It is also the largest, highest-risk effort, and pure-PHP decode speed is unproven for large fetches.

### Cited Findings
- No pure-PHP TNS/TTC driver turned up. PHP results were only OCI8-based packages and a `tnsnames.ora` parser — [dcarbone/tns-parser](https://github.com/dcarbone/tns-parser); [yajra/laravel-oci8](https://github.com/yajra/laravel-oci8)
- python-oracledb Thin mode "connects directly to Oracle Database 12.1 or later" with no Oracle Client libraries. The module "is built with Cython for safety and speed" — [python-oracledb intro](https://python-oracledb.readthedocs.io/en/latest/user_guide/introduction.html)
- python-oracledb history: cx_Oracle began in **1998** (Anthony Tuininga). The 1.0 release put "a significant amount of engineering focus on Thin mode". Object and collection support in Thin mode came in **1.2**. **asyncio support in 2.0** (thin mode) — [python-oracledb intro](https://python-oracledb.readthedocs.io/en/latest/user_guide/introduction.html); [levelup: open source Python thin driver](https://levelup.gitconnected.com/open-source-python-thin-driver-for-oracle-database-e82aac7ecf5a); [i-programmer: Python driver goes asynchronous](https://i-programmer.info/news/216-python/16901-python-driver-for-oracle-database-goes-asynchronous.html)
- Oracle published a post on Thin-mode **object performance**, a sign that objects in thin needed separate optimisation — [Medium/oracledevs: Thin mode object performance](https://medium.com/oracledevs/python-oracledb-thin-mode-object-performance-8a230fe2bf4)
- Thin-mode limitations (python-oracledb, current docs): no bequeath/local connections or `ORACLE_SID`/`TWO_TASK`. Does not read `sqlnet.ora`/`oraaccess.xml`. **No Native Network Encryption/checksumming** (TLS is the alternative). No OAuth2/IAM token connect strings (use `access_token`). Pool tags, events, sharding keys and heterogeneous pools unsupported. NLS env vars and `ORA_TZFILE` ignored. NCHAR types require AL16UTF16. mTLS wallets need PEM `ewallet.pem` — [python-oracledb Appendix B](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_b.html)
- node-oracledb 6 introduced a **thin mode in pure JavaScript** — [cjones-oracle Medium (title)](https://cjones-oracle.medium.com/node-oracledb-6-is-a-thin-mode-javascript-driver-for-node-js-and-oracle-database-5dcea6602c7e) (page body returned 403)
- **go-ora** (pure Go, "no C dependencies or Oracle client required"), about 988 stars. Supports REF CURSOR (`WrapRefCursor`), multiple result sets, **UDTs incl. nested and arrays**, associative arrays, bulk DML, CLOB/BLOB/NCLOB/BFILE with streaming, OUT params, **AES/SHA native encryption/integrity via Diffie-Hellman** (i.e. NNE), TLS/mTLS/wallets, Kerberos. v3 adds 23ai support. TTC v9 negotiation. Direct-path BulkCopy experimental — [sijms/go-ora](https://github.com/sijms/go-ora)
- **dart-oracledb**: an independent pure-Dart port of the thin TNS/TTC protocol "as documented and implemented in Oracle's official node-oracledb driver" — [abibiano/dart-oracledb](https://github.com/abibiano/dart-oracledb)
- The earlier community Rust `oracledb` crate was a **clean-room port of python-oracledb 4.0.1 thin**, validated with python-oracledb's own test suite. Oracle then shipped its own pure-Rust thin driver — [docs.rs oracledb 0.7.0](https://docs.rs/crate/oracledb/0.7.0); [docs.rs oracledb latest](https://docs.rs/crate/oracledb/latest)
- A third party built a read-only **TNS/TTC proxy** that exposes Oracle Fusion Cloud as an Oracle-compatible endpoint, showing the server side of the protocol is also being reimplemented — [krokozyab/oracle-fusion-tns-proxy](https://github.com/krokozyab/oracle-fusion-tns-proxy)

### Inferences
- **Size and complexity (estimate, [background]).** The python-oracledb thin implementation (`src/oracledb/impl/thin/*.pyx` plus shared `impl/base`) is commonly described as tens of thousands of lines of Cython. It covers TNS packets (connect, accept, refuse, redirect, data, marker), the authentication handshake (O5LOGON with AES/PBKDF2 verifiers, 11g/12c verifier types), TTC message types (execute, fetch, LOB ops, describe, piggybacks), Oracle NUMBER/DATE/TIMESTAMP/INTERVAL binary formats, charset handling, LOB locators, object pickling (the "image" format for ADTs/collections), DRCP, OOB break/reset, and fast-auth. I did not measure the line count. The report should flag it as an estimate.
- **Effort.** Oracle's own team (cx_Oracle maintainers since 1998, with internal protocol knowledge) shipped thin 1.0 in 2022 and kept adding features (objects in 1.2, asyncio in 2.0) over the following years. A community PHP port with no internal docs would likely need **12–24+ engineer-months** to reach "SELECT/DML/PL-SQL/REF CURSOR/LOB/objects/TLS" parity, plus an ongoing chase of server-version changes. This is an estimate. The best accelerator is a line-by-line port of python-oracledb thin or node-oracledb thin (JS maps closely onto PHP), validated against their test suites, as the Rust and Dart ports did.
- **Licensing/legal [background, not re-verified].** python-oracledb and node-oracledb are released by Oracle under dual **UPL 1.0 / Apache 2.0** (the same scheme as ODPI-C, which is cited above). Porting their thin implementations is a derivative work permitted under those licenses, with attribution and notices preserved. Keeping the port under Apache-2.0/UPL-compatible terms is the low-risk path. Independent reverse engineering of the wire protocol (as go-ora did) has existed for years without known Oracle legal action, but no legal opinion was found. Recommend counsel review if a commercial entity backs the project.
- **Performance in pure PHP (estimate).** Connect/execute latency is network-bound, so it is fine. Bulk fetch decode (unpacking NUMBER bytes into int/float/string, building arrays) in PHP userland is likely **several times slower than C** for large result sets. PHP's JIT helps tight byte loops, but `unpack()`, `substr()` and `ord()` remain internal function calls. node-oracledb thin in pure JS shows a JIT'd dynamic language can carry thin mode acceptably, but V8's JIT is far more aggressive than PHP's. **No benchmark exists; a spike benchmark (decode 100k rows of NUMBER/VARCHAR2) is required.**
- **Async upside.** A pure-PHP socket implementation gets non-blocking I/O for free with PHP 8.1+ Fibers (Revolt/AMPHP, ReactPHP) and Swoole/OpenSwoole socket hooks. No other option offers that, and it mirrors python-oracledb thin enabling asyncio in 2.0.
- **Feature parity gaps to expect:** NNE (go-ora shows it can be done, python thin skipped it), Kerberos, OS auth, sqlnet.ora semantics, heterogeneous pools, AQ (python thin added AQ later; unverified), Application Continuity.

### Gaps
- Exact LOC of python-oracledb thin and node-oracledb thin (not measured in this session).
- Published thin-vs-thick performance numbers for python-oracledb or node-oracledb (not found in this session).
- Any formal Oracle statement on third-party protocol reimplementation (none found).
- Whether node-oracledb thin was a port of python thin (the blog page returned 403).

---

## Q5. Option 5: Sidecar/proxy approaches and async/non-blocking Oracle in PHP

### Takeaway
A sidecar (a Go service on go-ora, a Rust service on Oracle's Rust thin crate, or ORDS REST) moves Oracle connectivity out of PHP entirely. That gives zero PHP-side native dependencies and real pooling, at the cost of an extra hop (serialization plus IPC/TCP latency) and an extra service to operate. Async Oracle in PHP is today limited to Swoole-style hooks over pdo_oci (as used by the Hyperf ecosystem). A pure-PHP thin or a non-blocking native extension would be needed for real Fiber-based async.

### Cited Findings
- Swoole runtime hooks can turn synchronous PDO clients non-blocking (`SWOOLE_HOOK_ALL`, `Swoole\Runtime::enableCoroutine`). The documented examples are MySQL-centric — [OpenSwoole runtime flags](https://openswoole.com/docs/core/coroutine-client-pdo-config); [Swoole runtime flags](https://swoole.co.uk/docs/modules/swoole-runtime-flags)
- A Hyperf package uses pdo-oci with Swoole to boost concurrency for Oracle — [tangwei/hyperf-oracle](https://packagist.org/packages/tangwei/hyperf-oracle)
- python-oracledb thin mode added **asyncio in 2.0**. Thin (pure socket) mode is what made async possible — [i-programmer](https://i-programmer.info/news/216-python/16901-python-driver-for-oracle-database-goes-asynchronous.html)
- oracle-rs (Rust) provides Tokio async plus pooling (`deadpool-oracle`), a ready building block for an async Rust sidecar — [docs.rs oracle-rs](https://docs.rs/oracle-rs)
- go-ora is a full-featured pure-Go driver suitable for a Go sidecar — [sijms/go-ora](https://github.com/sijms/go-ora)

### Inferences
- **[background, not re-verified]** Swoole v5.1+ added coroutine support for `pdo_pgsql`, `pdo_oci`, `pdo_odbc` and `pdo_sqlite` (compile flag `--with-swoole-oracle` or similar). Because OCI does its own socket I/O inside libclntsh, Swoole cannot hook it with plain socket hooks. The mechanism is believed to be thread offloading or OCI non-blocking mode. Confirm against the Swoole changelog.
- **ext-oci8 is blocking.** Under PHP-FPM that is fine (one request per process). Under Fibers/AMPHP/ReactPHP it blocks the whole event loop. OCI has a non-blocking mode (`OCI_ATTR_NONBLOCKING_MODE`), and ODPI-C exposes `dpiConn_setCallTimeout`/break but not an event-loop-friendly async API. A native async extension would need a thread pool. That is complex in ZTS-agnostic PHP extensions.
- **ORDS [background]:** Oracle REST Data Services exposes SQL and PL/SQL over HTTP (AutoREST, `/_/sql` endpoint, REST-enabled SQL). It is free and needs zero PHP-side drivers, but it changes the programming model. Each call is an HTTP round trip with JSON serialization, transactions spanning calls are awkward (REST-enabled SQL runs a batch per call), and REF CURSORs and LOB streaming are limited. Suitable for edge/serverless or as an optional "HTTP backend", not as the core driver.
- **Sidecar cost (estimate).** Adding a localhost hop (Unix socket, a binary protocol such as MessagePack or Arrow IPC) costs roughly tens of µs per call. That is small next to a DB round trip, but it doubles operational surface (deploy, monitor, version). Most attractive where PHP hosts cannot install extensions at all, or as an async gateway for many short PHP workers with a shared pool (similar to PgBouncer; Oracle's own answer here is DRCP / CMAN-TDM).
- The sidecar is effectively "Option 3-Rust without ext-php-rs". If the Rust thin crate is chosen as the engine, the same core could ship both as a PHP extension and as a sidecar binary.

### Gaps
- Exact Swoole version and mechanism for pdo_oci coroutine support (not confirmed in this session).
- Whether ODPI-C 6.x added any async-friendly API (not checked).
- ORDS current version and REST-enabled SQL limits (not fetched).

---

## Q6. Oracle Instant Client packaging and licensing: the plug-and-play bottleneck

### Takeaway
Every option except the thin ones (pure-PHP thin, Rust-thin extension, sidecar) needs Instant Client at runtime. Basic Light reduces size but keeps the proprietary license, glibc-only Linux builds (no Alpine/musl), and manual library-path setup. Instant Client is redistributable under the OTN Development and Distribution License, so bundling it in Docker images is possible but legally conditioned.

### Cited Findings
- Instant Client is under the **"Oracle Technology Network Development and Distribution License Terms for Instant Client"**, which grants use "for your business purposes and development and testing purposes" and sets conditions for distribution — [Oracle Instant Client license](https://www.oracle.com/downloads/licenses/instant-client-lic.html) (page body returned 403; clause wording via search snippet)
- The Instant Client FAQ says it is available under an OTN license that **allows redistribution** — [Instant Client FAQ](https://www.oracle.com/database/technologies/faq-instant-client.html)
- Some vendors sign separate redistribution agreements (e.g. Cloudera's redistribution license for Instant Client) — [Cloudera Instant Client redistribution agreement](https://es.cloudera.com/legal/terms-and-conditions/oracle-instant-client-redistribution-license-agreement.html)
- **Basic Light**: "smaller version of the Basic package, with only English error messages and Unicode, ASCII, and Western European character set support" — [Oracle docs 23: About Instant Client Basic Light](https://docs.oracle.com/en/database/oracle/oracle-database/23/ntcli/about-oracle-instant-client-light.html); [Oracle docs 26](https://docs.oracle.com/en/database/oracle/oracle-database/26/ntcli/about-oracle-instant-client-light.html)
- Old (version unclear, Windows 32-bit) Basic Light zips were about 20 MB and Basic about 34 MB. **These are outdated figures** — [Oracle Windows 32 downloads](https://www.oracle.com/nl/database/technologies/instant-client/microsoft-windows-32-downloads.html)
- ODPI-C 6.0 requires Instant Client **19c or later** — [ODPI-C release notes](https://odpi-c.readthedocs.io/en/latest/releasenotes.html). python-oracledb Thick mode likewise requires client libraries 19+ — [python-oracledb Appendix B](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_b.html)

### Inferences
- **[background, not re-verified]** Current 23ai/23.x Linux x64 Basic zips are roughly 75–90 MB compressed (≈250 MB unzipped). Basic Light is roughly 35–45 MB compressed. Instant Client Linux builds target glibc, so Alpine images need `gcompat`/`libnsl` workarounds or a Debian/Oracle Linux base. Instant Client is available via Oracle Linux yum/dnf (`oracle-instantclient-release-el8/el9`) and as official container images (`ghcr.io/oracle/oraclelinux9-instantclient:23`). gvenzl's `gvenzl/oracle-free` and `gvenzl/oracle-xe` are the de-facto images for running the **database** in dev/CI. They are not client images. **All sizes need verification on the live download page (403 in this session).**
- The license permits redistribution, but OTN terms generally forbid modification and require passing on license terms. That is acceptable for Docker images and installers, but makes "auto-download Instant Client from a Composer plugin" legally grey (a click-through acceptance is expected). Oracle's own pages and container images are the safe channel.
- Practical consequence: a "thick" driver can at best reach "one Dockerfile line" plug-and-play. Only a thin implementation reaches "composer require / pie install and done" on any host, including Alpine, macOS arm64 and shared hosting.

### Gaps
- Current exact sizes for Instant Client 23.x/26 Basic and Basic Light (download page 403).
- Exact redistribution conditions (license page 403). Have counsel or the report writer read the page directly.
- Status of official Oracle Linux PHP container images with OCI8 preinstalled (not fetched this session).

---

## Q7. Decision matrix and recommended phased strategy

### Takeaway
No single option wins every axis. The highest-leverage 2026 development is **Oracle's own pure-Rust thin driver**. Wrapping it in a PHP extension (ext-php-rs), distributed as prebuilt binaries via PIE, is the only route found that combines near-native speed, no Instant Client, and Oracle-maintained protocol code. Its feature completeness for PL/SQL-heavy workloads (REF CURSOR, objects) is unverified and it is still beta. The recommended strategy therefore puts a stable PHP API first with swappable backends, keeps OCI8 as the compatibility backend, and adds a thin backend.

### Cited Findings
- Oracle-maintained pure-Rust thin crate exists (beta, Nov 2026) — [docs.rs oracledb](https://docs.rs/crate/oracledb/latest)
- PIE 1.x is the official installer and PECL is deprecated — [php/pie releases](https://github.com/php/pie/releases); [PECL oci8](https://pecl.php.net/package/oci8)
- ODPI-C covers objects, AQ, SODA, DRCP, pools, JSON and VECTOR, and loads the client at runtime — [ODPI-C](https://oracle.github.io/odpi/)
- Third-party ports of Oracle's thin drivers (Rust, Dart) have succeeded by following python/node-oracledb — [docs.rs oracledb 0.7.0](https://docs.rs/crate/oracledb/0.7.0); [dart-oracledb](https://github.com/abibiano/dart-oracledb)
- python-oracledb's thin/thick dual-mode design is the established pattern — [python-oracledb Appendix B](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_b.html)

### Inferences

**Decision matrix** (1 = poor, 5 = excellent; scores are the researcher's judgement from the evidence above)

| Option | Raw performance | PL/SQL feature coverage (REF CURSOR, OUT, LOB, implicit results, objects/collections) | Modern features (AQ, SODA, JSON, VECTOR, DRCP) | Plug-and-play install | Async potential | Effort / time to MVP | Long-term risk |
|---|---|---|---|---|---|---|---|
| 1. ext-oci8 + userland | 5 | 3 (no general object types) | 2 | 2 (compile + IC SDK + IC runtime) | 1 (Swoole hook only) | 5 (1–3 mo) | 3 (Oracle maintenance-mode?) |
| 2a. FFI → ODPI-C | 2–3 (fetch loops costly) | 5 | 5 | 2–3 (no compile, but IC + ffi.enable + ship libodpic) | 1 | 4 (2–4 mo) | 3 |
| 2b. FFI → OCI directly | 2 | 4 | 3 | 2 | 1 | 1 | 2 |
| 3a. C extension → ODPI-C | 5 | 5 | 5 | 3 (PIE prebuilt possible; IC runtime still needed) | 2 | 2 (6–12 mo) | 4 |
| 3b. Rust ext (ext-php-rs) → Oracle `oracledb` thin crate | 4–5 | 3–4 (verify REF CURSOR/objects; beta) | 3–4 (JSON, VECTOR, DRCP yes; AQ/SODA unknown) | **5** (prebuilt via PIE, no IC) | 3 (Tokio inside; PHP-side async needs design) | 3 (3–6 mo) | 3 (beta crate; ext-php-rs pre-1.0) |
| 3c. Rust ext → rust-oracle (ODPI-C) | 5 | 5 | 4 | 3 | 2 | 3 | 3 |
| 4. Pure-PHP thin (port of python/node thin) | 2–3 (unbenchmarked) | 3→5 over time | 2→4 over time | **5** (composer only, any host) | **5** (Fibers/AMPHP/ReactPHP/Swoole) | 1 (12–24+ mo) | 2 (protocol drift, single-team bus factor) |
| 5a. Sidecar (Go/Rust) | 4 (extra hop) | 4–5 (go-ora is rich) | 3 | 3 (no PHP ext, but extra service) | 4 | 3 | 3 (ops burden) |
| 5b. ORDS (HTTP) | 2 | 2 | 3 | 4 (pure HTTP) | 4 | 4 | 3 (programming-model mismatch) |

**Recommended phased strategy (inference)**

- **Phase 0 (0–2 months): API first, backend-agnostic.**
  - Define a PHP-level driver contract (connection, statement, typed binds incl. OUT/IN OUT, REF CURSOR as nested statement, LOB streams, implicit results, object/collection value objects, pool).
  - Ship it with an **ext-oci8 backend** (Option 1) so users get value immediately (Laravel/Doctrine adapters).
  - Build a benchmark harness: 100k-row fetch, bulk insert, PL/SQL call with OUT plus REF CURSOR, LOB 10 MB.
- **Phase 1 (1–4 months, parallel spikes):**
  - (a) An **FFI→ODPI-C prototype** to validate object types, AQ and VECTOR API design, and to measure FFI fetch overhead.
  - (b) A **feature audit and spike of Oracle's Rust `oracledb` thin crate** behind ext-php-rs: REF CURSOR, objects, implicit results, LOB streaming, TLS/wallet, NNE. Measure zval-conversion throughput.
  - (c) A **pure-PHP decode micro-benchmark**: port only the NUMBER/VARCHAR2 row decoder from python-oracledb thin and time it under PHP 8.5 with JIT on/off.
  - Go/no-go decisions come from these numbers.
- **Phase 2 (4–12 months): the "default" core.**
  - If spike (b) passes, make the **Rust thin extension the default backend**. Use prebuilt binaries for PHP 8.2–8.5 × NTS/ZTS × linux-glibc/musl x64/arm64 × macOS × Windows, via PIE and GitHub Actions (pie-ext-binary-builder or a custom matrix), plus static-php-cli/FrankenPHP recipes.
  - If it fails on PL/SQL features, build the **C extension on ODPI-C** instead (or rust-oracle under the same ext-php-rs shell). This gives a thick backend whose build needs no IC SDK.
  - Either way, keep the OCI8 backend for legacy hosts.
- **Phase 3 (12+ months, optional/community):**
  - A **pure-PHP thin backend** for async (Fibers/AMPHP/ReactPHP) and for hosts that cannot load any extension. Port it from node-oracledb/python-oracledb thin and validate against their test suites, as the Rust and Dart ports did. Start with the subset most needed for async web workloads.
  - Optionally a **sidecar mode** reusing the Rust core for serverless or shared-hosting users.
- **Cross-cutting:**
  - Licensing: keep code Apache-2.0/UPL compatible to allow porting from Oracle drivers.
  - Do not auto-download Instant Client. Document official Oracle container images instead.
  - Track ODPI-C 6.x (19c+ client floor) and python-oracledb release notes as the feature roadmap oracle.

### Gaps
- All effort estimates are judgement-based. No published effort data for comparable ports was found beyond the qualitative history of python-oracledb.
- Performance scores lack PHP-specific benchmarks for FFI fetch loops, ext-php-rs conversion and pure-PHP decode. Phase 1 spikes are designed to fill exactly these gaps.
- Feature coverage of Oracle's Rust thin crate (REF CURSOR, objects, AQ, implicit results) is the single most decision-relevant unknown.
