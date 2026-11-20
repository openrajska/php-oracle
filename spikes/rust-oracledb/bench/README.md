# Benchmark: rajska_oracle vs oci8

Throwaway code for the Benchmark ticket
([#13](https://github.com/openrajska/php-oracle/issues/13)) of the
rust-oracledb spike. It compares a minimal `rajska_oracle` (`ext-php-rs` 0.16
over `oracledb` 26.0.0-beta.6, `php-ext/src/bench.rs`) with oci8 3.4.1 over
Instant Client on the three workloads fixed by the Acceptance criteria ticket
([#6](https://github.com/openrajska/php-oracle/issues/6)).

## Workloads

| | Workload | SQL | Measured per iteration |
|---|---|---|---|
| (a) | A Call with scalar binds | `fx_scalar.echo_out`: 3 IN (NUMBER, VARCHAR2 multibyte, DATE) and 3 OUT | Latency of one Call: parse, bind, execute, read the OUT values |
| (b) | A fetch of 10k rows | `select … from fx_bench_rows`: 10,000 rows × 9 columns (integer and decimal NUMBER, BINARY_DOUBLE, VARCHAR2 ASCII and multibyte, a nullable VARCHAR2, DATE, TIMESTAMP(6)) | Query plus fetching every row into PHP arrays |
| (c) | A Call returning a REF CURSOR | `:rc := fx_cursor.items(1000)`: 1,000 rows × 5 columns | The Call plus fetching every row of the REF CURSOR into PHP arrays |

Fetch modes: `row` is one PHP call per row (`oci_fetch_array` /
`Statement::fetch`), `all` is one call for every row (`oci_fetch_all` /
`Statement::fetchAll`).

Shapes:

- **plain** (gated): both drivers return the same PHP values, the ones oci8
  returns: NUMBER, DATE, TIMESTAMP and BINARY_DOUBLE as strings in oci8's
  formats, NULL as null. `crosscheck.php` asserts the values are identical
  before anything is timed, on every workload, shape and mode.
- **typed** (recorded): int for integer NUMBER columns, float for
  BINARY_DOUBLE, DateTimeImmutable for DATE / TIMESTAMP, exact strings for
  other NUMBERs. oci8 gets there with a PHP conversion per value, which is what
  Core would do over oci8. rajska_oracle converts natively.

## Methodology

- **Isolation**: every (case, driver, round) is a fresh `php -n` process that
  loads only its own extension, so neither driver's libraries count in the
  other's memory. The Connection is opened once, before warm-up.
- **Warm-up, then iterations**: (a) 1,000 + 10,000 Calls; (b) 5 + 50 fetches;
  (c) 50 + 300 Calls. Warm-up fills the statement caches on both sides.
- **Rounds**: 10, interleaved. oci8 runs first on odd rounds and rajska_oracle
  first on even rounds, so drift (thermal, database caches) hits both alike.
- **Median**: over every timed iteration of every round (so 100,000 samples
  for (a)). Each iteration is timed on its own with `hrtime()`. The table also
  gives p95 and the spread of the per-round medians.
- **Speedup** = oci8 median / rajska_oracle median, so above 1 means
  rajska_oracle is faster. Verdict per #6: below 0.5 fails the floor, 0.5 to 1
  is a condition, 1 or more passes, and 2 or more meets the target.
- **Fetch sizes**: the gated runs keep each driver's defaults (oci8 prefetch
  100; `oracledb` prefetch 2, fetch array size 100). A recorded run sets both to
  1,000.
- **NTS × ZTS**: the same matrix runs twice. NTS uses PHP 8.4 in the ddev web
  container. ZTS uses the PHP 8.4 ZTS CLI from FrankenPHP's official image,
  with oci8 and rajska_oracle built for ZTS (`zts/Dockerfile`). Concurrency is
  measured separately under FrankenPHP worker mode (below).
- **Memory** (recorded, not gated): RSS before and after connecting and the
  process's peak RSS (`VmHWM`, which includes the Rust and OCI heaps), plus
  PHP's own allocator peak.
- **Build**: `rajska_oracle` in release with `lto = "fat"` and
  `codegen-units = 1`, as it would ship.
- **Machine**: Apple M5 (10 cores, 16 GB) under OrbStack. The client and
  Oracle Free 26ai (`gvenzl/oracle-free:23.26.3-faststart`) share the machine,
  so the network round trip is a local bridge (about 13 µs for a Call).
  Absolute numbers are for this machine only; the ratios are what the gate
  reads.

### ZTS concurrency (FrankenPHP)

`zts/load.sh` starts FrankenPHP 1.13 in worker mode with 8 worker threads and
drives it with `wrk` for 10 s per point (after 2 s of warm-up), at 1 and 8
concurrent connections, in two rounds with the driver order reversed:

- `oci8`: one Connection per worker thread, for the worker's lifetime;
- `rajska`: the same, with a `rajska_oracle` Connection;
- `rajska-pool`: one process-wide `oracledb` Pool (`max` = 8, `min` and
  `increment` = 1 because of
  [rust-oracledb#97](https://github.com/oracle/rust-oracledb/issues/97)). Each
  request acquires a Connection and gives it back when it ends. This is the
  design the extension would use under FrankenPHP.

Workloads: (a) `scalar`, (b) `fetch` and (c) `cursor` in the `all` mode and
plain shape, plus `scalar-txn`: an uncommitted INSERT into `fx_batch_rows` with
a random id, the scalar Call, and then the release. oci8 and `rajska` roll back
explicitly. `rajska-pool` leaves the rollback to the Pool's release, which
holds the Pool mutex during that round trip (`end_request`, found in
[Probe one Pool shared across threads](https://github.com/openrajska/php-oracle/issues/11)).
`rajska-pool-rb` is the workaround: it rolls back explicitly before the release.
oci8's scalar Call runs with `OCI_NO_AUTO_COMMIT` here: its default
(`OCI_COMMIT_ON_SUCCESS`) would commit the INSERT.

## Run

```bash
# NTS, in ddev (fixtures installed with `ddev fixtures`)
ddev exec bash -s <<'EOF'
cd spikes/rust-oracledb/php-ext && cargo build --release --locked
cd ../bench
php -n -d extension=oci8.so -d extension=/var/cache/cargo-target/release/librajska_oracle.so crosscheck.php
php run.php tag=nts rounds=10
php -n -d extension=/var/cache/cargo-target/release/librajska_oracle.so breakdown.php
EOF

# ZTS: image, build, cross-check, matrix, then FrankenPHP
docker build -t php-oracle-bench-zts spikes/rust-oracledb/bench/zts
spikes/rust-oracledb/bench/zts/run.sh 'cd spikes/rust-oracledb/php-ext && cargo build --release --locked'
spikes/rust-oracledb/bench/zts/run.sh 'cd spikes/rust-oracledb/bench && php -n -d extension=oci8.so -d extension=/var/cache/cargo-target/release/librajska_oracle.so crosscheck.php'
spikes/rust-oracledb/bench/zts/run.sh 'cd spikes/rust-oracledb/bench && php run.php tag=zts-cli rounds=10'
spikes/rust-oracledb/bench/zts/run.sh spikes/rust-oracledb/bench/zts/load.sh
```

## Output

- `output/summary-nts.md`, `output/summary-zts-cli.md`: the matrix (medians,
  p95, per-round spread, speedup, verdict, memory).
- `output/frankenphp-zts.txt`: one line per `wrk` run (its oci8 `scalar-txn`
  lines are invalid, see the note in the file).
- `output/frankenphp-zts-txn.txt`: the `scalar-txn` rerun, with the
  explicit-rollback workaround.
- `output/breakdown-nts.txt`: rajska_oracle's fetch time split into protocol
  plus decoding and the zval conversion.
- `output/raw-*.jsonl` (not committed): every sample, one JSON line per process.

## Results

All three gates pass in NTS and in ZTS, and no workload reaches the 2× target.
Speedup is oci8 median / rajska_oracle median, plain shape.

| Workload | NTS | ZTS (CLI) | Distance to the 2× target |
|---|---|---|---|
| (a) Call, scalar IN/OUT | 1.28× (17.2 → 13.4 µs) | 1.34× | Bound by the round trip: 2× would need 8.6 µs per Call |
| (b) fetch 10k rows | 1.73× row, 1.69× all | 1.75× / 1.78× | About 60% of rajska_oracle's time is the zval conversion (`breakdown-nts.txt`) |
| (c) REF CURSOR, 1k rows | 1.24× row, 1.22× all | 1.26× / 1.25× | Conversion is about 38%; the rest is the Call and two fetch round trips |

- **Typed shape** (recorded): 1.73× to 1.81× on (b), 1.21× to 1.31× on (a) and (c).
  DateTimeImmutable via `date_create_immutable` per value is the costly part
  (76% of (b) typed is conversion).
- **Fetch size 1000** (recorded): 1.69× to 1.85× on (b); both drivers gain about 5–10%.
- **Memory** (recorded): about 17 MB RSS per rajska_oracle process after
  connecting, against 47 MB for oci8 with Instant Client. (b) `all` peaks at 31 MB
  against 61 MB. PHP's own allocator peak is the same for both (same arrays).
- **FrankenPHP, 8 threads**: without an open transaction, the Pool costs
  nothing. `scalar` gives 10.1k–13.0k req/s for all three arrangements; (c)
  gives 2.0k–2.5k for oci8 against 2.3k–2.9k for rajska_oracle; (b) gives
  about 151 for oci8 against 265–279 for rajska_oracle (1.8×). With an open
  transaction at release (`scalar-txn`), the Pool's p50 rises to 6.4–12.9 ms
  against 1.5–1.7 ms for a dedicated Connection: the rollback runs under the
  Pool mutex, so the releases queue. An explicit `rollback()` before the release
  brings it back to 1.2–2.6 ms. oci8 runs at 1.5–2.0 ms.
- **Caveat**: client and database share one machine, so a round trip costs
  about 13 µs. Over a real network, round trips dominate (a) and (c) and their
  ratios move toward parity. (b) keeps more of its lead, because the conversion
  is client CPU.
