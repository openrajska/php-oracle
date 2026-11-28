# Benchmark summary: nts

PHP 8.4.28 NTS, opcache; 20 rounds (full iterations), pooled from 2 runs (`raw-nts-b.jsonl`, `raw-nts-c.jsonl`); each (case, round) is a fresh process with `bench/php.ini` and one driver. A sample is one iteration: the Call or query, every row read into PHP, and freeing it. Speedup = oci8 median / Core median (> 1: Core is faster). A run of 10 rounds took 6 min; load average 4.15 3.95 3.31 at the start, 4.44 4.51 3.78 at the end. A run of 10 rounds took 7 min; load average 1.88 3.31 3.52 at the start, 3.94 4.16 3.92 at the end.

## Core against oci8

`same values`: both sides return identical PHP values (`crosscheck.php`). Otherwise oci8 returns its strings, the work it does by default.

| Comparison | Core | oci8 | Core median (per-round spread) | oci8 median (per-round spread) | Speedup | Spike | Verdict |
|---|---|---|---|---|---|---|---|
| Call 3 IN + 3 OUT, `procedure()` (same values) | `call.core.procedure` | `call.oci8.typed` | 17.6 µs (16.8 µs–34.7 µs) | 18.4 µs (17.5 µs–29.2 µs) | 1.04× | 1.28× (typed) | faster, below 2× |
| Call 3 IN + 3 OUT, `run()` block (same values) | `call.core.run` | `call.oci8.typed` | 17.2 µs (16.5 µs–26.4 µs) | 18.4 µs (17.5 µs–29.2 µs) | 1.07× | 1.28× (typed) | faster, below 2× |
| Call, `procedure()` vs oci8 strings | `call.core.procedure` | `call.oci8.raw` | 17.6 µs (16.8 µs–34.7 µs) | 17.3 µs (16.8 µs–29.7 µs) | 0.98× | 1.28× (plain) | **slower than oci8** |
| Fetch 10k, `all()` (same values) | `fetch.core.all` | `fetch.oci8.exact-all` | 14.35 ms (12.48 ms–42.40 ms) | 30.33 ms (25.35 ms–114.18 ms) | 2.11× | 1.73× (typed all) | **≥ 2×, goal met** |
| Fetch 10k, `foreach` (same values) | `fetch.core.row` | `fetch.oci8.exact-row` | 15.43 ms (13.26 ms–64.45 ms) | 28.20 ms (24.56 ms–85.73 ms) | 1.83× | 1.75× (typed row) | faster, below 2× |
| Fetch 10k, `all()` vs oci8 strings | `fetch.core.all` | `fetch.oci8.raw-all` | 14.35 ms (12.48 ms–42.40 ms) | 17.22 ms (14.50 ms–27.86 ms) | 1.20× | – | faster, below 2× |
| Fetch 10k, fast opt-ins, `all()` (same values) | `fetch.core.fast-all` | `fetch.oci8.float-all` | 8.13 ms (6.93 ms–23.59 ms) | 22.91 ms (18.34 ms–130.72 ms) | 2.82× | – | **≥ 2×, goal met** |
| Fetch 10k, fast opt-ins vs oci8 strings, `all()` | `fetch.core.fast-all` | `fetch.oci8.raw-all` | 8.13 ms (6.93 ms–23.59 ms) | 17.22 ms (14.50 ms–27.86 ms) | 2.12× | 1.69× (plain all) | **≥ 2×, goal met** |
| Fetch 10k, fast opt-ins vs oci8 strings, `foreach` | `fetch.core.fast-row` | `fetch.oci8.raw-row` | 9.11 ms (7.42 ms–34.92 ms) | 17.55 ms (14.85 ms–35.49 ms) | 1.93× | 1.73× (plain row) | faster, below 2× |
| Fetch 10k, `RowShape::List` vs oci8 `OCI_NUM` strings | `fetch.core.list-all` | `fetch.oci8.raw-num-all` | 13.56 ms (11.89 ms–31.36 ms) | 15.58 ms (13.60 ms–23.87 ms) | 1.15× | – | faster, below 2× |
| Fetch 10k, fetchSize / prefetch 1000 (same values) | `fetch.core.size1000-all` | `fetch.oci8.exact-prefetch1000-all` | 13.12 ms (11.88 ms–27.22 ms) | 27.68 ms (24.46 ms–57.79 ms) | 2.11× | – | **≥ 2×, goal met** |
| Numbers 10k × 4, `Exact` (same values) | `numbers.core.exact` | `numbers.oci8.exact` | 3.86 ms (3.36 ms–11.82 ms) | 14.15 ms (11.90 ms–39.91 ms) | 3.67× | – | **≥ 2×, goal met** |
| Numbers 10k × 4, `Float` (same values) | `numbers.core.float` | `numbers.oci8.float` | 3.97 ms (3.37 ms–12.97 ms) | 14.14 ms (11.81 ms–65.80 ms) | 3.56× | – | **≥ 2×, goal met** |
| Numbers 10k × 4, `Float` vs oci8 strings | `numbers.core.float` | `numbers.oci8.raw` | 3.97 ms (3.37 ms–12.97 ms) | 11.35 ms (8.74 ms–27.91 ms) | 2.86× | – | **≥ 2×, goal met** |
| Dates 10k × 2, `Object` (same values) | `dates.core.object` | `dates.oci8.exact` | 10.52 ms (8.59 ms–23.53 ms) | 12.64 ms (11.18 ms–22.97 ms) | 1.20× | – | faster, below 2× |
| Dates 10k × 2, `String` (same values) | `dates.core.string` | `dates.oci8.raw` | 3.17 ms (2.82 ms–7.16 ms) | 5.07 ms (4.58 ms–7.35 ms) | 1.60× | – | faster, below 2× |
| REF CURSOR 1k, `function()->cursor()->all()` (same values) | `cursor.core.function-all` | `cursor.oci8.exact-all` | 955.2 µs (829.8 µs–1.97 ms) | 1.32 ms (1.15 ms–2.03 ms) | 1.39× | 1.21× (typed all) | faster, below 2× |
| REF CURSOR 1k, `foreach` (same values) | `cursor.core.function-row` | `cursor.oci8.exact-row` | 1.00 ms (882.1 µs–1.32 ms) | 1.49 ms (1.28 ms–3.52 ms) | 1.49× | 1.27× (typed row) | faster, below 2× |
| REF CURSOR 1k, `run()` block (same values) | `cursor.core.run-all` | `cursor.oci8.exact-all` | 950.7 µs (832.8 µs–1.60 ms) | 1.32 ms (1.15 ms–2.03 ms) | 1.39× | – | faster, below 2× |
| REF CURSOR 1k, fast opt-ins (same values) | `cursor.core.fast-all` | `cursor.oci8.float-all` | 661.0 µs (564.0 µs–1.14 ms) | 1.05 ms (836.4 µs–3.38 ms) | 1.59× | – | faster, below 2× |
| REF CURSOR 1k, fast opt-ins vs oci8 strings | `cursor.core.fast-all` | `cursor.oci8.raw-all` | 661.0 µs (564.0 µs–1.14 ms) | 799.3 µs (683.8 µs–1.40 ms) | 1.21× | 1.22× (plain all) | faster, below 2× |
| LOBs 1k × (CLOB + BLOB), `Inline` vs `OCI_RETURN_LOBS` (same values) | `lobs.core.inline` | `lobs.oci8.inline` | 7.00 ms (6.16 ms–16.16 ms) | 39.03 ms (34.24 ms–72.59 ms) | 5.57× | – | **≥ 2×, goal met** |
| LOBs 1k × (CLOB + BLOB), `Lob::contents()` vs `load()` (same values) | `lobs.core.locator` | `lobs.oci8.locator` | 7.67 ms (6.61 ms–24.55 ms) | 40.23 ms (34.92 ms–67.63 ms) | 5.24× | – | **≥ 2×, goal met** |
| One 16 MiB BLOB, `contents()` vs `load()` (same values) | `largelob.core.contents` | `largelob.oci8.load` | 12.78 ms (11.20 ms–38.38 ms) | 6.57 ms (5.35 ms–9.60 ms) | 0.51× | – | **slower than oci8** |
| Insert 1k rows, `runMany()` vs an oci8 loop (same values) | `insert.core.runmany` | `insert.oci8.loop` | 2.91 ms (2.49 ms–4.26 ms) | 16.22 ms (14.48 ms–25.94 ms) | 5.57× | – | **≥ 2×, goal met** |
| Insert 1k rows, `runMany()` vs oci8 FORALL (same values) | `insert.core.runmany` | `insert.oci8.forall` | 2.91 ms (2.49 ms–4.26 ms) | 2.81 ms (2.30 ms–5.45 ms) | 0.96× | – | **slower than oci8** |
| Connect + close, Pool vs `oci_pconnect` (same values) | `connect.core.pool` | `connect.oci8.pconnect` | 1.3 µs (1.2 µs–2.6 µs) | 1.1 µs (1.0 µs–2.4 µs) | 0.87× | – | **slower than oci8** |
| Connect + 1-row query + close, Pool vs `oci_pconnect` (same values) | `request.core.pool` | `request.oci8.pconnect` | 15.0 µs (13.8 µs–32.0 µs) | 19.5 µs (18.0 µs–60.1 µs) | 1.30× | – | faster, below 2× |
| Logon + logoff, `standalone` vs `oci_new_connect` (same values) | `logon.core.standalone` | `logon.oci8.new` | 12.87 ms (10.71 ms–16.67 ms) | 14.50 ms (12.68 ms–25.87 ms) | 1.13× | – | faster, below 2× |

