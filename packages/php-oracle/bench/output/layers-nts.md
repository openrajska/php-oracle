# Where Core's time goes (NTS, `ddev bench layers rounds=5`)

native = the Extension's classes alone, driver = Core's Extension adapter,
core = the public API; all three on one Session. Medians in one process, load
average about 6 during the run: read the proportions.

## Call 3 IN + 3 OUT (block) (2000 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native | 19.0 µs | 1 |
| driver | 19.5 µs | 1 |
| core run() | 21.2 µs | 1 |
| core procedure() | 24.2 µs | 1 |
| native, autocommit off | 18.8 µs | 1 |
| native, no binds (begin null; end;) | 8.0 µs | 1 |

## Fetch 10k × 9, default values (20 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native | 24.25 ms | 100 |
| driver | 21.89 ms | 100 |
| core all() | 23.01 ms | 100 |
| core foreach | 22.47 ms | 100 |
| driver, every row kept | 23.69 ms | 100 |
| native, fast opt-ins | 10.27 ms | 100 |
| native, list | 20.63 ms | 100 |
| native, fetchSize 1000 | 18.66 ms | 10 |

## Fetch 10k, one column each (20 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native id NUMBER(10) | 1.74 ms | 100 |
| native amount NUMBER(12,2) | 1.93 ms | 100 |
| native ratio BINARY_DOUBLE | 1.54 ms | 100 |
| native code VARCHAR2 | 1.89 ms | 100 |
| native created_at DATE object | 7.58 ms | 100 |
| native created_at DATE string | 2.72 ms | 100 |
| native updated_at TIMESTAMP object | 7.52 ms | 100 |
| native updated_at TIMESTAMP string | 3.14 ms | 100 |
| native constant 1 (protocol floor) | 1.66 ms | 100 |

## REF CURSOR 1k × 5 (200 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native | 1.21 ms | 12 |
| driver | 1.26 ms | 12 |
| core run() | 1.25 ms | 12 |
| core function() | 1.25 ms | 12 |
| native, fast opt-ins | 668.5 µs | 12 |

## LOBs 1k × (CLOB + BLOB) (20 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native inline | 6.25 ms | 10 |
| native locators, unread | 4.73 ms | 10 |
| core inline | 6.79 ms | 10 |
| core Lob::contents() | 58.05 ms | 10 |

## One 16 MiB BLOB (10 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| core contents() | 11.06 ms | 17 |
| core stream_get_contents(stream()) | 13.10 ms | 17 |
| core inline | 22.83 ms | 2 |

## Connect + close (Pool) (2000 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native | 7.4 µs | n/a (new Session) |
| driver | 8.0 µs | n/a (new Session) |
| core | 8.2 µs | n/a (new Session) |
| core, ConnectionConfig built each time | 9.0 µs | n/a (new Session) |

## Insert 1k rows (20 iterations × 5 rounds)

| Layer | Median | Round trips per iteration |
|---|---|---|
| native executeMany | 3.80 ms | 1 |
| core runMany() | 3.66 ms | 1 |
