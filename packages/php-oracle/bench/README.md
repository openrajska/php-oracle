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
ddev bench           # release build, crosscheck, then the matrix (about 7 min)
ddev bench rounds=3 only=call,fetch   # a subset; scale=0.2 cuts iterations
ddev bench tag=run1 && ddev bench tag=run2   # two runs, then pool them:
ddev exec -d /var/www/html/packages/php-oracle/bench \
  php run.php from=run1,run2 tag=nts       # one summary of their 20 rounds
ddev bench layers    # where Core's time goes (see below)
ddev bench-zts       # FrankenPHP worker mode, PHP ZTS, in Docker (about 10 min)
```

- `ddev bench` builds the Extension in release (`lto = "fat"`, as it ships)
  into `/var/cache/cargo-target/bench` (`BENCH_TARGET_DIR` overrides it),
  runs `crosscheck.php`, then `run.php`, which writes
  `output/summary-<tag>.md` and `output/raw-<tag>.jsonl` (not committed).
  `run.php from=<tag>,<tag>` runs nothing: it pools those raw files into
  one summary.
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
in the next container. Extension 0.1.0 at `spec/32-core-v1` after the
performance tickets #76–#79 (oracledb fork `d2cbb0f`). 20 rounds, pooled
from two runs on a quiet machine; the full table, p95 and memory are in
[`output/summary-nts.md`](output/summary-nts.md). The *#64* column is the
first benchmark, before #76–#79.

**The goal is met for fetches, numbers, LOBs and Array DML, not for the
rest.** Core reaches 2× oci8 on the default 10k fetch with `all()`, the
fetch with a larger Fetch size, the fast opt-ins, numbers, small LOBs (read
inline or through `Lob::contents()`) and `runMany()` against a loop. It is
faster than oci8 but below 2× on `foreach` fetches, dates, the REF CURSOR,
the Call, a Pool request with a query, and a standalone logon. It is slower
than oci8 on four: the Call by `procedure()` against oci8's strings (0.98×)
and `runMany()` against a `FORALL` block (0.96×), both at parity, connect +
close (0.87×, 0.2 µs), and one 16 MiB BLOB (0.51×). Nothing is below the
2×-slower floor, but the 16 MiB BLOB sits on it: 0.48×–0.55× run by run
(see [Where the time goes](#where-the-time-goes)).

| Scenario | Core | oci8 | Speedup | #64 | Spike | |
|---|---|---|---|---|---|---|
| Call 3 IN + 3 OUT, `run()` (same values) | 17.2 µs | 18.4 µs | 1.07× | 0.90× | 1.28× | below 2× |
| Call 3 IN + 3 OUT, `procedure()` (same values) | 17.6 µs | 18.4 µs | 1.04× | 0.67× | 1.28× | below 2× |
| Call, `procedure()` vs oci8's strings | 17.6 µs | 17.3 µs | 0.98× | 0.72× | 1.28× (plain) | slower |
| Fetch 10k × 9, `all()` (same values) | 14.35 ms | 30.33 ms | 2.11× | 1.21× | 1.73× | **goal met** |
| Fetch 10k × 9, `foreach` (same values) | 15.43 ms | 28.20 ms | 1.83× | 1.22× | 1.75× | below 2× |
| Fetch 10k × 9, `all()`, Core's values vs oci8's strings | 14.35 ms | 17.22 ms | 1.20× | 0.76× | – | below 2× |
| Fetch 10k × 9, fast opt-ins (same values) | 8.13 ms | 22.91 ms | 2.82× | 2.16× | – | **goal met** |
| Fetch 10k × 9, fast opt-ins vs oci8's strings, `all()` | 8.13 ms | 17.22 ms | 2.12× | 1.78× | 1.69× (plain) | **goal met** |
| Fetch 10k × 9, fast opt-ins vs oci8's strings, `foreach` | 9.11 ms | 17.55 ms | 1.93× | 1.64× | 1.73× (plain) | below 2× |
| Fetch 10k × 9, `RowShape::List` vs oci8's `OCI_NUM` strings | 13.56 ms | 15.58 ms | 1.15× | 0.82× | – | below 2× |
| Fetch 10k × 9, fetchSize / prefetch 1000 (same values) | 13.12 ms | 27.68 ms | 2.11× | 1.32× | – | **goal met** |
| Numbers 10k × 4, `Exact` (same values) | 3.86 ms | 14.15 ms | 3.67× | 4.26× | – | **goal met** |
| Numbers 10k × 4, `Float` (same values) | 3.97 ms | 14.14 ms | 3.56× | 3.23× | – | **goal met** |
| Dates 10k × 2, `Object` (same values) | 10.52 ms | 12.64 ms | 1.20× | 0.82× | – | below 2× |
| Dates 10k × 2, `String` (same values) | 3.17 ms | 5.07 ms | 1.60× | 0.92× | – | below 2× |
| REF CURSOR 1k, `all()` (same values) | 955 µs | 1.32 ms | 1.39× | 1.18× | 1.21× | below 2× |
| REF CURSOR 1k, `foreach` (same values) | 1.00 ms | 1.49 ms | 1.49× | 1.01× | 1.27× | below 2× |
| REF CURSOR 1k, fast opt-ins vs oci8's strings | 661 µs | 799 µs | 1.21× | 1.10× | 1.22× (plain) | below 2× |
| 1k small LOBs, `Inline` vs `OCI_RETURN_LOBS` (same values) | 7.00 ms | 39.03 ms | 5.57× | 5.13× | – | **goal met** |
| 1k small LOBs, `Lob::contents()` vs `load()` (same values) | 7.67 ms | 40.23 ms | 5.24× | 0.74× | – | **goal met** |
| One 16 MiB BLOB, `contents()` vs `load()` | 12.78 ms | 6.57 ms | 0.51× | 0.39× | – | slower, at the floor |
| Insert 1k rows, `runMany()` vs a loop of executes | 2.91 ms | 16.22 ms | 5.57× | 4.36× | – | **goal met** |
| Insert 1k rows, `runMany()` vs a `FORALL` block | 2.91 ms | 2.81 ms | 0.96× | 0.79× | – | slower |
| Connect + close, Pool vs `oci_pconnect` | 1.3 µs | 1.1 µs | 0.87× | 0.12× | – | slower |
| Connect + 1-row query + close | 15.0 µs | 19.5 µs | 1.30× | 0.79× | – | below 2× |
| Logon + logoff, `standalone` vs `oci_new_connect` | 12.87 ms | 14.50 ms | 1.13× | 1.22× | – | below 2× |

"Same values" pits Core's default values against oci8 plus the PHP
conversions that give the same values; it is the comparison the spike's
*typed* shape made. "oci8's strings" is oci8 doing no conversion at all; the
spike's *plain* shape gave both drivers oci8's strings, which Core cannot
return (its closest are the fast opt-ins: int and float numbers, temporal
text).

Against the spike, Core now beats its ratios on the fetches and the REF
CURSOR, and stays below them on the Call (1.04–1.07× against 1.28×).

**Memory** (median RSS): Core 19.7 MB before connecting and 21.5 MB after,
against 29.1 and 50.6 MB for oci8 with Instant Client. The 10k fetch with
`all()` peaks at 40.9 MB against 70.8 MB, the 16 MiB BLOB at 54.4 MB
against 85.8 MB.

### FrankenPHP worker mode (ZTS)

8 worker threads, `wrk` 10 s per point, 2 rounds
([`output/frankenphp-zts.txt`](output/frankenphp-zts.txt)); requests per
second, both rounds:

| Workload | Connections | oci8 | Core, `connect()` per request | Core, held per worker |
|---|---|---|---|---|
| `scalar` | 1 | 4,992 / 6,019 | 4,945 / 5,564 | 5,418 / 5,001 |
| `scalar` | 8 | 8,869 / 9,035 | 9,679 / 10,550 | 9,912 / 10,526 |
| `scalar-txn` | 1 | 450 / 508 | 428 / 412 | 451 / 305 |
| `scalar-txn` | 8 | 1,971 / 1,357 | 1,540 / 1,074 | 1,302 / 1,001 |
| `cursor` | 8 | 996 / 1,228 | 1,645 / 1,721 | 1,525 / 1,464 |
| `fetch` | 1 | 23 / 26 | 45 / 46 | 47 / 47 |
| `fetch` | 8 | 70 / 70 | 127 / 144 | 128 / 136 |

- `connect()` per request now costs nothing measurable: `scalar` at 1
  connection is level with oci8 (it was about 20% behind in #64), because
  the Release makes no Round trip when no transaction is open (#78).
- The 10k fetch serves 1.8–2.1× oci8's requests and the REF CURSOR
  1.2–1.7×.
- `scalar-txn` (the spike's case) ends each request with a transaction
  open, so every Release rolls back. Core stays within the same range as
  oci8 (the spike's Pool collapsed to 134–1,100 req/s with a p50 of
  6–13 ms), but 8 open DML transactions contend in the database: p99 runs
  to 0.4–1.8 s and `wrk` reports timeouts, so read the order of magnitude,
  not the exact rate.

## Where the time goes

`ddev bench layers` times the same work through the Extension's classes
alone (*native*), Core's adapter (*driver*) and the public API (*core*), on
one Session, and counts Round trips
([`output/layers-nts.md`](output/layers-nts.md), the median of 3 runs):

| Work | native | driver | core | Round trips |
|---|---|---|---|---|
| Call 3 IN + 3 OUT | 15.8 µs (7.5 µs with no binds) | 16.3 µs | 17.1 µs `run()`, 18.6 µs `procedure()` | 1 |
| Fetch 10k × 9 | 15.5 ms | 14.9 ms | 15.4 ms `all()`, 15.6 ms `foreach` | 100 |
| REF CURSOR 1k × 5 | 1.02 ms | 1.06 ms | 1.04 ms | 12 |
| 1k small LOBs | 5.8 ms unread, 8.7 ms inline | – | 9.6 ms `contents()`, 8.7 ms inline | 10 |
| One 16 MiB BLOB | – | – | 12.8 ms `contents()`, 14.1 ms inline | 17, 2 inline (oci8: 2) |
| Connect + close | 1.2 µs | 1.3 µs | 1.5 µs | 0 (oci8: 0) |

Core's PHP layers cost little: within the noise of a fetch, 1.3 µs of a
`run()` Call and 2.8 µs of a `procedure()`. What #76–#79 fixed, and what
is left:

1. **Dates: fixed down to PHP's own cost.** A DATE or TIMESTAMP as
   `DateTimeImmutable` went through `format!`, a function lookup by name and
   a userland call (0.59 µs a value); the Extension now builds the object
   with ext/date's own C functions (#76). What remains, about 0.37 µs a
   value over the text, is PHP parsing and initialising the object: oci8
   plus `new DateTimeImmutable()` pays the same 0.38 µs. So `Object` is
   1.20× oci8 with the same values, but 0.48× oci8's raw strings (10.52 ms
   against 5.07 ms for 20k values), and no Extension change closes that.
   `TemporalMode::String` is 1.60× oci8's strings.
2. **Small LOBs: fixed** (#77). `readChunk()` allocated a zeroed 1 MiB or
   3 MiB buffer per call; a chunk is now sized by what is left, and
   `contents()` joins its chunks once. `Lob::contents()` on 1k small LOBs
   went from 0.74× to 5.24×.
3. **A large LOB: half fixed, still at the floor.** The fork's fix for
   end-of-response detection (C77: a data packet ending in byte 29 made the
   crate re-parse the whole response) roughly halved a 16 MiB read, and
   Core went from 0.39× to 0.51×. It still takes 17 Round trips (1 MiB
   each) and about 2,048 packets of the default 8 KiB SDU, where oci8 takes
   2 Round trips; read inline, one Round trip of 16 MiB is about 10%
   slower than `contents()` because the crate re-joins the response. A
   larger SDU helps: `(SDU=65535)` in the dsn read it 21–25% faster in an
   in-process A/B (12.0 ms to 9.3 ms, about 0.65× oci8), at up to 2 × SDU
   of buffers per Session (+70–90 kB RSS measured). The default stays
   Oracle Net's 8 KiB, as in Oracle's own clients
   ([Connecting](../docs/connecting.md#packet-size-sdu),
   [Known limitations](../docs/known-limitations.md#values)). Left: the
   crate's per-packet work and copies; CLOB UTF-16 decoding is a separate
   candidate.
4. **The Release Round trip: fixed** (#78). The Release rolls back only
   when the server reports an open transaction (or a call failed), so
   connect + close is CPU only: 1.3 µs against `oci_pconnect`'s 1.1 µs
   (0.12× before). A request with a query is 1.30× oci8.
5. **The Call: overhead cut, Round trip bound** (#79). Binds are matched
   once, the Call timeout is no longer set and cleared per execute, and
   Core caches validated Call shapes, so Core now adds 1.3 µs (`run()`) and
   2.8 µs (`procedure()`) over the Extension, and the Call is 1.04–1.07× oci8
   (0.67–0.90× before). A Call is one Round trip: an empty block takes
   7.5 µs, so 2× oci8 (9.2 µs) is out of reach on this setup. The lead left
   in the client: the crate clones its `CachedStatement` twice per execute
   and builds Metadata per bind (a fork patch, not scheduled).

`runMany()` against a `FORALL` block (0.96×) and a standalone logon (1.13×)
are one Round trip or one logon each on both sides: the server's time
dominates.

## Noise

Client and database share one machine. These runs were on a quiet machine
(load average 2–4.5, the benchmark itself included), yet the per-round
spreads in `summary-nts.md` stay wide (a Call: 16.5–34.7 µs), because a
Session's dedicated server process may land on an efficiency core for a
whole round. The speedups of the two pooled runs mostly agreed within 15%,
up to 30% for numbers `Float` (4.00× and 3.10×); the default fetch with
`all()` met 2× in both (2.05× and 2.32×), the fast opt-ins with `foreach`
against oci8's strings straddled it (1.82× and 2.13×). A third run, partly
overlapping another workload on the database, gave the same verdicts except
the Call by `procedure()` (0.83×): it sits at parity with oci8, on either
side of 1× from run to run. An earlier run, made
while Mutagen re-synced the growing `raw-*.jsonl` after every case, read
15–40% slower for both drivers and is left out; `run.php` now writes that
file once per round. Compare ratios, not absolute times, and rerun before
reading a change under 15%.
