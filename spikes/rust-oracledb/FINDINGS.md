# Findings: is the Rust thin Backend the Primary Backend?

**Verdict: yes with conditions.** Every hard gate passes. Eighteen items pass
only with a workaround, a planned upstream fix or a patch we build, so each of
them becomes a condition with a named exit (below). The decision itself is
recorded in [ADR 0002](../../docs/adr/0002-rust-thin-backend-is-the-primary-backend.md).

This file consolidates the spike tracked by the wayfinder map
[Spike: is the Rust thin Backend our Primary Backend?][map]. Each row links the
ticket that holds the full result and the evidence pinned to the commit it was
measured on.

## Setup

- Crate under test: `oracledb` 26.0.0-beta.6 (pure Rust, sync API), built
  `--release`.
- Extension: `rajska_oracle` on ext-php-rs 0.16.5, PHP 8.4.28, NTS and ZTS.
- Database: Oracle AI Database 26ai Free 23.26.3 (`FREEPDB1`), in ddev on an
  Apple M5, same Docker host as the client (a round trip costs about 13 µs).
- Baseline: oci8 3.4.1 on Instant Client 23.26.1. oci8 is never a candidate; it
  is only the yardstick for results and speed.
- Fixtures: the `fx_*` schema in [`fixtures/sql/`](../../fixtures/sql/).

## How to read this

The categories and the verdict rule come from
[Acceptance criteria][t6], decided before any probe ran:

- **hard gate**: a failure with no way out makes the verdict *no*.
- **workaround**: acceptable with a documented workaround.
- **upstream**: acceptable when upstream has planned it.
- **build**: acceptable if building it ourselves is feasible (contained and
  additive only, as read in [Probe REF CURSORs and Implicit results][t8]).
- **not a gate**: a risk or a documented limit.

