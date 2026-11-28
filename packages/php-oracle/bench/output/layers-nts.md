# Where Core's time goes (NTS, `ddev bench layers`, 3 runs)

native = the Extension's classes alone, driver = Core's Extension adapter,
core = the public API; all three on one Session. Each run is one process
(5 rounds); the median is the middle one of the 3 runs' medians, the range
their lowest and highest. One run's Session can sit on a slower core than
another's, so read the proportions within a run rather than absolute times.
Load average about 3 during the runs (2026-11-24).

## Call 3 IN + 3 OUT (block) (2000 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native | 15.8 µs | 15.6 µs–16.0 µs | 1 |
| driver | 16.3 µs | 16.0 µs–16.5 µs | 1 |
| core run() | 17.1 µs | 17.1 µs–17.3 µs | 1 |
| core procedure() | 18.6 µs | 18.2 µs–18.7 µs | 1 |
| native, autocommit off | 15.6 µs | 15.5 µs–16.2 µs | 1 |
| native, no binds (begin null; end;) | 7.5 µs | 7.2 µs–7.5 µs | 1 |

## Fetch 10k × 9, default values (20 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native | 15.51 ms | 14.41 ms–15.71 ms | 100 |
| driver | 14.90 ms | 14.12 ms–16.57 ms | 100 |
| core all() | 15.41 ms | 13.86 ms–17.45 ms | 100 |
| core foreach | 15.64 ms | 14.30 ms–16.03 ms | 100 |
| driver, every row kept | 16.97 ms | 15.92 ms–18.13 ms | 100 |
| native, fast opt-ins | 8.63 ms | 8.04 ms–8.92 ms | 100 |
| native, list | 14.39 ms | 13.55 ms–14.97 ms | 100 |
| native, fetchSize 1000 | 15.22 ms | 13.18 ms–15.40 ms | 10 |

## Fetch 10k, one column each (20 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native id NUMBER(10) | 2.06 ms | 1.74 ms–2.46 ms | 100 |
| native amount NUMBER(12,2) | 2.36 ms | 1.94 ms–2.69 ms | 100 |
| native ratio BINARY_DOUBLE | 1.81 ms | 1.53 ms–2.14 ms | 100 |
| native code VARCHAR2 | 2.55 ms | 1.89 ms–2.56 ms | 100 |
| native created_at DATE object | 6.24 ms | 4.81 ms–6.28 ms | 100 |
| native created_at DATE string | 2.46 ms | 1.93 ms–2.53 ms | 100 |
| native updated_at TIMESTAMP object | 6.23 ms | 5.20 ms–6.58 ms | 100 |
| native updated_at TIMESTAMP string | 2.54 ms | 2.11 ms–2.73 ms | 100 |
| native constant 1 (protocol floor) | 1.97 ms | 1.65 ms–2.07 ms | 100 |

## REF CURSOR 1k × 5 (200 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native | 1.02 ms | 867.7 µs–1.04 ms | 12 |
| driver | 1.06 ms | 880.3 µs–1.08 ms | 12 |
| core run() | 1.04 ms | 898.7 µs–1.08 ms | 12 |
| core function() | 1.03 ms | 899.9 µs–1.06 ms | 12 |
| native, fast opt-ins | 684.9 µs | 580.0 µs–709.3 µs | 12 |

## LOBs 1k × (CLOB + BLOB) (20 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native inline | 8.72 ms | 7.21 ms–9.24 ms | 10 |
| native locators, unread | 5.83 ms | 5.00 ms–6.62 ms | 10 |
| core inline | 8.72 ms | 7.48 ms–10.28 ms | 10 |
| core Lob::contents() | 9.58 ms | 7.51 ms–12.19 ms | 10 |

## One 16 MiB BLOB (10 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| core contents() | 12.75 ms | 10.38 ms–17.50 ms | 17 |
| core stream_get_contents(stream()) | 18.11 ms | 12.68 ms–22.42 ms | 17 |
| core inline | 14.08 ms | 12.48 ms–21.33 ms | 2 |

## Connect + close (Pool) (2000 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native | 1.2 µs | 1.1 µs–1.4 µs | n/a (new Session) |
| driver | 1.3 µs | 1.2 µs–1.5 µs | n/a (new Session) |
| core | 1.5 µs | 1.3 µs–1.7 µs | n/a (new Session) |
| core, ConnectionConfig built each time | 2.5 µs | 2.2 µs–2.8 µs | n/a (new Session) |

## Insert 1k rows (20 iterations × 5 rounds)

| Layer | Median | Range over the 3 runs | Round trips per iteration |
|---|---|---|---|
| native executeMany | 2.95 ms | 2.93 ms–3.42 ms | 1 |
| core runMany() | 3.00 ms | 2.73 ms–3.44 ms | 1 |
