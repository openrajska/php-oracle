# Benchmark summary: zts-cli

PHP 8.4.28 ZTS; 10 rounds; each (case, driver, round) is a fresh `php -n` process. Time per iteration is one Call (a) or one Call/query plus fetching every row (b, c), including the row → zval conversion. Speedup = oci8 median / rajska_oracle median.

| Workload | Mode | Shape | arraysize | gated | oci8 median | rajska median | oci8 p95 | rajska p95 | rajska per-round medians | speedup | verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|
| scalar | - | plain | default | yes | 17.8 µs | 13.2 µs | 30.2 µs | 20.4 µs | 12.9 µs–15.2 µs | 1.34× | pass |
| scalar | - | typed | default | no | 18.5 µs | 14.1 µs | 27.2 µs | 22.6 µs | 13.7 µs–17.1 µs | 1.31× | recorded |
| fetch | row | plain | default | yes | 15.97 ms (626,309 rows/s) | 9.15 ms (1,093,360 rows/s) | 20.02 ms | 11.81 ms | 8.78 ms–11.61 ms | 1.75× | pass |
| fetch | all | plain | default | yes | 16.80 ms (595,136 rows/s) | 9.46 ms (1,057,107 rows/s) | 22.70 ms | 13.66 ms | 8.76 ms–12.41 ms | 1.78× | pass |
| fetch | row | typed | default | no | 25.93 ms (385,702 rows/s) | 14.29 ms (699,967 rows/s) | 32.90 ms | 17.09 ms | 13.96 ms–15.90 ms | 1.81× | recorded |
| fetch | all | typed | default | no | 25.63 ms (390,148 rows/s) | 14.29 ms (699,602 rows/s) | 33.21 ms | 18.25 ms | 13.97 ms–18.16 ms | 1.79× | recorded |
| fetch | row | plain | 1000 | no | 15.26 ms (655,165 rows/s) | 8.23 ms (1,214,737 rows/s) | 17.82 ms | 9.20 ms | 8.01 ms–9.05 ms | 1.85× | recorded |
| fetch | all | plain | 1000 | no | 15.04 ms (664,956 rows/s) | 8.26 ms (1,211,265 rows/s) | 16.95 ms | 9.45 ms | 7.99 ms–9.25 ms | 1.82× | recorded |
| cursor | row | plain | default | yes | 0.74 ms (1,349,821 rows/s) | 0.59 ms (1,697,120 rows/s) | 0.83 ms | 0.68 ms | 0.58 ms–0.67 ms | 1.26× | pass |
| cursor | all | plain | default | yes | 0.72 ms (1,386,831 rows/s) | 0.58 ms (1,738,737 rows/s) | 1.19 ms | 0.65 ms | 0.56 ms–0.63 ms | 1.25× | pass |
| cursor | row | typed | default | no | 1.19 ms (840,433 rows/s) | 0.91 ms (1,097,912 rows/s) | 1.52 ms | 1.10 ms | 0.88 ms–1.07 ms | 1.31× | recorded |
| cursor | all | typed | default | no | 1.19 ms (842,129 rows/s) | 0.91 ms (1,095,107 rows/s) | 1.47 ms | 1.06 ms | 0.87 ms–1.01 ms | 1.30× | recorded |

## Memory (kB, median over rounds; recorded, not gated)

`rss_start` is before connecting, `rss_connected` after, `hwm_end` is the process peak RSS (it includes Rust and OCI heaps), `zend_peak` is PHP's own allocator peak.

| Workload | Mode | Shape | arraysize | driver | rss_start | rss_connected | hwm_end | zend_peak |
|---|---|---|---|---|---|---|---|---|
| scalar | - | plain | default | oci8 | 34252 | 55856 | 56400 | 2048 |
| scalar | - | plain | default | rajska | 24750 | 26002 | 26242 | 2048 |
| scalar | - | typed | default | oci8 | 34248 | 55866 | 56538 | 2048 |
| scalar | - | typed | default | rajska | 24742 | 26000 | 26368 | 2048 |
| fetch | row | plain | default | oci8 | 34246 | 55862 | 56200 | 2048 |
| fetch | row | plain | default | rajska | 24746 | 26002 | 26352 | 2048 |
| fetch | all | plain | default | oci8 | 34250 | 55858 | 69700 | 14336 |
| fetch | all | plain | default | rajska | 24732 | 26000 | 39870 | 14336 |
| fetch | row | typed | default | oci8 | 34248 | 55838 | 56366 | 2048 |
| fetch | row | typed | default | rajska | 24748 | 26002 | 26538 | 2048 |
| fetch | all | typed | default | oci8 | 34248 | 55860 | 76316 | 20480 |
| fetch | all | typed | default | rajska | 24742 | 26000 | 44402 | 20480 |
| fetch | row | plain | 1000 | oci8 | 34244 | 55860 | 56724 | 2048 |
| fetch | row | plain | 1000 | rajska | 24748 | 26006 | 27224 | 2048 |
| fetch | all | plain | 1000 | oci8 | 34248 | 55832 | 70200 | 14336 |
| fetch | all | plain | 1000 | rajska | 24736 | 26002 | 40726 | 14336 |
| cursor | row | plain | default | oci8 | 34248 | 55860 | 56174 | 2048 |
| cursor | row | plain | default | rajska | 24748 | 25998 | 26248 | 2048 |
| cursor | all | plain | default | oci8 | 34250 | 55856 | 56820 | 2048 |
| cursor | all | plain | default | rajska | 24744 | 26004 | 26860 | 2048 |
| cursor | row | typed | default | oci8 | 34254 | 55864 | 56296 | 2048 |
| cursor | row | typed | default | rajska | 24732 | 25994 | 26368 | 2048 |
| cursor | all | typed | default | oci8 | 34254 | 55860 | 57238 | 2048 |
| cursor | all | typed | default | rajska | 24752 | 26006 | 27252 | 2048 |
