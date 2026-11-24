# Benchmark summary: nts-rerun

PHP 8.4.28 NTS, opcache; 20 rounds (full iterations), pooled from 2 runs (`raw-post-1.jsonl`, `raw-post-2.jsonl`); each (case, round) is a fresh process with `bench/php.ini` and one driver. A sample is one iteration: the Call or query, every row read into PHP, and freeing it. Speedup = oci8 median / Core median (> 1: Core is faster). A run of 10 rounds took 1 min; load average 3.70 6.02 5.82 at the start, 2.26 4.65 5.34 at the end. A run of 10 rounds took 2 min; load average 2.56 4.68 5.34 at the start, 3.54 4.35 5.13 at the end.

## Core against oci8

`same values`: both sides return identical PHP values (`crosscheck.php`). Otherwise oci8 returns its strings, the work it does by default.

| Comparison | Core | oci8 | Core median (per-round spread) | oci8 median (per-round spread) | Speedup | Spike | Verdict |
|---|---|---|---|---|---|---|---|
| Dates 10k × 2, `Object` (same values) | `dates.core.object` | `dates.oci8.exact` | 9.33 ms (8.53 ms–12.72 ms) | 13.36 ms (10.87 ms–26.24 ms) | 1.43× | – | faster, below 2× |
| Dates 10k × 2, `String` (same values) | `dates.core.string` | `dates.oci8.raw` | 3.15 ms (2.80 ms–4.97 ms) | 5.22 ms (4.56 ms–9.27 ms) | 1.66× | – | faster, below 2× |
| LOBs 1k × (CLOB + BLOB), `Inline` vs `OCI_RETURN_LOBS` (same values) | `lobs.core.inline` | `lobs.oci8.inline` | 6.52 ms (5.54 ms–13.28 ms) | 39.51 ms (34.29 ms–60.66 ms) | 6.06× | – | **≥ 2×, goal met** |
| LOBs 1k × (CLOB + BLOB), `Lob::contents()` vs `load()` (same values) | `lobs.core.locator` | `lobs.oci8.locator` | 7.45 ms (6.66 ms–13.08 ms) | 38.08 ms (34.25 ms–60.18 ms) | 5.11× | – | **≥ 2×, goal met** |
| One 16 MiB BLOB, `contents()` vs `load()` (same values) | `largelob.core.contents` | `largelob.oci8.load` | 12.33 ms (10.83 ms–24.78 ms) | 6.21 ms (5.38 ms–11.74 ms) | 0.50× | – | **slower than oci8** |
| Insert 1k rows, `runMany()` vs an oci8 loop (same values) | `insert.core.runmany` | `insert.oci8.loop` | 2.77 ms (2.20 ms–4.08 ms) | 15.73 ms (14.32 ms–20.02 ms) | 5.69× | – | **≥ 2×, goal met** |
| Insert 1k rows, `runMany()` vs oci8 FORALL (same values) | `insert.core.runmany` | `insert.oci8.forall` | 2.77 ms (2.20 ms–4.08 ms) | 2.60 ms (1.98 ms–3.47 ms) | 0.94× | – | **slower than oci8** |
| Connect + close, Pool vs `oci_pconnect` (same values) | `connect.core.pool` | `connect.oci8.pconnect` | 1.3 µs (1.3 µs–1.5 µs) | 1.1 µs (1.0 µs–1.3 µs) | 0.81× | – | **slower than oci8** |
| Connect + 1-row query + close, Pool vs `oci_pconnect` (same values) | `request.core.pool` | `request.oci8.pconnect` | 14.0 µs (13.5 µs–29.1 µs) | 18.4 µs (17.6 µs–41.2 µs) | 1.31× | – | faster, below 2× |

## Every case

