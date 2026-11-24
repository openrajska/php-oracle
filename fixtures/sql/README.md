# PL/SQL fixtures

A small schema that exercises the Oracle features a Backend must handle. It
started as the target for the `rust-oracledb` spike probes and is the seed of
the conformance suite. Terms (Call, REF CURSOR, Implicit result, Collection,
Session, Pool) follow the [Core glossary](../../packages/php-oracle/GLOSSARY.md).

## Install

```bash
ddev fixtures
```

This runs `grants.sql` (see below) and then `install.sql` as the app user (`rajska`) in `FREEPDB1` inside the
`oracle` container, where this folder is mounted read-only at `/fixtures/sql`.
The ddev database is ephemeral (`ddev restart` recreates it), so run the
command again after every restart. It takes about 2 seconds.

`install.sql` is idempotent: it drops every object whose name starts with
`FX_`, creates everything again, commits, and exits non-zero if any fixture
object is invalid. SQL*Plus only warns when a package does not compile, so this
check is what makes such failures fail the install. Outside ddev:

```bash
cd fixtures/sql && sqlplus -s -l user/password@//host:1521/service @install.sql
```

The scripts need `CREATE TABLE`, `CREATE PROCEDURE` and `CREATE TYPE`, plus
enough tablespace quota for about 70 MB of LOB segments.

The test suites also read V$ views (`V$SESSTAT` for Round-trip counts,
`V$SESSION`, `V$SQL`). `grants.sql` grants them, run as SYSDBA with the user
as the argument; `ddev fixtures` runs it before `install.sql`:

```bash
sqlplus -s -l sys/password@//host:1521/service as sysdba @grants.sql user
``` They avoid
23ai-only syntax (no `DROP ... IF EXISTS`), so they should also run on 19c.
Non-ASCII seed data is written with `UNISTR`, so the files stay ASCII and the
client `NLS_LANG` does not matter.

## Objects by checklist item

| Checklist item | Objects | Files |
| --- | --- | --- |
| OUT / IN OUT binds: NUMBER, VARCHAR2, DATE | `fx_scalar.echo_out` (OUT), `fx_scalar.bump` (IN OUT), `fx_scalar.repeat_out` (OUT VARCHAR2 up to 32767 bytes, for OUT buffer sizing) | `01_scalar.sql` |
| Function return value | `fx_scalar.add_numbers`, `fx_scalar.greet`, `fx_scalar.add_days` | `01_scalar.sql` |
| Several REF CURSOR OUT parameters in one Call | `fx_cursor.two_cursors` (2 REF CURSORs with different columns, plus a scalar OUT) | `02_cursor.sql` |
| REF CURSOR as a function return value; strongly typed REF CURSOR | `fx_cursor.items`, `fx_cursor.typed_cursor` (`fx_cursor.item_cursor`) | `02_cursor.sql` |
| `CURSOR()` column | `fx_cursor.categories_with_items` (a REF CURSOR whose rows hold a nested `CURSOR()`), or the query below | `02_cursor.sql` |
| Implicit results (`DBMS_SQL.RETURN_RESULT`) | `fx_cursor.implicit_results` (2 results with different columns) | `02_cursor.sql` |
| Object types as IN / OUT / IN OUT / return | `fx_point`, `fx_polygon` (object with a Collection attribute); `fx_object.make_point`, `move_point`, `make_polygon`, `count_vertices` | `03_object.sql` |
| Collections (nested table, VARRAY) as IN / OUT / IN OUT / return | `fx_number_list`, `fx_point_list` (nested tables), `fx_string_varray` (VARRAY(10)); `fx_object.sum_numbers`, `reverse_numbers`, `split_csv`, `append_tag` | `03_object.sql` |
| Associative arrays (`INDEX BY`) as IN / OUT | `fx_object.t_number_map` (`index by pls_integer`); `fx_object.sum_map`, `fill_map` (sparse keys) | `03_object.sql` |
| Object and Collection columns in fetched rows | `fx_shapes` (object, VARRAY and nested table columns; rows with values, all NULL, and empty Collections) | `03_object.sql` |
| `SELECT ... FROM TABLE(pipelined_fn())` | `fx_pipe.numbers` (scalar rows), `fx_pipe.points` (object rows → columns `x, y, label`) | `04_pipe.sql` |
| Collection bound into `TABLE(:ids)` | `fx_number_list` bound against `fx_items` (query below) | `03_object.sql`, `02_cursor.sql` |
| Large LOB for streaming | `fx_lobs` row 4: 16 MiB CLOB and 16 MiB BLOB with deterministic content; rows 1–3: small, empty, NULL; `fx_lob.generate`, `text_line`, `text_length`, `binary_length` | `05_lob.sql` |
| `execute_batch` with per-row errors | `fx_batch_rows` (empty; PK, UNIQUE, NOT NULL and CHECK constraints) | `06_batch.sql` |
| `INSERT ... RETURNING ... INTO` | `fx_bind_rows` (global temporary table, rows gone at commit or rollback) | `09_bind.sql` |
| One Pool shared across threads | `fx_pool.session_id`, `fx_pool.sleep`, `fx_pool.bump_counter` | `07_pool.sql` |
| Fetch benchmark (10k rows, mixed columns) | `fx_bench_rows` | `08_bench.sql` |

