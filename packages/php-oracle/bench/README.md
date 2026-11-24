# Benchmark: Core against oci8

Tracks ADR 0002's performance goal with real numbers: Core, through its
public API over the `rajska_oracle` Extension, should be **at least 2×
faster than oci8**; 2× slower is the floor that catches a disaster. It
covers the scenarios of the rust-oracledb spike
([`spikes/rust-oracledb/bench`](../../../spikes/rust-oracledb/bench)) and
every v1 verb, and compares each ratio with the spike's.

## Run it

```bash
ddev fixtures        # once: the fx_* tables in the rajska schema
ddev bench           # release build, crosscheck, then the matrix (about 15 min)
ddev bench rounds=3 only=call,fetch   # a subset; scale=0.2 cuts iterations
ddev bench layers    # where Core's time goes (see below)
ddev bench-zts       # FrankenPHP worker mode, PHP ZTS, in Docker (about 10 min)
```

- `ddev bench` builds the Extension in release (`lto = "fat"`, as it ships)
  into `/var/cache/cargo-target/bench` (`BENCH_TARGET_DIR` overrides it),
  runs `crosscheck.php`, then `run.php`, which writes
  `output/summary-<tag>.md` and `output/raw-<tag>.jsonl` (not committed).
- Another schema: set `ORACLE_USER` / `ORACLE_PASSWORD` (it needs the
  fixtures, and `fixtures/sql/grants.sql` for `layers`).
- From a worktree, run the scripts the commands run:
  `bash .ddev/commands/web/bench` inside the web container,
  `packages/php-oracle/bench/zts/run.sh` on the host.
