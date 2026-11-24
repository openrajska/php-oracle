# Benchmark summary: nts

PHP 8.4.28 NTS, opcache; 10 rounds (full iterations); each (case, round) is a fresh process with `bench/php.ini` and one driver. A sample is one iteration: the Call or query, every row read into PHP, and freeing it. Speedup = oci8 median / Core median (> 1: Core is faster). Run took 8 min; load average 4.28 5.77 5.02 at the start, 4.11 4.56 4.73 at the end.

## Core against oci8

`same values`: both sides return identical PHP values (`crosscheck.php`). Otherwise oci8 returns its strings, the work it does by default.

| Comparison | Core | oci8 | Core median (per-round spread) | oci8 median (per-round spread) | Speedup | Spike | Verdict |
|---|---|---|---|---|---|---|---|
| Call 3 IN + 3 OUT, `procedure()` (same values) | `call.core.procedure` | `call.oci8.typed` | 33.7 µs (23.1 µs–52.2 µs) | 22.6 µs (18.2 µs–43.3 µs) | 0.67× | 1.28× (typed) | **slower than oci8** |
| Call 3 IN + 3 OUT, `run()` block (same values) | `call.core.run` | `call.oci8.typed` | 25.0 µs (21.6 µs–50.5 µs) | 22.6 µs (18.2 µs–43.3 µs) | 0.90× | 1.28× (typed) | **slower than oci8** |
| Call, `procedure()` vs oci8 strings | `call.core.procedure` | `call.oci8.raw` | 33.7 µs (23.1 µs–52.2 µs) | 24.2 µs (17.1 µs–39.6 µs) | 0.72× | 1.28× (plain) | **slower than oci8** |
| Fetch 10k, `all()` (same values) | `fetch.core.all` | `fetch.oci8.exact-all` | 27.97 ms (24.57 ms–39.24 ms) | 33.72 ms (28.41 ms–46.50 ms) | 1.21× | 1.73× (typed all) | faster, below 2× |
| Fetch 10k, `foreach` (same values) | `fetch.core.row` | `fetch.oci8.exact-row` | 27.15 ms (23.32 ms–41.66 ms) | 33.11 ms (29.10 ms–46.74 ms) | 1.22× | 1.75× (typed row) | faster, below 2× |
| Fetch 10k, `all()` vs oci8 strings | `fetch.core.all` | `fetch.oci8.raw-all` | 27.97 ms (24.57 ms–39.24 ms) | 21.28 ms (18.32 ms–27.31 ms) | 0.76× | – | **slower than oci8** |
| Fetch 10k, fast opt-ins, `all()` (same values) | `fetch.core.fast-all` | `fetch.oci8.float-all` | 11.95 ms (10.10 ms–17.05 ms) | 25.78 ms (21.35 ms–33.35 ms) | 2.16× | – | **≥ 2×, goal met** |
| Fetch 10k, fast opt-ins vs oci8 strings, `all()` | `fetch.core.fast-all` | `fetch.oci8.raw-all` | 11.95 ms (10.10 ms–17.05 ms) | 21.28 ms (18.32 ms–27.31 ms) | 1.78× | 1.69× (plain all) | faster, below 2× |
| Fetch 10k, fast opt-ins vs oci8 strings, `foreach` | `fetch.core.fast-row` | `fetch.oci8.raw-row` | 13.18 ms (12.28 ms–17.99 ms) | 21.60 ms (18.32 ms–30.81 ms) | 1.64× | 1.73× (plain row) | faster, below 2× |
| Fetch 10k, `RowShape::List` vs oci8 `OCI_NUM` strings | `fetch.core.list-all` | `fetch.oci8.raw-num-all` | 23.74 ms (18.21 ms–29.48 ms) | 19.50 ms (16.20 ms–26.56 ms) | 0.82× | – | **slower than oci8** |
| Fetch 10k, fetchSize / prefetch 1000 (same values) | `fetch.core.size1000-all` | `fetch.oci8.exact-prefetch1000-all` | 24.55 ms (20.88 ms–32.91 ms) | 32.40 ms (27.67 ms–46.18 ms) | 1.32× | – | faster, below 2× |
| Numbers 10k × 4, `Exact` (same values) | `numbers.core.exact` | `numbers.oci8.exact` | 4.57 ms (3.60 ms–6.40 ms) | 19.46 ms (15.89 ms–27.57 ms) | 4.26× | – | **≥ 2×, goal met** |
| Numbers 10k × 4, `Float` (same values) | `numbers.core.float` | `numbers.oci8.float` | 5.45 ms (3.87 ms–6.86 ms) | 17.57 ms (14.62 ms–27.46 ms) | 3.23× | – | **≥ 2×, goal met** |
| Numbers 10k × 4, `Float` vs oci8 strings | `numbers.core.float` | `numbers.oci8.raw` | 5.45 ms (3.87 ms–6.86 ms) | 13.85 ms (11.48 ms–20.52 ms) | 2.54× | – | **≥ 2×, goal met** |
| Dates 10k × 2, `Object` (same values) | `dates.core.object` | `dates.oci8.exact` | 22.73 ms (16.98 ms–33.69 ms) | 18.74 ms (15.35 ms–27.08 ms) | 0.82× | – | **slower than oci8** |
| Dates 10k × 2, `String` (same values) | `dates.core.string` | `dates.oci8.raw` | 7.09 ms (5.43 ms–9.74 ms) | 6.51 ms (5.14 ms–9.83 ms) | 0.92× | – | **slower than oci8** |
| REF CURSOR 1k, `function()->cursor()->all()` (same values) | `cursor.core.function-all` | `cursor.oci8.exact-all` | 1.69 ms (1.49 ms–2.44 ms) | 2.00 ms (1.57 ms–2.68 ms) | 1.18× | 1.21× (typed all) | faster, below 2× |
| REF CURSOR 1k, `foreach` (same values) | `cursor.core.function-row` | `cursor.oci8.exact-row` | 1.76 ms (1.59 ms–2.39 ms) | 1.77 ms (1.49 ms–2.13 ms) | 1.01× | 1.27× (typed row) | faster, below 2× |
| REF CURSOR 1k, `run()` block (same values) | `cursor.core.run-all` | `cursor.oci8.exact-all` | 1.78 ms (1.47 ms–2.27 ms) | 2.00 ms (1.57 ms–2.68 ms) | 1.12× | – | faster, below 2× |
| REF CURSOR 1k, fast opt-ins (same values) | `cursor.core.fast-all` | `cursor.oci8.float-all` | 1.02 ms (911.4 µs–1.46 ms) | 1.39 ms (1.10 ms–1.70 ms) | 1.37× | – | faster, below 2× |
| REF CURSOR 1k, fast opt-ins vs oci8 strings | `cursor.core.fast-all` | `cursor.oci8.raw-all` | 1.02 ms (911.4 µs–1.46 ms) | 1.12 ms (918.4 µs–1.59 ms) | 1.10× | 1.22× (plain all) | faster, below 2× |
| LOBs 1k × (CLOB + BLOB), `Inline` vs `OCI_RETURN_LOBS` (same values) | `lobs.core.inline` | `lobs.oci8.inline` | 8.77 ms (6.75 ms–10.48 ms) | 44.99 ms (35.46 ms–69.83 ms) | 5.13× | – | **≥ 2×, goal met** |
| LOBs 1k × (CLOB + BLOB), `Lob::contents()` vs `load()` (same values) | `lobs.core.locator` | `lobs.oci8.locator` | 60.64 ms (57.58 ms–81.14 ms) | 44.74 ms (38.15 ms–57.65 ms) | 0.74× | – | **slower than oci8** |
| One 16 MiB BLOB, `contents()` vs `load()` (same values) | `largelob.core.contents` | `largelob.oci8.load` | 19.69 ms (14.28 ms–33.33 ms) | 7.72 ms (5.90 ms–12.82 ms) | 0.39× | – | **below the 2×-slower floor** |
| Insert 1k rows, `runMany()` vs an oci8 loop (same values) | `insert.core.runmany` | `insert.oci8.loop` | 4.16 ms (3.68 ms–5.03 ms) | 18.13 ms (16.14 ms–24.86 ms) | 4.36× | – | **≥ 2×, goal met** |
| Insert 1k rows, `runMany()` vs oci8 FORALL (same values) | `insert.core.runmany` | `insert.oci8.forall` | 4.16 ms (3.68 ms–5.03 ms) | 3.30 ms (2.76 ms–4.60 ms) | 0.79× | – | **slower than oci8** |
| Connect + close, Pool vs `oci_pconnect` (same values) | `connect.core.pool` | `connect.oci8.pconnect` | 10.6 µs (8.5 µs–18.7 µs) | 1.3 µs (1.1 µs–2.8 µs) | 0.12× | – | **below the 2×-slower floor** |
| Connect + 1-row query + close, Pool vs `oci_pconnect` (same values) | `request.core.pool` | `request.oci8.pconnect` | 31.6 µs (24.8 µs–72.0 µs) | 24.9 µs (19.9 µs–41.6 µs) | 0.79× | – | **slower than oci8** |
| Logon + logoff, `standalone` vs `oci_new_connect` (same values) | `logon.core.standalone` | `logon.oci8.new` | 13.64 ms (11.82 ms–16.54 ms) | 16.66 ms (13.43 ms–23.79 ms) | 1.22× | – | faster, below 2× |