## Every case

| Case | Driver | Iterations × rounds | Median | p95 | Per-round medians | Rows/s |
|---|---|---|---|---|---|---|
| `call.core.procedure` | core | 10000 × 20 | 17.6 µs | 33.2 µs | 16.8 µs–34.7 µs | – |
| `call.core.run` | core | 10000 × 20 | 17.2 µs | 29.5 µs | 16.5 µs–26.4 µs | – |
| `call.oci8.typed` | oci8 | 10000 × 20 | 18.4 µs | 32.0 µs | 17.5 µs–29.2 µs | – |
| `call.oci8.raw` | oci8 | 10000 × 20 | 17.3 µs | 30.8 µs | 16.8 µs–29.7 µs | – |
| `fetch.core.all` | core | 50 × 20 | 14.35 ms | 36.21 ms | 12.48 ms–42.40 ms | 696,892 |
| `fetch.core.fast-all` | core | 50 × 20 | 8.13 ms | 21.00 ms | 6.93 ms–23.59 ms | 1,229,567 |
| `fetch.oci8.raw-all` | oci8 | 50 × 20 | 17.22 ms | 30.79 ms | 14.50 ms–27.86 ms | 580,662 |
| `fetch.oci8.exact-all` | oci8 | 50 × 20 | 30.33 ms | 76.39 ms | 25.35 ms–114.18 ms | 329,714 |
| `fetch.oci8.float-all` | oci8 | 50 × 20 | 22.91 ms | 89.47 ms | 18.34 ms–130.72 ms | 436,573 |
| `fetch.core.row` | core | 50 × 20 | 15.43 ms | 42.70 ms | 13.26 ms–64.45 ms | 648,277 |
| `fetch.core.fast-row` | core | 50 × 20 | 9.11 ms | 23.21 ms | 7.42 ms–34.92 ms | 1,098,121 |
| `fetch.oci8.raw-row` | oci8 | 50 × 20 | 17.55 ms | 37.20 ms | 14.85 ms–35.49 ms | 569,906 |
| `fetch.oci8.exact-row` | oci8 | 50 × 20 | 28.20 ms | 70.31 ms | 24.56 ms–85.73 ms | 354,652 |
| `fetch.core.list-all` | core | 50 × 20 | 13.56 ms | 27.53 ms | 11.89 ms–31.36 ms | 737,328 |
| `fetch.oci8.raw-num-all` | oci8 | 50 × 20 | 15.58 ms | 24.65 ms | 13.60 ms–23.87 ms | 641,989 |
| `fetch.core.size1000-all` | core | 50 × 20 | 13.12 ms | 22.49 ms | 11.88 ms–27.22 ms | 762,408 |
| `fetch.oci8.exact-prefetch1000-all` | oci8 | 50 × 20 | 27.68 ms | 64.86 ms | 24.46 ms–57.79 ms | 361,234 |
| `numbers.core.exact` | core | 50 × 20 | 3.86 ms | 10.86 ms | 3.36 ms–11.82 ms | 2,591,583 |
| `numbers.core.float` | core | 50 × 20 | 3.97 ms | 10.46 ms | 3.37 ms–12.97 ms | 2,516,767 |
| `numbers.oci8.raw` | oci8 | 50 × 20 | 11.35 ms | 23.96 ms | 8.74 ms–27.91 ms | 881,164 |
| `numbers.oci8.exact` | oci8 | 50 × 20 | 14.15 ms | 45.22 ms | 11.90 ms–39.91 ms | 706,925 |
| `numbers.oci8.float` | oci8 | 50 × 20 | 14.14 ms | 50.57 ms | 11.81 ms–65.80 ms | 707,321 |
| `dates.core.object` | core | 50 × 20 | 10.52 ms | 25.75 ms | 8.59 ms–23.53 ms | 950,339 |
| `dates.core.string` | core | 50 × 20 | 3.17 ms | 5.74 ms | 2.82 ms–7.16 ms | 3,151,138 |
| `dates.oci8.raw` | oci8 | 50 × 20 | 5.07 ms | 7.40 ms | 4.58 ms–7.35 ms | 1,974,200 |
| `dates.oci8.exact` | oci8 | 50 × 20 | 12.64 ms | 22.23 ms | 11.18 ms–22.97 ms | 791,109 |
| `cursor.core.function-all` | core | 300 × 20 | 955.2 µs | 1.70 ms | 829.8 µs–1.97 ms | 1,046,909 |
| `cursor.core.function-row` | core | 300 × 20 | 1.00 ms | 1.40 ms | 882.1 µs–1.32 ms | 998,558 |
| `cursor.core.run-all` | core | 300 × 20 | 950.7 µs | 1.52 ms | 832.8 µs–1.60 ms | 1,051,889 |
| `cursor.core.fast-all` | core | 300 × 20 | 661.0 µs | 1.11 ms | 564.0 µs–1.14 ms | 1,512,905 |
| `cursor.oci8.raw-all` | oci8 | 300 × 20 | 799.3 µs | 1.38 ms | 683.8 µs–1.40 ms | 1,251,067 |
| `cursor.oci8.exact-all` | oci8 | 300 × 20 | 1.32 ms | 2.01 ms | 1.15 ms–2.03 ms | 755,081 |
| `cursor.oci8.float-all` | oci8 | 300 × 20 | 1.05 ms | 2.17 ms | 836.4 µs–3.38 ms | 950,681 |
| `cursor.oci8.raw-row` | oci8 | 300 × 20 | 900.3 µs | 2.36 ms | 719.1 µs–2.99 ms | 1,110,719 |
| `cursor.oci8.exact-row` | oci8 | 300 × 20 | 1.49 ms | 3.67 ms | 1.28 ms–3.52 ms | 668,967 |
| `lobs.core.inline` | core | 50 × 20 | 7.00 ms | 13.68 ms | 6.16 ms–16.16 ms | 142,759 |
| `lobs.core.locator` | core | 50 × 20 | 7.67 ms | 16.53 ms | 6.61 ms–24.55 ms | 130,378 |
| `lobs.oci8.inline` | oci8 | 50 × 20 | 39.03 ms | 98.63 ms | 34.24 ms–72.59 ms | 25,620 |
| `lobs.oci8.locator` | oci8 | 50 × 20 | 40.23 ms | 86.93 ms | 34.92 ms–67.63 ms | 24,859 |
| `largelob.core.contents` | core | 30 × 20 | 12.78 ms | 27.21 ms | 11.20 ms–38.38 ms | – |
| `largelob.oci8.load` | oci8 | 30 × 20 | 6.57 ms | 9.67 ms | 5.35 ms–9.60 ms | – |
| `insert.core.runmany` | core | 50 × 20 | 2.91 ms | 5.80 ms | 2.49 ms–4.26 ms | 343,214 |
| `insert.oci8.loop` | oci8 | 50 × 20 | 16.22 ms | 71.70 ms | 14.48 ms–25.94 ms | 61,653 |
| `insert.oci8.forall` | oci8 | 50 × 20 | 2.81 ms | 5.90 ms | 2.30 ms–5.45 ms | 356,329 |
| `connect.core.pool` | core | 5000 × 20 | 1.3 µs | 2.5 µs | 1.2 µs–2.6 µs | – |
| `connect.oci8.pconnect` | oci8 | 5000 × 20 | 1.1 µs | 2.4 µs | 1.0 µs–2.4 µs | – |
| `request.core.pool` | core | 5000 × 20 | 15.0 µs | 32.7 µs | 13.8 µs–32.0 µs | – |
| `request.oci8.pconnect` | oci8 | 5000 × 20 | 19.5 µs | 51.8 µs | 18.0 µs–60.1 µs | – |
| `logon.core.standalone` | core | 30 × 20 | 12.87 ms | 22.38 ms | 10.71 ms–16.67 ms | – |
| `logon.oci8.new` | oci8 | 30 × 20 | 14.50 ms | 26.58 ms | 12.68 ms–25.87 ms | – |