- Measure on an idle machine: client and database share it, so another
  build or test suite moves every number (see [Noise](#noise)).

## Method

- **Same values.** A comparison marked *same values* runs only after
  `crosscheck.php` proved both sides return identical PHP values: same keys,
  types and values, `DateTimeImmutable` zones included. oci8 gets there the
  way an application over oci8 must: a PHP conversion per value
  (`src/Oci8Values.php`), decided once per statement from its columns. The
  other comparisons put Core against oci8's own strings, the work oci8 does
  by default.
- **Isolation.** Every (case, round) is a fresh PHP process that loads only
  its own driver, with `bench/php.ini` alone (no `conf.d`): OPcache on, JIT
  off, `zend.assertions=-1`, the same for both. oci8 runs with session
  formats that make its DATE and TIMESTAMP text equal to Core's
  `TemporalMode::String`.
- **Samples.** The Connection opens before warm-up. Each timed iteration is
  one Call or query, every row read into PHP, and freeing it, timed with
  `hrtime()`. The median pools every iteration of every round; the spread is
  the lowest and highest per-round median. Rounds run a scenario's cases
  forward on odd rounds and backward on even ones.
- **Speedup** = oci8 median / Core median: above 1, Core is faster.
- **Build.** The Extension in release, as it ships. Core is loaded from
  `src/` by `bootstrap.php` (no Composer), so the ZTS image needs no
  `vendor/`.

| Scenario | Work per iteration |
|---|---|
| `call` | `fx_scalar.echo_out`: 3 IN (NUMBER, multibyte VARCHAR2, DATE) and 3 OUT, by `procedure()` or `run()` of the spike's block |
| `fetch` | `fx_bench_rows`: 10,000 rows × 9 columns (integer and decimal NUMBER, BINARY_DOUBLE, ASCII and multibyte VARCHAR2, a nullable VARCHAR2, DATE, TIMESTAMP(6)), with `all()` or `foreach` |
| `numbers`, `dates` | the NUMBER / BINARY_DOUBLE columns, or the DATE / TIMESTAMP columns, of the same 10,000 rows, under each `ValueMapping` mode |
| `cursor` | `fx_cursor.items(1000)`: a REF CURSOR of 1,000 rows × 5 columns |
| `lobs` | `fx_bench_lobs`: 1,000 rows with a 1,000-character CLOB and a 1,000-byte BLOB, read whole |
| `largelob` | one 16 MiB BLOB (`fx_lobs` row 4), read whole |
| `insert` | 1,000 rows into `fx_batch_rows`: `runMany()` against an oci8 loop and an oci8 `FORALL` block |
| `connect`, `request`, `logon` | `Oracle::connect()` + `close()` through the Pool against `oci_pconnect()` + `oci_close()`; the same with a one-row query; `standalone` against `oci_new_connect()` |

`bench/zts` runs the spike's FrankenPHP case: 8 worker threads, `wrk` at 1
and 8 connections, workloads `scalar`, `scalar-txn` (an uncommitted INSERT,
the scalar Call, then the end of the request with the transaction open),
`cursor` and `fetch`. Drivers: `oci8` (one Connection per worker thread),
`core` (`Oracle::connect()` per request, `close()` at its end) and
`core-held` (one Core Connection per worker thread, in a Pool of its own).

## Results (2026-11-24)

Apple M5, 10 cores, OrbStack; PHP 8.4.28 NTS; Oracle AI Database 26ai Free
in the next container. Extension 0.1.0 at `spec/32-core-v1` (oracledb fork
`c99ec8c`). 10 rounds; the full table, p95 and memory are in
[`output/summary-nts.md`](output/summary-nts.md).

**The goal is not met.** Core reaches 2× oci8 only on numbers, small LOBs
read inline, Array DML against a loop of executes, and the fetch with the
fast opt-ins. The default fetch, the REF CURSOR and the Call are below the
spike's ratios; the scalar Call, DATE objects, `Lob::contents()`, a large
LOB and connect + close are slower than oci8, and a large LOB and
connect + close are below the 2×-slower floor.

| Scenario | Core | oci8 | Speedup | Spike | |
|---|---|---|---|---|---|
| Call 3 IN + 3 OUT, `run()` (same values) | 25.0 µs | 22.6 µs | 0.90× | 1.28× | slower |
| Call 3 IN + 3 OUT, `procedure()` (same values) | 33.7 µs | 22.6 µs | 0.67× | 1.28× | slower |
| Fetch 10k × 9, `all()` (same values) | 27.97 ms | 33.72 ms | 1.21× | 1.73× | below 2× |
| Fetch 10k × 9, `foreach` (same values) | 27.15 ms | 33.11 ms | 1.22× | 1.75× | below 2× |
| Fetch 10k × 9, `all()`, Core's values vs oci8's strings | 27.97 ms | 21.28 ms | 0.76× | – | slower |
| Fetch 10k × 9, fast opt-ins (same values) | 11.95 ms | 25.78 ms | 2.16× | – | **goal met** |
| Fetch 10k × 9, fast opt-ins vs oci8's strings, `all()` | 11.95 ms | 21.28 ms | 1.78× | 1.69× (plain) | below 2× |
| Fetch 10k × 9, fast opt-ins vs oci8's strings, `foreach` | 13.18 ms | 21.60 ms | 1.64× | 1.73× (plain) | below 2× |
| Fetch 10k × 9, fetchSize / prefetch 1000 (same values) | 24.55 ms | 32.40 ms | 1.32× | – | below 2× |
| Numbers 10k × 4, `Exact` (same values) | 4.57 ms | 19.46 ms | 4.26× | – | **goal met** |
| Numbers 10k × 4, `Float` (same values) | 5.45 ms | 17.57 ms | 3.23× | – | **goal met** |
| Dates 10k × 2, `Object` (same values) | 22.73 ms | 18.74 ms | 0.82× | – | slower |
| Dates 10k × 2, `String` (same values) | 7.09 ms | 6.51 ms | 0.92× | – | slower |
| REF CURSOR 1k, `all()` (same values) | 1.69 ms | 2.00 ms | 1.18× | 1.21× | below 2× |
| REF CURSOR 1k, `foreach` (same values) | 1.76 ms | 1.77 ms | 1.01× | 1.27× | below 2× |
| REF CURSOR 1k, fast opt-ins vs oci8's strings | 1.02 ms | 1.12 ms | 1.10× | 1.22× (plain) | below 2× |
| 1k small LOBs, `Inline` vs `OCI_RETURN_LOBS` (same values) | 8.77 ms | 44.99 ms | 5.13× | – | **goal met** |
| 1k small LOBs, `Lob::contents()` vs `load()` (same values) | 60.64 ms | 44.74 ms | 0.74× | – | slower |
| One 16 MiB BLOB, `contents()` vs `load()` | 19.69 ms | 7.72 ms | 0.39× | – | **below the floor** |
| Insert 1k rows, `runMany()` vs a loop of executes | 4.16 ms | 18.13 ms | 4.36× | – | **goal met** |
| Insert 1k rows, `runMany()` vs a `FORALL` block | 4.16 ms | 3.30 ms | 0.79× | – | slower |
| Connect + close, Pool vs `oci_pconnect` | 10.6 µs | 1.3 µs | 0.12× | – | **below the floor** |
| Connect + 1-row query + close | 31.6 µs | 24.9 µs | 0.79× | – | slower |
| Logon + logoff, `standalone` vs `oci_new_connect` | 13.64 ms | 16.66 ms | 1.22× | – | below 2× |

"Same values" pits Core's default values against oci8 plus the PHP
conversions that give the same values; it is the comparison the spike's
*typed* shape made. "oci8's strings" is oci8 doing no conversion at all; the
spike's *plain* shape gave both drivers oci8's strings, which Core cannot
return (its closest are the fast opt-ins: int and float numbers, temporal
text).

**Memory** (median RSS, kB): Core 19.7 MB before connecting and 21.5 MB
after, against 29.0 and 50.6 MB for oci8 with Instant Client. The 10k fetch
with `all()` peaks at 40.8 MB against 70.7 MB.

**The spike, rerun the same day** (`spikes/rust-oracledb/bench`, 3
rounds), to tell a regression from the environment: Call 14.3 µs against
oci8's 20.5 (1.44×); 10k fetch typed 15.1–16.5 ms against 27.2–27.4
(1.65–1.81×); REF CURSOR typed 1.02–1.04 ms against 1.28–1.35 (1.25–1.30×).
The spike's ratios still hold on this machine, so the gaps below are Core's
and the Extension's, not the environment's.

### FrankenPHP worker mode (ZTS)

8 worker threads, `wrk` 10 s per point, 2 rounds
([`output/frankenphp-zts.txt`](output/frankenphp-zts.txt)); requests per
second, both rounds:

| Workload | Connections | oci8 | Core, `connect()` per request | Core, held per worker |
|---|---|---|---|---|
| `scalar` | 1 | 4,875 / 4,379 | 3,484 / 3,852 | 4,302 / 4,778 |
| `scalar` | 8 | 9,314 / 8,915 | 8,816 / 7,481 | 8,688 / 8,262 |
| `scalar-txn` | 1 | 419 / 478 | 356 / 346 | 489 / 537 |
| `scalar-txn` | 8 | 1,129 / 756 | 990 / 722 | 982 / 1,323 |
| `cursor` | 8 | 1,334 / 1,132 | 1,481 / 1,159 | 1,205 / 1,183 |
| `fetch` | 8 | 70 / 74 | 97 / 99 | 101 / 97 |

- `scalar-txn` (the spike's case) no longer collapses: the Release's
  rollback runs outside the Pool mutex (C14), so Core at 8 connections is
  level with oci8 (the spike's Pool fell to 134–1,100 req/s with a p50 of
  6–13 ms). Every driver hits `wrk` timeouts on it: 8 open DML transactions
  contend in the database, so read p50 and the order of magnitude, not the
  exact rate.
- The 10k fetch keeps a 1.3–1.4× lead; `connect()` per request costs about
  20% of `scalar`'s rate at 1 connection (one Round trip per Release, below).

## Where the time goes

`ddev bench layers` times the same work through the Extension's classes
alone (*native*), Core's adapter (*driver*) and the public API (*core*), on
one Session, and counts Round trips
([`output/layers-nts.md`](output/layers-nts.md)):

| Work | native | driver | core | Round trips |
|---|---|---|---|---|
| Call 3 IN + 3 OUT | 19.0 µs | 19.5 µs | 21.2 µs `run()`, 24.2 µs `procedure()` | 1 |
| Fetch 10k × 9 | 19–24 ms (first in its run) | 21.9 ms | 23.0 ms `all()`, 22.5 ms `foreach` | 100 |
| REF CURSOR 1k × 5 | 1.21 ms | 1.26 ms | 1.25 ms | 12 (oci8: 11.3) |
| 1k small LOBs | 4.7 ms unread, 6.3 ms inline | – | 58.1 ms `contents()`, 6.8 ms inline | 10 |
| One 16 MiB BLOB | – | – | 11.1 ms `contents()`, 22.8 ms inline | 17, 2 inline (oci8: 2) |
| Connect + close | 7.4 µs | 8.0 µs | 8.2 µs | 1 (oci8: 0) |

Core's PHP layers cost little: under 5% of a fetch and 2–5 µs of a Call.
The gaps are in the Extension, one follow-up each:

1. **DATE / TIMESTAMP as `DateTimeImmutable`: 0.59 µs a value**, against
   0.11 µs as text and 0.01–0.03 µs for a NUMBER (10k values of one column:
   7.6 ms, 2.7 ms, 1.7–1.9 ms; the protocol floor is 1.7 ms). The two
   temporal columns are about 12 of the default fetch's 22 ms. Each value
   goes through two `format!`s, a lookup of
   `date_create_immutable_from_format` by name and a userland call.
   Removing most of it would put the default fetch near 2×.
2. **`Lob::contents()`: about 29 µs of CPU per small LOB.** `readChunk()`
   allocates a zeroed 1 MiB (BLOB) or 3 MiB (CLOB) buffer on every call, and
   `contents()` calls it twice. A large LOB is read 1 MiB per Round trip and
   joined in PHP; read inline, one 16 MiB read through the crate is slower
   still.
3. **The Release costs a Round trip**: it rolls back even when no
   transaction is open, where `oci_pconnect` + `oci_close` costs none.
4. **The Call: the Extension is about 5 µs slower than the spike's
   extension** on the same statement (bind and options reading, OUT buffers
   of 32767 bytes, the DATE OUT through item 1), and Core adds 2 µs for
   `run()` and 5 µs for `procedure()` (`Call::of()` validates every name with
   a regex on every call). A Call is one Round trip of about 8 µs, so 2× oci8
   (11 µs) needs almost all of the client time gone.

## Noise

Client and database share one machine with other workloads (parallel builds
and test suites during these runs: load average 4–6 on 10 cores). A
Session's dedicated server process may also land on an efficiency core: one
Session ran a Call 2.5× slower than its siblings for a whole run. Hence the
wide per-round spreads in `summary-nts.md` (a Call: 22–50 µs). The pooled
medians repeated across two full runs mostly within 10% (up to 20%);
compare ratios, not absolute times, and rerun on an idle machine before
reading a change under 10%.