| Case | Driver | Iterations × rounds | Median | p95 | Per-round medians | Rows/s |
|---|---|---|---|---|---|---|
| `dates.core.object` | core | 50 × 20 | 9.33 ms | 12.71 ms | 8.53 ms–12.72 ms | 1,072,223 |
| `dates.core.string` | core | 50 × 20 | 3.15 ms | 4.87 ms | 2.80 ms–4.97 ms | 3,176,969 |
| `dates.oci8.raw` | oci8 | 50 × 20 | 5.22 ms | 8.59 ms | 4.56 ms–9.27 ms | 1,917,232 |
| `dates.oci8.exact` | oci8 | 50 × 20 | 13.36 ms | 26.76 ms | 10.87 ms–26.24 ms | 748,337 |
| `lobs.core.inline` | core | 50 × 20 | 6.52 ms | 12.88 ms | 5.54 ms–13.28 ms | 153,299 |
| `lobs.core.locator` | core | 50 × 20 | 7.45 ms | 12.92 ms | 6.66 ms–13.08 ms | 134,215 |
| `lobs.oci8.inline` | oci8 | 50 × 20 | 39.51 ms | 65.93 ms | 34.29 ms–60.66 ms | 25,311 |
| `lobs.oci8.locator` | oci8 | 50 × 20 | 38.08 ms | 63.93 ms | 34.25 ms–60.18 ms | 26,263 |
| `largelob.core.contents` | core | 30 × 20 | 12.33 ms | 21.96 ms | 10.83 ms–24.78 ms | – |
| `largelob.oci8.load` | oci8 | 30 × 20 | 6.21 ms | 10.79 ms | 5.38 ms–11.74 ms | – |
| `insert.core.runmany` | core | 50 × 20 | 2.77 ms | 4.31 ms | 2.20 ms–4.08 ms | 361,487 |
| `insert.oci8.loop` | oci8 | 50 × 20 | 15.73 ms | 42.20 ms | 14.32 ms–20.02 ms | 63,581 |
| `insert.oci8.forall` | oci8 | 50 × 20 | 2.60 ms | 4.31 ms | 1.98 ms–3.47 ms | 384,785 |
| `connect.core.pool` | core | 5000 × 20 | 1.3 µs | 1.6 µs | 1.3 µs–1.5 µs | – |
| `connect.oci8.pconnect` | oci8 | 5000 × 20 | 1.1 µs | 1.4 µs | 1.0 µs–1.3 µs | – |
| `request.core.pool` | core | 5000 × 20 | 14.0 µs | 30.7 µs | 13.5 µs–29.1 µs | – |
| `request.oci8.pconnect` | oci8 | 5000 × 20 | 18.4 µs | 39.9 µs | 17.6 µs–41.2 µs | – |

## Memory (kB, median over rounds)

`rss_start` is before connecting, `rss_connected` after, `hwm_end` the process's peak RSS (Rust or Instant Client heaps included), `zend_peak` PHP's own allocator peak.

| Case | Driver | rss_start | rss_connected | hwm_end | zend_peak |
|---|---|---|---|---|---|
| `dates.core.object` | core | 19652 | 21542 | 33990 | 14336 |
| `dates.core.string` | core | 19660 | 21552 | 27440 | 8192 |
| `dates.oci8.raw` | oci8 | 29070 | 50634 | 56300 | 8192 |
| `dates.oci8.exact` | oci8 | 29066 | 50626 | 63678 | 14336 |
| `lobs.core.inline` | core | 19656 | 21544 | 25504 | 4096 |
| `lobs.core.locator` | core | 19654 | 21546 | 26464 | 4096 |
| `lobs.oci8.inline` | oci8 | 29068 | 50632 | 54950 | 4096 |
| `lobs.oci8.locator` | oci8 | 29068 | 50628 | 54944 | 4096 |
| `largelob.core.contents` | core | 19660 | 21552 | 54382 | 49156 |
| `largelob.oci8.load` | oci8 | 29068 | 50648 | 85700 | 34824 |
| `insert.core.runmany` | core | 19652 | 21544 | 22704 | 2048 |
| `insert.oci8.loop` | oci8 | 29076 | 50650 | 50778 | 2048 |
| `insert.oci8.forall` | oci8 | 29070 | 50632 | 50792 | 2048 |
| `connect.core.pool` | core | 19654 | 21544 | 21640 | 2048 |
| `connect.oci8.pconnect` | oci8 | 29070 | 50632 | 51644 | 2048 |
| `request.core.pool` | core | 19656 | 21540 | 21812 | 2048 |
| `request.oci8.pconnect` | oci8 | 29064 | 50632 | 51852 | 2048 |