## Memory (kB, median over rounds)

`rss_start` is before connecting, `rss_connected` after, `hwm_end` the process's peak RSS (Rust or Instant Client heaps included), `zend_peak` PHP's own allocator peak.

| Case | Driver | rss_start | rss_connected | hwm_end | zend_peak |
|---|---|---|---|---|---|
| `call.core.procedure` | core | 19658 | 21544 | 21938 | 2048 |
| `call.core.run` | core | 19652 | 21544 | 21822 | 2048 |
| `call.oci8.typed` | oci8 | 29076 | 50648 | 51092 | 2048 |
| `call.oci8.raw` | oci8 | 29064 | 50626 | 50994 | 2048 |
| `fetch.core.all` | core | 19652 | 21546 | 40936 | 20480 |
| `fetch.core.fast-all` | core | 19656 | 21552 | 34136 | 14336 |
| `fetch.oci8.raw-all` | oci8 | 29064 | 50628 | 64156 | 14336 |
| `fetch.oci8.exact-all` | oci8 | 29070 | 50628 | 70756 | 20480 |
| `fetch.oci8.float-all` | oci8 | 29066 | 50638 | 64390 | 14336 |
| `fetch.core.row` | core | 19648 | 21540 | 22196 | 2048 |
| `fetch.core.fast-row` | core | 19654 | 21544 | 22136 | 2048 |
| `fetch.oci8.raw-row` | oci8 | 29068 | 50628 | 51028 | 2048 |
| `fetch.oci8.exact-row` | oci8 | 29074 | 50630 | 51232 | 2048 |
| `fetch.core.list-all` | core | 19658 | 21544 | 34808 | 14336 |
| `fetch.oci8.raw-num-all` | oci8 | 29070 | 50644 | 58038 | 8192 |
| `fetch.core.size1000-all` | core | 19656 | 21544 | 42038 | 20480 |
| `fetch.oci8.exact-prefetch1000-all` | oci8 | 29066 | 50628 | 71280 | 20480 |
| `numbers.core.exact` | core | 19650 | 21548 | 27044 | 8192 |
| `numbers.core.float` | core | 19650 | 21544 | 26928 | 6144 |
| `numbers.oci8.raw` | oci8 | 29070 | 50632 | 57054 | 8192 |
| `numbers.oci8.exact` | oci8 | 29064 | 50632 | 57280 | 8192 |
| `numbers.oci8.float` | oci8 | 29064 | 50628 | 57282 | 8192 |
| `dates.core.object` | core | 19652 | 21546 | 33992 | 14336 |
| `dates.core.string` | core | 19656 | 21548 | 27428 | 8192 |
| `dates.oci8.raw` | oci8 | 29070 | 50636 | 56306 | 8192 |
| `dates.oci8.exact` | oci8 | 29064 | 50624 | 63682 | 14336 |
| `cursor.core.function-all` | core | 19656 | 21544 | 22542 | 2048 |
| `cursor.core.function-row` | core | 19654 | 21540 | 21972 | 2048 |
| `cursor.core.run-all` | core | 19656 | 21544 | 22490 | 2048 |
| `cursor.core.fast-all` | core | 19650 | 21550 | 22276 | 2048 |
| `cursor.oci8.raw-all` | oci8 | 29064 | 50620 | 51292 | 2048 |
| `cursor.oci8.exact-all` | oci8 | 29064 | 50620 | 51624 | 2048 |
| `cursor.oci8.float-all` | oci8 | 29070 | 50630 | 51330 | 2048 |
| `cursor.oci8.raw-row` | oci8 | 29068 | 50630 | 51000 | 2048 |
| `cursor.oci8.exact-row` | oci8 | 29064 | 50624 | 51074 | 2048 |
| `lobs.core.inline` | core | 19656 | 21542 | 25798 | 4096 |
| `lobs.core.locator` | core | 19656 | 21544 | 26474 | 4096 |
| `lobs.oci8.inline` | oci8 | 29068 | 50630 | 54950 | 4096 |
| `lobs.oci8.locator` | oci8 | 29072 | 50640 | 54956 | 4096 |
| `largelob.core.contents` | core | 19660 | 21544 | 54390 | 49156 |
| `largelob.oci8.load` | oci8 | 29070 | 50632 | 85782 | 34824 |
| `insert.core.runmany` | core | 19654 | 21544 | 22704 | 2048 |
| `insert.oci8.loop` | oci8 | 29068 | 50632 | 50760 | 2048 |
| `insert.oci8.forall` | oci8 | 29072 | 50632 | 50792 | 2048 |
| `connect.core.pool` | core | 19650 | 21544 | 21640 | 2048 |
| `connect.oci8.pconnect` | oci8 | 29070 | 50632 | 51650 | 2048 |
| `request.core.pool` | core | 19656 | 21550 | 21812 | 2048 |
| `request.oci8.pconnect` | oci8 | 29068 | 50630 | 51854 | 2048 |
| `logon.core.standalone` | core | 19658 | 21550 | 21770 | 2048 |
| `logon.oci8.new` | oci8 | 29072 | 50632 | 51274 | 2048 |