## Data and expected values

### `fx_categories`, `fx_items`

- 5 categories: `Plain`, `Ação`, `Café`, `Straße`, `日本` (ids 1–5), to catch
  character set bugs when rows are decoded.
- 1000 items. Item `n` has category `((n - 1) mod 5) + 1`, name `Item 000n`
  (4-digit zero padding), price `n * 1.25` and `created_at`
  `2026-01-01 00:00 + (n - 1)` hours. Sum of prices: 625625.

```sql
-- CURSOR() column
select c.id, c.name,
       cursor(select i.id, i.name from fx_items i
               where i.category_id = c.id and i.id <= 20 order by i.id) as items
  from fx_categories c
 order by c.id;

-- Collection bound into TABLE(:ids); bind :ids as fx_number_list
select id, name
  from fx_items
 where id in (select column_value from table(:ids))
 order by id;
```

### `fx_shapes`

| id | center | tags | outline |
| --- | --- | --- | --- |
| 1 | `fx_point(1.5, -2, 'center')` | `('red', 'café')` | 3 points, the last with a NULL label |
| 2 | NULL | NULL | NULL |
| 3 | `fx_point(NULL, NULL, NULL)` | empty | empty |

### `fx_object` edge cases

- `sum_numbers`: NULL for a NULL Collection, 0 for an empty one.
- `reverse_numbers`: NULL in gives NULL out.
- `split_csv('a,,b,')` returns `('a', NULL, 'b', NULL)`. More than 10 parts
  raises ORA-06532 (VARRAY limit).
- `append_tag` turns a NULL VARRAY into a one-element VARRAY.
- `fill_map(3)` gives keys 10, 20, 30 with values 1, 2, 3; `sum_map` of an
  empty map is 0.

### `fx_lobs`

| id | label | text_value | binary_value |
| --- | --- | --- | --- |
| 1 | `small` | `Hello, LOB! Ação 日本` (19 characters) | `DEADBEEF` |
| 2 | `empty` | empty CLOB | empty BLOB |
| 3 | `null` | NULL | NULL |
| 4 | `large 16 MiB` | 16,777,216 characters (18,612,224 bytes in UTF-8) | 16,777,216 bytes |

The large values are deterministic, so a probe can check a streamed read
without a stored checksum:

- CLOB: 262,144 lines of 64 characters. Line `n` (from 1) is the 8-digit
  zero-padded `n`, a space, `ação café 日本`, a space, 41 `x`, and LF. That is
  71 bytes in UTF-8, so character offsets and byte offsets diverge on every
  line. `fx_lob.text_line(n)` returns line `n`.
- BLOB: the byte at 0-based offset `k` is `k mod 256`.

`fx_lob.generate(id, megabytes)` replaces row `id` with values of another size
(it does not commit). `fx_lob.text_length` and `fx_lob.binary_length` return
the length of a LOB the client binds in, to check a streamed write.

### `fx_batch_rows`

Empty. Each constraint gives a different error, so a batch can mix them:

| Row problem | Error |
| --- | --- |
| Duplicate `id` or `code` | ORA-00001 |
| NULL `code` or `qty` | ORA-01400 |
| `qty < 0` | ORA-02290 |

### `fx_pool`

- `session_id`: SID of the Session that ran the Call.
- `sleep(seconds)`: holds the Session (fractions allowed), to force
  concurrent Calls to use different Sessions.
- `bump_counter`: increments a package variable and returns it. It starts at 0
  in every new Session, so the sequence a Connection sees shows whether its
  Session changed underneath it.

### `fx_bench_rows`

10,000 rows for the fetch benchmark, one column of each common type. Row `n`:

| Column | Type | Value |
| --- | --- | --- |
| `id` | `NUMBER(10)` | `n` |
| `qty` | `NUMBER(10)` | `n mod 1000` |
| `amount` | `NUMBER(12,2)` | `n * 1.25` |
| `ratio` | `BINARY_DOUBLE` | `n / 7` |
| `code` | `VARCHAR2(20)` | `BENCH-` and the 6-digit zero-padded `n` |
| `label` | `VARCHAR2(60)` | `Ação café 日本 n` when `n mod 5 = 0`, else `Item n` |
| `note` | `VARCHAR2(60)` | NULL when `n mod 3 = 0`, else `note n` |
| `created_at` | `DATE` | 2026-01-01 00:00 + `(n - 1)` minutes |
| `updated_at` | `TIMESTAMP(6)` | 2026-01-01 00:00:00 + `n` milliseconds |