## Every case

| Case | Driver | Iterations × rounds | Median | p95 | Per-round medians | Rows/s |
|---|---|---|---|---|---|---|
| `call.core.procedure` | core | 10000 × 10 | 33.7 µs | 62.9 µs | 23.1 µs–52.2 µs | – |
| `call.core.run` | core | 10000 × 10 | 25.0 µs | 65.5 µs | 21.6 µs–50.5 µs | – |
| `call.oci8.typed` | oci8 | 10000 × 10 | 22.6 µs | 51.0 µs | 18.2 µs–43.3 µs | – |
| `call.oci8.raw` | oci8 | 10000 × 10 | 24.2 µs | 46.7 µs | 17.1 µs–39.6 µs | – |
| `fetch.core.all` | core | 50 × 10 | 27.97 ms | 44.52 ms | 24.57 ms–39.24 ms | 357,472 |
| `fetch.core.fast-all` | core | 50 × 10 | 11.95 ms | 18.12 ms | 10.10 ms–17.05 ms | 836,478 |
| `fetch.oci8.raw-all` | oci8 | 50 × 10 | 21.28 ms | 30.30 ms | 18.32 ms–27.31 ms | 469,951 |
| `fetch.oci8.exact-all` | oci8 | 50 × 10 | 33.72 ms | 56.58 ms | 28.41 ms–46.50 ms | 296,568 |
| `fetch.oci8.float-all` | oci8 | 50 × 10 | 25.78 ms | 48.37 ms | 21.35 ms–33.35 ms | 387,888 |
| `fetch.core.row` | core | 50 × 10 | 27.15 ms | 44.00 ms | 23.32 ms–41.66 ms | 368,275 |
| `fetch.core.fast-row` | core | 50 × 10 | 13.18 ms | 20.08 ms | 12.28 ms–17.99 ms | 758,439 |
| `fetch.oci8.raw-row` | oci8 | 50 × 10 | 21.60 ms | 33.42 ms | 18.32 ms–30.81 ms | 462,937 |
| `fetch.oci8.exact-row` | oci8 | 50 × 10 | 33.11 ms | 50.73 ms | 29.10 ms–46.74 ms | 302,027 |
| `fetch.core.list-all` | core | 50 × 10 | 23.74 ms | 32.00 ms | 18.21 ms–29.48 ms | 421,200 |
| `fetch.oci8.raw-num-all` | oci8 | 50 × 10 | 19.50 ms | 30.73 ms | 16.20 ms–26.56 ms | 512,852 |
| `fetch.core.size1000-all` | core | 50 × 10 | 24.55 ms | 35.64 ms | 20.88 ms–32.91 ms | 407,362 |
| `fetch.oci8.exact-prefetch1000-all` | oci8 | 50 × 10 | 32.40 ms | 56.70 ms | 27.67 ms–46.18 ms | 308,599 |
| `numbers.core.exact` | core | 50 × 10 | 4.57 ms | 6.57 ms | 3.60 ms–6.40 ms | 2,189,471 |
| `numbers.core.float` | core | 50 × 10 | 5.45 ms | 7.41 ms | 3.87 ms–6.86 ms | 1,835,977 |
| `numbers.oci8.raw` | oci8 | 50 × 10 | 13.85 ms | 25.41 ms | 11.48 ms–20.52 ms | 721,810 |
| `numbers.oci8.exact` | oci8 | 50 × 10 | 19.46 ms | 33.09 ms | 15.89 ms–27.57 ms | 513,779 |
| `numbers.oci8.float` | oci8 | 50 × 10 | 17.57 ms | 37.53 ms | 14.62 ms–27.46 ms | 569,040 |
| `dates.core.object` | core | 50 × 10 | 22.73 ms | 38.56 ms | 16.98 ms–33.69 ms | 439,959 |
| `dates.core.string` | core | 50 × 10 | 7.09 ms | 9.90 ms | 5.43 ms–9.74 ms | 1,410,017 |
| `dates.oci8.raw` | oci8 | 50 × 10 | 6.51 ms | 10.03 ms | 5.14 ms–9.83 ms | 1,536,132 |
| `dates.oci8.exact` | oci8 | 50 × 10 | 18.74 ms | 31.01 ms | 15.35 ms–27.08 ms | 533,733 |
| `cursor.core.function-all` | core | 300 × 10 | 1.69 ms | 2.67 ms | 1.49 ms–2.44 ms | 590,091 |
| `cursor.core.function-row` | core | 300 × 10 | 1.76 ms | 2.65 ms | 1.59 ms–2.39 ms | 569,060 |
| `cursor.core.run-all` | core | 300 × 10 | 1.78 ms | 2.61 ms | 1.47 ms–2.27 ms | 561,932 |
| `cursor.core.fast-all` | core | 300 × 10 | 1.02 ms | 1.50 ms | 911.4 µs–1.46 ms | 983,888 |
| `cursor.oci8.raw-all` | oci8 | 300 × 10 | 1.12 ms | 1.72 ms | 918.4 µs–1.59 ms | 892,089 |
| `cursor.oci8.exact-all` | oci8 | 300 × 10 | 2.00 ms | 2.90 ms | 1.57 ms–2.68 ms | 499,546 |
| `cursor.oci8.float-all` | oci8 | 300 × 10 | 1.39 ms | 1.92 ms | 1.10 ms–1.70 ms | 719,800 |
| `cursor.oci8.raw-row` | oci8 | 300 × 10 | 1.09 ms | 1.55 ms | 900.1 µs–1.43 ms | 920,251 |
| `cursor.oci8.exact-row` | oci8 | 300 × 10 | 1.77 ms | 2.26 ms | 1.49 ms–2.13 ms | 563,483 |
| `lobs.core.inline` | core | 50 × 10 | 8.77 ms | 11.47 ms | 6.75 ms–10.48 ms | 114,054 |
| `lobs.core.locator` | core | 50 × 10 | 60.64 ms | 93.29 ms | 57.58 ms–81.14 ms | 16,490 |
| `lobs.oci8.inline` | oci8 | 50 × 10 | 44.99 ms | 90.54 ms | 35.46 ms–69.83 ms | 22,229 |
| `lobs.oci8.locator` | oci8 | 50 × 10 | 44.74 ms | 78.58 ms | 38.15 ms–57.65 ms | 22,349 |
| `largelob.core.contents` | core | 30 × 10 | 19.69 ms | 35.91 ms | 14.28 ms–33.33 ms | – |
| `largelob.oci8.load` | oci8 | 30 × 10 | 7.72 ms | 13.22 ms | 5.90 ms–12.82 ms | – |
| `insert.core.runmany` | core | 50 × 10 | 4.16 ms | 6.40 ms | 3.68 ms–5.03 ms | 240,459 |
| `insert.oci8.loop` | oci8 | 50 × 10 | 18.13 ms | 139.37 ms | 16.14 ms–24.86 ms | 55,158 |
| `insert.oci8.forall` | oci8 | 50 × 10 | 3.30 ms | 5.86 ms | 2.76 ms–4.60 ms | 303,243 |
| `connect.core.pool` | core | 5000 × 10 | 10.6 µs | 22.5 µs | 8.5 µs–18.7 µs | – |
| `connect.oci8.pconnect` | oci8 | 5000 × 10 | 1.3 µs | 2.9 µs | 1.1 µs–2.8 µs | – |
| `request.core.pool` | core | 5000 × 10 | 31.6 µs | 80.4 µs | 24.8 µs–72.0 µs | – |
| `request.oci8.pconnect` | oci8 | 5000 × 10 | 24.9 µs | 44.5 µs | 19.9 µs–41.6 µs | – |
| `logon.core.standalone` | core | 30 × 10 | 13.64 ms | 19.64 ms | 11.82 ms–16.54 ms | – |
| `logon.oci8.new` | oci8 | 30 × 10 | 16.66 ms | 28.33 ms | 13.43 ms–23.79 ms | – |

