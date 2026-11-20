# Benchmark summary: nts

PHP 8.4.28 NTS; 10 rounds; each (case, driver, round) is a fresh `php -n` process. Time per iteration is one Call (a) or one Call/query plus fetching every row (b, c), including the row → zval conversion. Speedup = oci8 median / rajska_oracle median.

| Workload | Mode | Shape | arraysize | gated | oci8 median | rajska median | oci8 p95 | rajska p95 | rajska per-round medians | speedup | verdict |
|---|---|---|---|---|---|---|---|---|---|---|---|
| scalar | - | plain | default | yes | 17.2 µs | 13.4 µs | 22.1 µs | 17.1 µs | 12.9 µs–14.8 µs | 1.28× | pass |
| scalar | - | typed | default | no | 18.2 µs | 14.2 µs | 25.5 µs | 18.8 µs | 13.8 µs–15.6 µs | 1.28× | recorded |
| fetch | row | plain | default | yes | 14.87 ms (672,703 rows/s) | 8.60 ms (1,162,502 rows/s) | 18.37 ms | 9.51 ms | 8.52 ms–9.24 ms | 1.73× | pass |
| fetch | all | plain | default | yes | 14.64 ms (682,888 rows/s) | 8.66 ms (1,154,355 rows/s) | 17.47 ms | 9.91 ms | 8.58 ms–9.74 ms | 1.69× | pass |
| fetch | row | typed | default | no | 24.40 ms (409,891 rows/s) | 13.96 ms (716,527 rows/s) | 28.07 ms | 17.12 ms | 13.68 ms–17.02 ms | 1.75× | recorded |
| fetch | all | typed | default | no | 24.74 ms (404,161 rows/s) | 14.27 ms (700,719 rows/s) | 29.87 ms | 17.23 ms | 13.93 ms–15.50 ms | 1.73× | recorded |
| fetch | row | plain | 1000 | no | 14.20 ms (704,263 rows/s) | 7.89 ms (1,266,751 rows/s) | 15.03 ms | 8.38 ms | 7.80 ms–8.36 ms | 1.80× | recorded |
| fetch | all | plain | 1000 | no | 14.07 ms (710,934 rows/s) | 8.31 ms (1,203,706 rows/s) | 16.19 ms | 9.58 ms | 7.87 ms–9.07 ms | 1.69× | recorded |
| cursor | row | plain | default | yes | 0.70 ms (1,421,620 rows/s) | 0.57 ms (1,755,849 rows/s) | 0.76 ms | 0.71 ms | 0.56 ms–0.69 ms | 1.24× | pass |
| cursor | all | plain | default | yes | 0.69 ms (1,453,476 rows/s) | 0.56 ms (1,771,335 rows/s) | 0.77 ms | 0.64 ms | 0.56 ms–0.62 ms | 1.22× | pass |
| cursor | row | typed | default | no | 1.15 ms (872,562 rows/s) | 0.90 ms (1,111,875 rows/s) | 1.37 ms | 1.02 ms | 0.88 ms–1.00 ms | 1.27× | recorded |
| cursor | all | typed | default | no | 1.09 ms (917,125 rows/s) | 0.90 ms (1,113,473 rows/s) | 1.29 ms | 1.03 ms | 0.88 ms–0.96 ms | 1.21× | recorded |

## Memory (kB, median over rounds; recorded, not gated)

`rss_start` is before connecting, `rss_connected` after, `hwm_end` is the process peak RSS (it includes Rust and OCI heaps), `zend_peak` is PHP's own allocator peak.

| Workload | Mode | Shape | arraysize | driver | rss_start | rss_connected | hwm_end | zend_peak |
|---|---|---|---|---|---|---|---|---|
| scalar | - | plain | default | oci8 | 25558 | 47158 | 47766 | 2048 |
| scalar | - | plain | default | rajska | 15916 | 17472 | 17704 | 2048 |
| scalar | - | typed | default | oci8 | 25542 | 47140 | 47812 | 2048 |
| scalar | - | typed | default | rajska | 15912 | 17456 | 17754 | 2048 |
| fetch | row | plain | default | oci8 | 25544 | 47138 | 47538 | 2048 |
| fetch | row | plain | default | rajska | 15922 | 17468 | 17812 | 2048 |
| fetch | all | plain | default | oci8 | 25548 | 47158 | 61110 | 14336 |
| fetch | all | plain | default | rajska | 15908 | 17456 | 31314 | 14336 |
| fetch | row | typed | default | oci8 | 25538 | 47152 | 47680 | 2048 |
| fetch | row | typed | default | rajska | 15914 | 17454 | 17924 | 2048 |
| fetch | all | typed | default | oci8 | 25530 | 47136 | 67608 | 20480 |
| fetch | all | typed | default | rajska | 15910 | 17460 | 35784 | 20480 |
| fetch | row | plain | 1000 | oci8 | 25540 | 47142 | 48070 | 2048 |
| fetch | row | plain | 1000 | rajska | 15914 | 17460 | 18670 | 2048 |
| fetch | all | plain | 1000 | oci8 | 25534 | 47134 | 61614 | 14336 |
| fetch | all | plain | 1000 | rajska | 15918 | 17464 | 32182 | 14336 |
| cursor | row | plain | default | oci8 | 25562 | 47168 | 47552 | 2048 |
| cursor | row | plain | default | rajska | 15910 | 17464 | 17712 | 2048 |
| cursor | all | plain | default | oci8 | 25536 | 47136 | 48176 | 2048 |
| cursor | all | plain | default | rajska | 15916 | 17456 | 18336 | 2048 |
| cursor | row | typed | default | oci8 | 25540 | 47146 | 47578 | 2048 |
| cursor | row | typed | default | rajska | 15910 | 17470 | 17780 | 2048 |
| cursor | all | typed | default | oci8 | 25544 | 47150 | 48526 | 2048 |
| cursor | all | typed | default | rajska | 15916 | 17466 | 18638 | 2048 |