Results are **pass**, **fail**, **workaround** (passes only through a
workaround), **build: feasible**, **upstream** or **unknown**. A condition
`Cn` refers to the list in [Conditions](#conditions).

## Checklist

### PL/SQL fidelity

| Item | Category | Result | Condition | Evidence |
|---|---|---|---|---|
| OUT / IN OUT NUMBER, incl. beyond i64/f64 | hard gate | **pass**: exact through `OracleNumber` (38 digits, 9.99…e125, ±1e-130) | | [Probe scalar binds][t7] · [probe][e7-probe] · [output][e7-out] |
| NUMBER IN from a PHP float | hard gate | **workaround**: `f64` binds as BINARY_DOUBLE (0.1 → 0.10000000000000001) | C4 | [Probe scalar binds][t7] (N7, N7b) |
| VARCHAR2 OUT, multibyte | hard gate | **pass** | | [Probe scalar binds][t7] (S2, S13, F4) |
| VARCHAR2 OUT, max length | hard gate | **workaround**: a bare bind is capped at 16000 bytes | C5 | [Probe scalar binds][t7] (S5–S10) |
| VARCHAR2 IN OUT | hard gate | **workaround**: the buffer is 4 × the input's bytes | C6 | [Probe scalar binds][t7] (S14–S21) |
| DATE / TIMESTAMP OUT / IN OUT | hard gate | **pass**: nanoseconds, year 1 to 9999, BC dates | | [Probe scalar binds][t7] (D1–D9, T1–T3) |
| TIMESTAMP WITH (LOCAL) TIME ZONE | hard gate | **workaround**: the instant is right, the offset is lost (UTC) | C7 | [Probe scalar binds][t7] (T4–T7) |
| Stored function return value | hard gate | **pass** | | [Probe scalar binds][t7] (F1–F9) |
| Several REF CURSOR OUT in one Call | hard gate | **pass**: interleaved reads, empty cursors keep columns | | [Probe REF CURSORs and Implicit results][t8] · [probe][e8-probe] · [output][e8-out] |
| Function returning a REF CURSOR | hard gate | **pass** | | [Probe REF CURSORs and Implicit results][t8] (R1–R3) |
| Nested `CURSOR()` column | workaround | **pass**: no workaround needed | | [Probe REF CURSORs and Implicit results][t8] (K1–K4) |
| Cursors are lazy and don't leak | (recorded) | **pass**: 100 rows per round trip, no ORA-01000 over 1000 Calls | | [Probe REF CURSORs and Implicit results][t8] (C5, C6, C8) |
| Implicit results | build | **build: feasible**: fails as shipped (TTC message 27 after the block ran, OUT binds lost); a 6-file additive patch passes 13/13 | C1 | [Probe REF CURSORs and Implicit results][t8] · [patch][e8-patch] · [output][e8-patched] |
| `SELECT … FROM TABLE(pipelined_fn())` | hard gate | **pass**: scalar and object rows, zero rows, inside a REF CURSOR | | [Probe object types and Collections][t9] · [probe][e9-probe] · [output][e9-out] |
| Fetch of objects and Collections | (recorded) | **pass**: everywhere, including inside REF CURSORs; oci8 cannot fetch them at all | | [Probe object types and Collections][t9] (F1–F5, N1–N8) |
| IN / OUT / IN OUT binds of objects and Collections | build | **build: feasible**: no API as shipped; an additive patch passes 38/38 | C2 | [Patch: object and Collection binds][t15] · [patch][e15-patch] · [output][e15-out] |
| Collection in `TABLE(:ids)` | workaround | **pass** with the C2 patch (10,000 ids); `JSON_TABLE` over an OSON bind meanwhile | C2 | [Probe object types and Collections][t9] (W1–W3b), [Patch][t15] (T1–T4) |
| Associative arrays (`INDEX BY`) | workaround | **pass** with the C2 patch (sparse and negative keys); JSON wrapper meanwhile | C2 | [Probe object types and Collections][t9] (A1–A3), [Patch][t15] (I12, I13, X4) |
| LOB read/write correctness, 16 MiB | hard gate | **pass**: byte for byte, inline and through locators | | [Probe LOB streaming and execute_batch errors][t10] · [probe][e10-lob] · [output][e10-lob-out] |
| Empty LOB fetched inline | hard gate (correctness) | **workaround**: comes back as NULL | C8 | [Probe LOB streaming][t10] (I2, L2) |
| CLOB read across a surrogate pair | hard gate (correctness) | **workaround**: `invalid encoded string` | C9 | [Probe LOB streaming][t10] (S6–S9) |
| CLOB write granularity | hard gate (correctness) | **workaround**: each `write()` must be whole UTF-8 | C10 | [Probe LOB streaming][t10] (W1–W3) |
| LOB chunked streaming, 100 MiB | upstream or build | **pass** as shipped: flat RSS (14–38 MiB), every byte checked | | [Probe LOB streaming][t10] (H1–H5) |
| LOB IN to PL/SQL over 32767 bytes | (new gap) | **workaround**: `Lob` is not bindable; chunked VARCHAR2 binds in one block | C11 | [Probe LOB streaming][t10] (W6–W12) |
| `execute_batch` per-row errors | upstream | **upstream**: fails fast with no row index, earlier rows stay applied | C3 | [Probe LOB streaming and execute_batch errors][t10] · [probe][e10-batch] · [output][e10-batch-out] |

### Pool, DRCP and timeouts

| Item | Category | Result | Condition | Evidence |
|---|---|---|---|---|
| One Pool shared across threads | hard gate | **pass**: 22,400 Calls from 16–64 threads, 0 wrong, 0 overlapping Session holders | | [Probe one Pool shared across threads][t11] · [probe][e11-probe] · [output][e11-out] |
| Pool growth past one Connection | hard gate (Pool) | **workaround**: stuck at 1 when `min_connections` or `connection_increment` > 1 | C12 | [Probe one Pool][t11] (G1–G6) |
| Acquire wait timeout | hard gate (Pool) | **workaround**: `acquire()` waits forever | C13 | [Probe one Pool][t11] (X1–X3) |
| Release with an open transaction | hard gate (Pool) | **workaround**: rollback runs under the Pool mutex, releases queue (p50 6–13 ms) | C14 | [Benchmark][t13] (FrankenPHP `scalar-txn`) |
| DRCP connect + Call | hard gate | **pass**: `:pooled` and `(SERVER=POOLED)`, Pool over DRCP correct | | [Probe one Pool][t11] (D1–D3) |
| DRCP server release | (new gap) | **upstream** (not yet planned): an idle Connection pins a DRCP server | C15 | [Probe one Pool][t11] (D3c–D4), [rust-oracledb#98][u98] |
| `set_call_timeout` | upstream | **pass**: same behaviour as oci8, per round trip | | [Probe one Pool][t11] (C1–C5) |
| Cancelling a Call from another thread | upstream | **upstream**: no API yet | C16 | [rust-oracledb#24][u24], [maturity research][r4] |

### Distribution and platform

| Item | Category | Result | Condition | Evidence |
|---|---|---|---|---|
| PIE binaries: Linux glibc + musl × x86_64 + arm64, macOS arm64, NTS + ZTS | hard gate | **pass**: build, load and a real query on all Linux jobs; no Instant Client | | [Probe that oracledb + ext-php-rs build for every PIE target][t12] · [CI run][e12-run] · [output][e12-out] |
| Windows x86_64, NTS + TS | workaround | **workaround**: stable + `RUSTC_BOOTSTRAP=1` and `#![cfg_attr(windows, feature(abi_vectorcall))]` | C18 | [Probe PIE targets][t12] |
| PIE can forbid source builds | (research) | **pass**: `download-url-method: ["pre-packaged-binary"]` | | [Can rajska_oracle ship as PIE pre-packaged binaries?][t5] · [research][r5] |
| glibc floor | (recorded) | **risk**: 2.34 from bookworm; RHEL 8 (2.28) and Debian 11 (2.31) not covered | | [Probe PIE targets][t12] |
| macOS Intel | out | not built | | [PIE research][r5] |
| Runtime dependencies | (recorded) | **pass**: libc + libgcc_s (Linux), libSystem (macOS) | | [Probe PIE targets][t12] |

### Crate and database

| Item | Category | Result | Condition | Evidence |
|---|---|---|---|---|
| Crate maturity | not a gate | **risk**: early beta, one maintainer, API churn, no GA date | C17 | [How mature is rust-oracledb…?][t4] · [research][r4] |
| License | (recorded) | **pass**: `UPL-1.0 OR Apache-2.0` | | [maturity research][r4] |
| DB version 26ai | hard gate | **pass**: every probe ran on 26ai | | all probes |
| DB version 19c / 21c | hard gate | **unknown**: not run; upstream tests both | | [maturity research][r4] |
| NNE, Kerberos, LDAP, external auth | not a gate | **unsupported in v1**: NNE will never come (ezTLS replaces it) | | [maturity research][r4] |
| TLS / mTLS | (recorded) | **pass**: PEM wallet; TLS stack verified on every PIE target | | [maturity research][r4], [Probe PIE targets][t12] |

## Performance

Speedup is the oci8 median divided by the `rajska_oracle` median, over 10
interleaved rounds in fresh `php -n` processes, and both drivers return
identical PHP values on every case ([Benchmark rajska_oracle against oci8][t13],
methodology in [`bench/README.md`](bench/README.md)).

| Workload | NTS | ZTS | Gate | Distance to the 2× target |
|---|---|---|---|---|
| (a) Call, 3 IN + 3 OUT scalar binds | 1.28× (17.2 → 13.4 µs) | 1.34× | **pass** | 2× needs 8.6 µs per Call; round-trip bound |
| (b) fetch 10k rows × 9 mixed columns | 1.69–1.73× (672k → 1.16M rows/s) | 1.75–1.78× | **pass** | about 60% of the time is the row → zval conversion in the extension |
| (c) Call returning a REF CURSOR of 1k rows | 1.22–1.24× | 1.25–1.26× | **pass** | conversion is about 38%; the rest is the Call and its round trips |

Recorded, not gated:

- **Memory**: about 17 MB RSS after connecting, against 47 MB for oci8 with
  Instant Client; peak on (b) 31 MB against 61 MB.
- **FrankenPHP worker mode, 8 threads**: one shared Pool costs nothing next to
  one Connection per thread; (b) gives 265–279 req/s against about 151 for oci8.
- **LOB streaming**: reads beat oci8 (BLOB 1.2–1.5 GiB/s against 1.0); writes
  are about 1.5× slower ([Probe LOB streaming][t10]).
- **Caveat**: over a real network, round trips dominate (a) and (c) and their
  ratios move toward parity. (b) keeps more of its lead because conversion is
  client CPU.

**Optimisation front toward 2×** (not a condition): the zval conversion in
`rajska_oracle`. Likely costs, unverified: a new zend_string key per column per
row, a `String` built and copied for each NUMBER or DATE, and one
`date_create_immutable` per value in the typed shape. Evidence:
[`bench/output/summary-nts.md`][e13-nts], [`summary-zts-cli.md`][e13-zts],
[`breakdown-nts.txt`][e13-breakdown].

## Conditions

Each condition names its exit, as the verdict rule requires. "Fork" means our
fork of `oracle/rust-oracledb`, pinned to an exact version and carrying the
patches; each fork patch is also offered upstream as a PR (contributions need
the Oracle Contributor Agreement).

| # | Condition | Category | Exit |
|---|---|---|---|
| C1 | Implicit results | build | **Fork**: [`patch/implicit-results.diff`][e8-patch] (+96 −4, 6 files, adds `ExecResult::implicit_results()`), plus the `execute_batch` and `Connection::query()` paths (about 3–5 lines each); then an upstream PR. Interim: `DBMS_SQL.GET_NEXT_RESULT` handed back as REF CURSOR OUT slots. |
| C2 | Binds of objects, Collections and Associative arrays (incl. `TABLE(:ids)`) | build | **Fork**: [`patch/object-binds.diff`][e15-patch] (+659 −13, 8 files, additive only), plus dedicated `ErrorKind`s and coverage of LOB, `BINARY_INTEGER`, `BOOLEAN`, TSTZ and NCHAR attributes; then an upstream PR. `VARCHAR2`-indexed Associative arrays stay unsupported. Interim: JSON in, REF CURSOR or `json_object` out; `TABLE(:ids)` via `JSON_TABLE` over an OSON bind. Core never emits a typeless `DB_TYPE_OBJECT` bind (ORA-03137 drops the session at the third). |
| C3 | `execute_batch` per-row errors | upstream | **Upstream**: planned on [rust-oracledb#41][u41]. Interim: savepoint, then a row-by-row retry on failure, which reports every failing row with its index and ORA code. Core rolls back to the savepoint, because earlier rows stay applied. |
| C4 | NUMBER IN from a PHP float | workaround | **Workaround**: bind as `OracleNumber` of the shortest round-trip text. |
| C5 | VARCHAR2 OUT past 16000 bytes | build | **Fork** (decided in [Bind model and Type catalog](https://github.com/openrajska/php-oracle/issues/22)): a public wrapper that declares a bind's type and max size (about 40–50 lines, 2 files), the equivalent of [rust-oracledb#65][u65]; then an upstream PR. No padding is sent. Spike workaround, superseded: bind a 32767-byte padding value as the OUT value. |
| C6 | VARCHAR2 IN OUT that grows | build | **Fork** (decided in [Bind model and Type catalog](https://github.com/openrajska/php-oracle/issues/22)): the same declared-size wrapper as C5, which also covers user-written Anonymous blocks. Spike workaround, superseded: wrap the Call in an Anonymous block with a local `varchar2(32767)`. |
| C7 | TSTZ offset | build | **Fork** (decided in [Mapping Oracle values to PHP values, LOBs included](https://github.com/openrajska/php-oracle/issues/23)): `OracleTimestamp` keeps a numeric offset, decoded from and encoded into the tz bytes (about 35–50 lines, 2 files; OSON gets it too); then an upstream PR. A value stored with a time-zone region comes back as the correct instant in UTC, because the crate has no region table. Spike workaround, superseded: `to_char(…, '… TZH:TZM')` on the server. |
| C8 | Empty LOB fetched inline comes back as NULL | build | LOBs are read through locators by default. **Fork** (decided in [Mapping Oracle values to PHP values](https://github.com/openrajska/php-oracle/issues/23)) for the opt-in inline mode: use the per-value null indicator the crate now discards (about 10–15 lines in `db_value.rs`); then an upstream PR. Unverified that the server sends "not null" for `EMPTY_CLOB()`; if not, the inline mode is not in v1. |
| C9 | CLOB read splitting a surrogate pair | build | **Fork** (decided in [Mapping Oracle values to PHP values](https://github.com/openrajska/php-oracle/issues/23)): `Lob::read` trims a trailing high surrogate and advances one unit less (about 15–25 lines in `lob.rs`); then an upstream PR. Spike workaround, superseded: retry with one UCS-2 unit less. |
| C10 | CLOB write must be whole UTF-8 | workaround | **Workaround**: a writer that holds back an incomplete trailing sequence (verified as `Utf8Boundary`). |
| C11 | LOB IN to PL/SQL over 32767 bytes | build | **Fork** (decided in [Bind model and Type catalog](https://github.com/openrajska/php-oracle/issues/22)): `ToDbValue for Lob` (about 25–35 lines); the Extension writes a temporary LOB and binds it, in SQL and PL/SQL alike; then an upstream PR. Spike workaround, superseded: ≤ 32767-byte VARCHAR2 binds appended to a temporary CLOB in one Anonymous block (16 MiB in 0.16 s). |
| C12 | Pool growth stalls at one Connection | workaround | **Workaround**: `min_connections ≤ 1`, `connection_increment = 1`, pre-acquire to warm. Reported as [rust-oracledb#97][u97] with a verified 3-line fix (fork candidate). |
| C13 | No acquire wait timeout | workaround | **Workaround**: a counting gate sized to `max_connections`, with a timed wait, in front of `acquire()` in the extension. |
| C14 | Release with an open transaction queues behind the Pool mutex | workaround | **Workaround**: the extension always ends the transaction itself before release (verified: p50 back to 1.2–2.6 ms). Candidate upstream fix: roll back outside the lock. |
| C15 | DRCP server is never released to the broker | upstream, else build | **Upstream**: [rust-oracledb#98][u98] (reported, not yet planned). If upstream doesn't plan it, **fork**: the crate already defines `_TTC_RPC_SESSION_RELEASE` but never sends it; feasibility not shown. Until then, DRCP gives no gain under PHP-FPM; under FrankenPHP (one Pool per process) it hardly matters. |
| C16 | Cancelling a Call from another thread | upstream | **Upstream**: [rust-oracledb#24][u24] (planned per the maintainer). `set_call_timeout` covers the common case meanwhile. |
| C17 | Crate maturity | not a gate | **Pin** the exact crate version (today `=26.0.0-beta.6`) through the fork; upgrade deliberately, rerunning the probes. |
| C18 | Windows builds | workaround | **Workaround**: CI only, pinned stable + `RUSTC_BOOTSTRAP=1` and `#![cfg_attr(windows, feature(abi_vectorcall))]` in the extension crate. Removed when rust-lang/rust#124485 stabilises `vectorcall`. |
| C19 | CHAR, NCHAR and NVARCHAR2 IN binds | build | **Fork** (decided in [Bind model and Type catalog](https://github.com/openrajska/php-oracle/issues/22)): strings always bind as VARCHAR, so there is no blank-padded CHAR comparison and no national-charset bind; the declared-type wrapper of C5 sends them as CHAR / NCHAR / NVARCHAR2, encoding NCHAR data in the national character set; then an upstream PR. Not probed. |
| C20 | Session time zone at logon | build | **Fork** (decided in [Mapping Oracle values to PHP values](https://github.com/openrajska/php-oracle/issues/23)): the crate sets the Session time zone from `$ORA_SDTZ` or else the process's fixed offset at connect, which ignores daylight saving. A connect option lets the Extension send PHP's default time zone by name when the Session is created; then an upstream PR. Not probed. |

## Risks and unknowns

- **19c / 21c are `unknown`.** Every probe ran on 26ai. Upstream tests 19c,
  21c and 26ai and accepts 12.1+. JSON-to-object conversion (the C2 interim
  workaround) was only tried on 26ai.
- **Single-maintainer beta.** All commits so far come from one Oracle engineer;
  development happens in an internal repo and the API broke between betas. C17
  pins the version, and the fork keeps us moving if upstream stalls.
- **glibc floor 2.34.** RHEL 8 and Debian 11 are not covered by the glibc
  binaries. Possible exit, not tried: a target pinned to 2.28 (zig or
  `manylinux_2_28`). musl binaries are the fallback.
- **Unsupported in v1**: NNE (Oracle will never add it; ezTLS on 23.26.4+ is the
  replacement), Kerberos, RADIUS, LDAP, external auth, SEPS, Application
  Continuity, TAF and FAN. Those users need a Thick Backend, which is out of this
  spike's scope.
- **Network latency** shrinks the lead on (a) and (c) toward parity.
- **Named `DBTIMEZONE`**: the crate fails every TIMESTAMP WITH LOCAL TIME ZONE
  fetch with `not_implemented` when the database time zone is a region name
  rather than an offset. Documented; v1 fails loudly, never with wrong data.
- **Not probed**: CHAR / NCHAR / NVARCHAR2, BINARY_FLOAT / BINARY_DOUBLE,
  PLS_INTEGER, BOOLEAN and INTERVAL binds; `SYS_REFCURSOR` IN binds; object
  attributes of LOB, TSTZ or BOOLEAN; XMLTYPE and `SYS.ANYDATA`; NCLOB and
  BFILE; `ping_interval` with a Session killed while idle; Pool `close()` while
  threads wait; DRCP purity; PIE itself downloading the assets.

## Upstream gaps ledger

| Gap | Status | Our side |
|---|---|---|
| Implicit results | not planned | C1 patch, PR candidate |
| Object / Collection binds; typeless `DB_TYPE_OBJECT` sent malformed | not planned | C2 patch (includes the client-side guard), PR candidate |
| Per-row batch errors; failing row index dropped on the error path | planned, [rust-oracledb#41][u41] | C3 |
| `setinputsizes` | open, [rust-oracledb#65][u65] | C6 |
| `OracleTimestamp` has no offset | not reported | C7 patch, PR candidate |
| Empty inline LOB read as NULL | not reported | C8 patch, PR candidate |
| `Lob::read` splits surrogate pairs | not reported | C9 patch, PR candidate |
| Session time zone is a fixed offset taken at connect | not reported | C20 patch, PR candidate |
| `Lob` is not bindable | not reported | C11 |
| Pool growth stall | reported, [rust-oracledb#97][u97] | C12 |
| No acquire wait timeout | not reported | C13 |
| Rollback under the Pool mutex | not reported | C14 |
| DRCP release / implicit connection pooling | reported, [rust-oracledb#98][u98] | C15 |
| Cancel a Call from another thread | planned, [rust-oracledb#24][u24] | C16 |
| NULL object element inside a Collection gives ORA-00600 (python-oracledb too) | reported, [python-oracledb#640][upy640] | fixed in the C2 patch |

[map]: https://github.com/openrajska/php-oracle/issues/1
[t4]: https://github.com/openrajska/php-oracle/issues/4
[t5]: https://github.com/openrajska/php-oracle/issues/5
[t6]: https://github.com/openrajska/php-oracle/issues/6
[t7]: https://github.com/openrajska/php-oracle/issues/7
[t8]: https://github.com/openrajska/php-oracle/issues/8
[t9]: https://github.com/openrajska/php-oracle/issues/9
[t10]: https://github.com/openrajska/php-oracle/issues/10
[t11]: https://github.com/openrajska/php-oracle/issues/11
[t12]: https://github.com/openrajska/php-oracle/issues/12
[t13]: https://github.com/openrajska/php-oracle/issues/13
[t15]: https://github.com/openrajska/php-oracle/issues/15
[r4]: https://github.com/openrajska/php-oracle/blob/research/rust-oracledb-maturity/docs/research/rust-oracledb-maturity-and-support.md
[r5]: https://github.com/openrajska/php-oracle/blob/research/pie-prebuilt-binaries/docs/research/pie-prebuilt-binaries-for-rajska-oracle.md
[e7-probe]: https://github.com/openrajska/php-oracle/blob/f08916a42d11357ced35586f056ced0795366aca/spikes/rust-oracledb/probe/src/main.rs
[e7-out]: https://github.com/openrajska/php-oracle/blob/f08916a42d11357ced35586f056ced0795366aca/spikes/rust-oracledb/probe/output/scalar-binds.txt
[e8-probe]: https://github.com/openrajska/php-oracle/blob/47aede370d65bae4547a5256f95299ee8cad1722/spikes/rust-oracledb/probe/src/bin/cursor.rs
[e8-out]: https://github.com/openrajska/php-oracle/blob/47aede370d65bae4547a5256f95299ee8cad1722/spikes/rust-oracledb/probe/output/cursors.txt
[e8-patch]: https://github.com/openrajska/php-oracle/blob/47aede370d65bae4547a5256f95299ee8cad1722/spikes/rust-oracledb/patch/implicit-results.diff
[e8-patched]: https://github.com/openrajska/php-oracle/blob/47aede370d65bae4547a5256f95299ee8cad1722/spikes/rust-oracledb/probe-patched/output/implicit-results.txt
[e9-probe]: https://github.com/openrajska/php-oracle/blob/74632c6d03e331f991cfec8b66537f40cf516a53/spikes/rust-oracledb/probe/src/bin/object.rs
[e9-out]: https://github.com/openrajska/php-oracle/blob/74632c6d03e331f991cfec8b66537f40cf516a53/spikes/rust-oracledb/probe/output/objects.txt
[e10-lob]: https://github.com/openrajska/php-oracle/blob/56930735fb9b81630c8a443c30b54f068c31d436/spikes/rust-oracledb/probe/src/bin/lob.rs
[e10-lob-out]: https://github.com/openrajska/php-oracle/blob/56930735fb9b81630c8a443c30b54f068c31d436/spikes/rust-oracledb/probe/output/lobs.txt
[e10-batch]: https://github.com/openrajska/php-oracle/blob/56930735fb9b81630c8a443c30b54f068c31d436/spikes/rust-oracledb/probe/src/bin/batch.rs
[e10-batch-out]: https://github.com/openrajska/php-oracle/blob/56930735fb9b81630c8a443c30b54f068c31d436/spikes/rust-oracledb/probe/output/batch.txt
[e11-probe]: https://github.com/openrajska/php-oracle/blob/e8b76fa1a46373ef362bef827fb2d9c43eeb0214/spikes/rust-oracledb/probe/src/bin/pool.rs
[e11-out]: https://github.com/openrajska/php-oracle/blob/e8b76fa1a46373ef362bef827fb2d9c43eeb0214/spikes/rust-oracledb/probe/output/pool.txt
[e12-run]: https://github.com/openrajska/php-oracle/actions/runs/44800329297
[e12-out]: https://github.com/openrajska/php-oracle/blob/d12a2693f4ac060a88f3df59967055c0a2ebeb09/spikes/rust-oracledb/php-ext/output/pie-targets.txt
[e13-nts]: https://github.com/openrajska/php-oracle/blob/9289f4e76eadd930a1f45d072d42695bd316eb6f/spikes/rust-oracledb/bench/output/summary-nts.md
[e13-zts]: https://github.com/openrajska/php-oracle/blob/9289f4e76eadd930a1f45d072d42695bd316eb6f/spikes/rust-oracledb/bench/output/summary-zts-cli.md
[e13-breakdown]: https://github.com/openrajska/php-oracle/blob/9289f4e76eadd930a1f45d072d42695bd316eb6f/spikes/rust-oracledb/bench/output/breakdown-nts.txt
[e15-patch]: https://github.com/openrajska/php-oracle/blob/ea30ef1d554ef5c89d31bd1461e5dd506eb505f8/spikes/rust-oracledb/patch/object-binds.diff
[e15-out]: https://github.com/openrajska/php-oracle/blob/ea30ef1d554ef5c89d31bd1461e5dd506eb505f8/spikes/rust-oracledb/probe-patched/output/object-binds.txt
[u24]: https://github.com/oracle/rust-oracledb/issues/24
[u41]: https://github.com/oracle/rust-oracledb/issues/41
[u65]: https://github.com/oracle/rust-oracledb/issues/65
[u97]: https://github.com/oracle/rust-oracledb/issues/97
[u98]: https://github.com/oracle/rust-oracledb/issues/98
[upy640]: https://github.com/oracle/python-oracledb/issues/640