## Memory (kB, median over rounds)

`rss_start` is before connecting, `rss_connected` after, `hwm_end` the process's peak RSS (Rust or Instant Client heaps included), `zend_peak` PHP's own allocator peak.

| Case | Driver | rss_start | rss_connected | hwm_end | zend_peak |
|---|---|---|---|---|---|
| `call.core.procedure` | core | 19672 | 21508 | 21944 | 2048 |
| `call.core.run` | core | 19678 | 21514 | 21838 | 2048 |
| `call.oci8.typed` | oci8 | 29018 | 50592 | 51048 | 2048 |
| `call.oci8.raw` | oci8 | 29020 | 50586 | 50970 | 2048 |
| `fetch.core.all` | core | 19680 | 21516 | 40820 | 20480 |
| `fetch.core.fast-all` | core | 19672 | 21506 | 33994 | 14336 |
| `fetch.oci8.raw-all` | oci8 | 29016 | 50582 | 64122 | 14336 |
| `fetch.oci8.exact-all` | oci8 | 29020 | 50588 | 70726 | 22528 |
| `fetch.oci8.float-all` | oci8 | 29012 | 50582 | 64342 | 14336 |
| `fetch.core.row` | core | 19680 | 21528 | 22136 | 2048 |
| `fetch.core.fast-row` | core | 19674 | 21520 | 22056 | 2048 |
| `fetch.oci8.raw-row` | oci8 | 29012 | 50584 | 50984 | 2048 |
| `fetch.oci8.exact-row` | oci8 | 29012 | 50590 | 51182 | 2048 |
| `fetch.core.list-all` | core | 19674 | 21510 | 34700 | 14336 |
| `fetch.oci8.raw-num-all` | oci8 | 29018 | 50564 | 57980 | 8192 |
| `fetch.core.size1000-all` | core | 19668 | 21512 | 42292 | 20480 |
| `fetch.oci8.exact-prefetch1000-all` | oci8 | 29016 | 50588 | 71250 | 22528 |
| `numbers.core.exact` | core | 19678 | 21522 | 26938 | 8192 |
| `numbers.core.float` | core | 19672 | 21512 | 26832 | 6144 |
| `numbers.oci8.raw` | oci8 | 29016 | 50556 | 57004 | 8192 |
| `numbers.oci8.exact` | oci8 | 29016 | 50582 | 57252 | 8192 |
| `numbers.oci8.float` | oci8 | 29018 | 50590 | 57256 | 8192 |
| `dates.core.object` | core | 19674 | 21520 | 33974 | 14336 |
| `dates.core.string` | core | 19668 | 21510 | 27392 | 8192 |
| `dates.oci8.raw` | oci8 | 29012 | 50574 | 56262 | 8192 |
| `dates.oci8.exact` | oci8 | 29006 | 50580 | 63650 | 14336 |
| `cursor.core.function-all` | core | 19672 | 21512 | 22552 | 2048 |
| `cursor.core.function-row` | core | 19664 | 21508 | 21988 | 2048 |
| `cursor.core.run-all` | core | 19670 | 21516 | 22498 | 2048 |
| `cursor.core.fast-all` | core | 19664 | 21510 | 22208 | 2048 |
| `cursor.oci8.raw-all` | oci8 | 29014 | 50562 | 51266 | 2048 |
| `cursor.oci8.exact-all` | oci8 | 29004 | 50560 | 51584 | 2048 |
| `cursor.oci8.float-all` | oci8 | 29016 | 50578 | 51282 | 2048 |
| `cursor.oci8.raw-row` | oci8 | 29008 | 50560 | 50940 | 2048 |
| `cursor.oci8.exact-row` | oci8 | 29016 | 50586 | 51026 | 2048 |
| `lobs.core.inline` | core | 19670 | 21520 | 25884 | 4096 |
| `lobs.core.locator` | core | 19674 | 21524 | 29246 | 4096 |
| `lobs.oci8.inline` | oci8 | 29018 | 50584 | 54904 | 4096 |
| `lobs.oci8.locator` | oci8 | 29020 | 50600 | 54930 | 4096 |
| `largelob.core.contents` | core | 19676 | 21514 | 43778 | 23560 |
| `largelob.oci8.load` | oci8 | 29012 | 50574 | 85666 | 34824 |
| `insert.core.runmany` | core | 19672 | 21514 | 22724 | 2048 |
| `insert.oci8.loop` | oci8 | 29014 | 50586 | 50714 | 2048 |
| `insert.oci8.forall` | oci8 | 29012 | 50572 | 50748 | 2048 |
| `connect.core.pool` | core | 19676 | 21512 | 21608 | 2048 |
| `connect.oci8.pconnect` | oci8 | 29016 | 50572 | 51596 | 2048 |
| `request.core.pool` | core | 19672 | 21518 | 21712 | 2048 |
| `request.oci8.pconnect` | oci8 | 29012 | 50564 | 51796 | 2048 |
| `logon.core.standalone` | core | 19670 | 21508 | 21728 | 2048 |
| `logon.oci8.new` | oci8 | 29020 | 50588 | 51228 | 2048 |
