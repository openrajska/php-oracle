# Oracle PL/SQL return/parameter mechanisms and how modern Oracle drivers expose them (design input for a modern PHP OCI8 API)

> Status date: 2026-11-19. Naming note: Oracle's 23ai database line is now marketed as **"Oracle AI Database 26ai"**. Release Updates continue the 23.x numbering (e.g. RU 23.7, 23.8, **23.26.0**, 23.26.3) ([PL/SQL 26ai "Changes in this release"](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html); [python-oracledb 26.0.0 notes, "Oracle Database 23.26.3"](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)). Driver docs now say "26ai" for features that older blogs call "23ai/23c" (e.g. SQL BOOLEAN, pipelining). Both drivers moved to **year-based versions**: python-oracledb 26.x (Sep 2026) and node-oracledb 26.0.0 (8 Oct 2026). Older docs and blogs still refer to "python-oracledb 3.x" and "node-oracledb 6.x". Those lines are superseded.

## 1. Catalog of Oracle SQL/PL/SQL return and parameter mechanisms a PHP library should support

### Takeaway
A modern client has to cover about ten distinct "data out of the database" channels:
- result sets
- REF CURSOR OUT/return values
- nested CURSOR() columns
- implicit results (12.1+)
- scalar OUT/IN OUT values and function returns
- DML RETURNING INTO, single and array
- PL/SQL collections, records and object types (DbObject)
- DBMS_OUTPUT
- batch errors and per-row counts
- 26ai scalar types (SQL BOOLEAN, VECTOR, JSON)

Pipelined and polymorphic table functions, TABLE() over collections, and temporary tables need no special client API, because the client sees them as plain queries. They do need good collection binding, so that values can be passed into `TABLE(:bind)`.

### Cited Findings

**REF CURSORs (SYS_REFCURSOR / strong REF CURSOR)**
- They are returned either as procedure OUT parameters or as function return values. python-oracledb binds a *cursor object* as the OUT placeholder, and for a function it uses `callfunc(name, oracledb.DB_TYPE_CURSOR)`. The returned ref cursor is then iterated like any cursor. — [python-oracledb bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- node-oracledb binds `{ type: oracledb.CURSOR, dir: oracledb.BIND_OUT }`. The value comes back in `result.outBinds.cursor` as a ResultSet that "always" must be `close()`d. — [node-oracledb bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- godror (Go) gotcha: the statement must be kept open until the cursor's rows are read. — [godror pkg docs](https://pkg.go.dev/github.com/godror/godror)
- **Nested cursors** (`SELECT ..., CURSOR(SELECT ...) FROM ...`) are supported in both Thin and Thick modes of python-oracledb, listed alongside REF CURSORs as "REF CURSORs and Nested Cursors". — [python-oracledb Appendix A](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_a.html)
- python-oracledb 3.1.0 shipped nested-cursor fetch fixes in Thin mode. 26.0.1 fixed a "NUMBER/SYS_REFCURSOR hang". REF CURSOR handling is a recurring source of driver bugs. — [python-oracledb release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)

**Implicit result sets (12.1+)**
- `DBMS_SQL.RETURN_RESULT(ref_cursor)` passes an open SYS_REFCURSOR back to the caller without an OUT parameter. PL/SQL callers can consume these with `DBMS_SQL.GET_NEXT_RESULT` on a cursor opened with `treat_as_client_for_results => TRUE`, which raises NO_DATA_FOUND when exhausted. — [oracle-base: Implicit Statement Results](https://oracle-base.com/articles/12c/implicit-statement-results-12cr1.php)
- Gotcha: result sets can have **different shapes** ("variant resultsets"), so the consumer must describe each one. The number of results can also depend on the arguments. — [oracle-base](https://oracle-base.com/articles/12c/implicit-statement-results-12cr1.php)
- Tim Hall sees the feature as mainly useful for migrating T-SQL procedures. — [oracle-base](https://oracle-base.com/articles/12c/implicit-statement-results-12cr1.php)
- Requires Oracle DB 12.1+ (python-oracledb). — [python-oracledb PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)
- Requires node-oracledb 4.0+ and DB 12.1+. — [node-oracledb PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**OUT / IN OUT parameters and function return values**
- python-oracledb: `callproc('myproc', [123, out_val])` generates `begin myproc(:1,:2); end;`. The OUT value is a `cursor.var(int)` read with `getvalue()`.
- python-oracledb: `callfunc("myfunc", int, [...])` takes the return type as its second argument; this can be a DB type or an Object Type.
- python-oracledb gotcha: an untyped `None` bind is treated as a string. NULLs of other types need a typed var or `setinputsizes`. — [python-oracledb PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html); [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- node-oracledb: "Binding is required for IN OUT and OUT parameters." A function return value is captured as an OUT bind in `BEGIN :ret := myfunc(); END;`. — [node-oracledb PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)
- node-oracledb gotcha: the default OUT string size is **200 bytes** when `maxSize` is omitted. Too small a size gives ORA-06502 or NJS-016 (Thick). — [node-oracledb bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**Named vs positional binds (a frequent source of bugs)**
- python-oracledb: positional binding ignores placeholder names. In SQL, each occurrence counts, so duplicates must be repeated (Thin enforces this). In PL/SQL, each *unique* name counts once. Binding by name avoids both issues. — [python-oracledb bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- node-oracledb documents the same rule: in SQL, position follows placeholder order; in PL/SQL, it is "the nth unique parameter name". — [node-oracledb bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**DML RETURNING INTO (single and array)**
- python-oracledb returns a *list* per variable, one item per affected row.
- The same placeholder cannot appear both before and after RETURNING. Thin mode raises DPY-2048, while Thick "silently fails to update". — [python-oracledb bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- node-oracledb: each OUT value is an array, empty if no rows match. Thin raises NJS-149 and Thick silently fails. — [node-oracledb bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- With `executemany`, the RETURNING variable needs an `arraysize` big enough for all rows. It is bound once via `setinputsizes`. — [python-oracledb batch docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/batch_statement.html)

**Array DML / bulk binds (FORALL-equivalent from the client) and batch errors**
- With `executemany(..., batcherrors=True)`, processing continues past bad rows. `getbatcherrors()` returns errors with `.offset` (the input row index).
- Gotcha: batch errors start a transaction even with autocommit on.
- `batcherrors` is **not usable with PL/SQL blocks**.
- `arraydmlrowcounts=True` gives per-row affected counts. — [python-oracledb batch docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/batch_statement.html)
- python-oracledb 3.4 added a `batch_size` parameter to chunk large batches. Bind buffers over 2 GB raise DPI-1015. — [batch docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/batch_statement.html); [release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)
- python-oracledb 26.2.0 (unreleased, TBD) adds "error offsets to `Cursor.executemany()` exceptions". — [release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)
- node-oracledb Thin raises NJS-141 when batch errors exceed 65535. Thick returns the count modulo 65536. — [node-oracledb release notes](https://node-oracledb.readthedocs.io/en/latest/release_notes.html)

**PL/SQL collections: associative arrays (INDEX BY), nested tables, VARRAYs; records; %ROWTYPE**
- 12.1 lets PL/SQL-only types (BOOLEAN, records, collections) be bound to anonymous blocks, PL/SQL function calls in SQL, the TABLE operator and CALL. — [oracle-base: PL/SQL-to-SQL interface enhancements 12cR1](https://oracle-base.com/articles/12c/plsql-to-sql-interface-enhancements-for-plsql-only-data-types-12cr1.php); [AskTOM/Oracle Magazine 2013](https://asktom.oracle.com/Misc/oramag/sql-in-plsql-enhancements.html)
- Restrictions:
  - Only `INDEX BY PLS_INTEGER` associative arrays can be bound, not `INDEX BY VARCHAR2`.
  - For TABLE() over an associative array, the type must be declared in a **package specification**, otherwise ORA-22905.
  - Functions called from SQL can *take* PL/SQL-only types but cannot *return* them.
  - Sources: [oracle-base](https://oracle-base.com/articles/12c/plsql-to-sql-interface-enhancements-for-plsql-only-data-types-12cr1.php); [Oracle 26ai Associative Arrays](https://docs.oracle.com/en/database/oracle/oracle-database/26/lnpls/associative-arrays.html); [Feuerstein blog](https://stevenfeuersteinonplsql.blogspot.com/2016/02/use-table-operator-with-associative.html)
- Dynamic SQL: EXECUTE IMMEDIATE accepts user-defined PL/SQL types since 12.1, and `DBMS_SQL.BIND_VARIABLE_PKG` since 12.2. — [search summary of AskTOM/Oracle docs](https://asktom.oracle.com/Misc/oramag/get-up-to-speed-with-dbmssql.html)
- 12.1 also allows the TABLE operator on locally defined types in PL/SQL. — [oracle-base](https://oracle-base.com/articles/12c/using-the-table-operator-with-locally-defined-types-in-plsql-12cr1)
- python-oracledb has two ways to bind associative arrays:
  - **Fast path:** `arrayvar(type, n, size)`, for contiguous `INDEX BY BINARY_INTEGER` arrays of string, number or date.
  - **General path:** `gettype("PKG.TYPE")`, a DbObject with `aslist()`/`asdict()`/`first()`/`next()`/`getelement()`. Use this for sparse arrays, because `arrayvar` fails with ORA-06513 on large index gaps.
  - Sources: [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html); [Appendix A](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_a.html)
- PL/SQL records are also DbObjects (`gettype("MYPKG.UDT_DEMORECORD")`), and `%ROWTYPE` works too (`gettype("LOCATIONS%ROWTYPE")`).
- Gotcha: set **all** record attributes, to avoid an Oracle Client bug that causes wrong values or segfaults. — [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html); [PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**TABLE() over collections / IN-list binding**
- A schema-level collection can be bound and queried as `WHERE x IN (SELECT * FROM TABLE(:bv))`.
- node-oracledb binds it by type-name string: `{ type: "NAME_ARRAY", val: [...] }`, or with the built-in `SYS.ODCIVARCHAR2LIST`.
- Gotcha: object binds add round-trips to fetch type metadata. — [node-oracledb bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)
- The IN-list limit is **65535 in 26ai**, versus 1000 before. — [python-oracledb bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**Pipelined and polymorphic table functions**
- Polymorphic table functions (PTFs) arrived in 18c with a variadic pseudo-operator. — [Oracle 23 PL/SQL: Overview of PTFs](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/overview-polymorphic-table-functions.html); [Oracle 18c changes](https://docs.oracle.com/en/database/oracle/oracle-database/18/lnpls/release-changes.html)
- 26ai: pipelined and polymorphic table functions can return **BOOLEAN columns**. — [PL/SQL 26ai changes](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html)
- python-oracledb 3.4.1 fixed Thin mode for "pipelined PL/SQL functions returning LOBs". — [release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)
- node-oracledb documents a pattern of wrapping `DBMS_OUTPUT.GET_LINE` in a pipelined function and querying it with `SELECT * FROM TABLE(mydofetch())`. — [node-oracledb PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**Private temporary tables (18c+)**
- They are memory-based and dropped at the end of the transaction (the default, `ON COMMIT DROP DEFINITION`) or of the session.
- Names must start with the `PRIVATE_TEMP_TABLE_PREFIX` prefix (default `ORA$PTT_`).
- They allow no indexes or materialized views, cannot be referenced by permanent objects, and cannot be reached over database links. — [Oracle coretec blog: 18c private temporary tables](https://blogs.oracle.com/coretec/18c-private-temporary-tables); [Connor McDonald TIL on PTTs](https://connor-mcdonald.com/2023/08/09/til-a-new-thing-about-private-temporary-tables)

**DBMS_OUTPUT**
- Retrieval pattern: `dbms_output.enable`, then `get_lines` in chunks into an array var. Per-line `get_line` "may be much slower".
- Gotcha: output is only available after the PL/SQL "runs to completion", and other connections cannot read the buffer. — [python-oracledb PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)
- godror wraps this as `EnableDbmsOutput(ctx, conn)` + `ReadDbmsOutput(ctx, w, conn)`. It must run on the same session (a `*sql.Conn`/`*sql.Tx`, not a pooled `*sql.DB`). — [godror](https://pkg.go.dev/github.com/godror/godror)

**BOOLEAN (PL/SQL and 23ai/26ai SQL)**
- SQL BOOLEAN (`BOOL` alias) is new in 26ai.
- Explicit conversions work always: `CAST`, the new `to_boolean`, and BOOLEAN overloads of `to_number`/`to_char`. Implicit numeric/char conversions need `PLSQL_IMPLICIT_CONVERSION_BOOL=TRUE`.
- DML binds, trigger WHEN clauses and `INTO`/`BULK COLLECT INTO` accept BOOLEAN.
- PL/SQL functions with BOOLEAN parameters are now callable from SQL with BOOLEAN expressions and literals. — [PL/SQL 26ai changes](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html); [PLSQL_IMPLICIT_CONVERSION_BOOL](https://docs.oracle.com/en/database/oracle/oracle-database/23/refrn/PLSQL_IMPLICIT_CONVERSION_BOOL.html)
- Gotcha: older clients can fail with "Datatype is not supported" on BOOLEAN columns. — [Devart forum report](https://support.devart.com/portal/en/community/topic/boolean-datatype-not-supported-with-oracle-23ai-client)
- python-oracledb 3.4.2 fixed "PL/SQL booleans on 12.1". — [release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)

**JSON, VECTOR and other 26ai items**
- VECTOR is a distinct scalar type in PL/SQL. BINARY format and arithmetic arrived in RU 23.7, SPARSE vectors in RU 23.8 (RAC needs a patch). — [PL/SQL 26ai changes](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html)
- The JSON constructor accepts PL/SQL aggregate types (records, collections), and `JSON_VALUE ... RETURNING` can map JSON to them. — [same](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html)
- Other 26ai PL/SQL items:
  - `IF [NOT] EXISTS` DDL.
  - The SQL Transpiler, which turns PL/SQL functions in SQL into SQL expressions.
  - The `RESETTABLE` package clause (RU 23.26.0), which avoids ORA-04068 after patching.
  - `ALTER TYPE ... INVALIDATE` and `REPLACE` are deprecated.
  - Source: [same](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html)

### Inferences
- **Must-have client abstractions for PHP:**
  - (1) A typed OUT/IN OUT bind object with explicit size and type, since default sizes cause ORA-06502.
  - (2) A cursor-typed bind and return value that yields a lazy statement/iterator.
  - (3) Iteration over implicit results, where each result is described independently.
  - (4) Array/collection binds with two strategies: a fast contiguous array (like `oci_bind_array_by_name`) and a DbObject path for sparse arrays, records and objects.
  - (5) RETURNING INTO that always yields arrays.
  - (6) Array DML with batch errors and per-row counts.
  - (7) A DBMS_OUTPUT helper pinned to one session.
  - (8) Native PHP `bool` mapping for BOOLEAN, with version detection (only PL/SQL before 26ai).
  - (9) VECTOR mapped to typed arrays, and JSON mapped to PHP arrays/objects.
- Pipelined and polymorphic table functions, CAST(MULTISET), and global and private temporary tables need **no new client API**. They are just queries. The client's job is excellent collection binding into `TABLE(:x)`, plus documentation.
- PHP's legacy OCI8 already has `oci_bind_array_by_name` (associative arrays), `OCI_B_CURSOR` and `oci_get_implicit_resultset`. The gaps compared with modern drivers are object/record types (DbObject-like), batch errors and row counts, BOOLEAN/VECTOR/JSON mapping, and ergonomic callproc/callfunc. This is based on general knowledge of PHP OCI8 and was not re-verified this session.

### Gaps
- I did not fetch the 26ai SQL Language Reference pages for **SQL domains** and **annotations**. These are 23ai/26ai metadata features; a client could expose them via column metadata (python-oracledb exposes `FetchInfo.domain_schema/domain_name/annotations` according to my training knowledge, not verified this session).
- I found no authoritative statement about using **PL/SQL package-declared types directly in SQL** beyond the 12.1 rules above (e.g. any 18c/21c expansion). The 18c qualified-expression and 21c iterator changes were not verified.
- Parallel-enabled pipelined functions (`PARALLEL_ENABLE`, `PARTITION ... BY`) were not researched. They are server-side only and transparent to clients.
- I have no primary source for **exception mapping** semantics (ORA codes, `RAISE_APPLICATION_ERROR` range -20000..-20999, `error.code`/`full_code`/`offset`). node-oracledb 6.0 added `error.code` ([release notes](https://node-oracledb.readthedocs.io/en/latest/release_notes.html)). Everything else here is from general knowledge.
- Overloaded procedures: I found no driver docs on overload resolution. In practice the drivers generate an anonymous block, and Oracle resolves overloads by bind types/names. This argues for typed binds and named notation (`=>`).

## 2. How python-oracledb exposes these features (now version 26.x, formerly 3.x)

### Takeaway
python-oracledb is the richest reference API. It has:
- a two-level bind model (plain Python values or a typed `Var` from `cursor.var()`/`arrayvar()`)
- `callproc`/`callfunc` sugar
- runtime type introspection through `connection.gettype()`, which returns DbObjectType/DbObject for objects, collections, records and %ROWTYPE
- `getimplicitresults()`
- `executemany` with `batcherrors`/`arraydmlrowcounts`/`batch_size`
- input and output type handlers
- a pure-network "Thin" mode that is now the default and feature-complete for nearly everything, including async and 26ai pipelining

### Cited Findings

**Version timeline**
| Version | Date | Features |
|---|---|---|
| 3.0.0 | Mar 2025 | pipelining out of pre-release; Arrow data frames (`fetch_df_all`); sparse vectors (Thick, 23.7) |
| 3.1 | Apr 2025 | Thin scrollable cursors |
| 3.3 | Jul 2025 | sessionless transactions (23.6); data frames bound to `executemany` |
| 3.4 | Oct 2025 | Thin Direct Path Load; `batch_size` |
| 4.0.0 | May 2026 | Thick drops Oracle Client libraries older than 19c; SQL-injection helpers `enquote_literal()`/`enquote_name()` |
| 26.0.0 | Sep 2026 | year-based versioning; drops Python 3.9; `Var.setvalue()` with str/bytes on LOB types removed |
| 26.1.0 | Oct 2026 | ezTLS; OpenTelemetry |

Source: [release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)

**Thin vs Thick**
- Thin needs DB 12.1+. Thick needs Oracle Client 19+ and DB 11.2+.
- Both modes support: REF CURSORs and nested cursors, implicit results, scrollable cursors, SQL and PL/SQL object types and collections, array DML, AQ, CQN and statement caching.
- **Pipelining, asyncio and Direct Path Load are Thin-only.**
- 26ai-dependent features: pipelining, JSON-Relational Duality Views, sessionless transactions and SQL BOOLEAN.
- Source: [Appendix A](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_a.html)

**Thin-only behavioral differences**
- Thin raises DPY-2048 for invalid RETURNING binds.
- Thin enforces repeated positional binds.
- `DB_TYPE_UROWID` binding works only in Thin.
- Thick requires the parent cursor to stay open until implicit results are fetched (DPI-1039).
- Sources: [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html); [PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**Code patterns (from the official docs)**
```python
# OUT param + function return
out_val = cursor.var(int)
cursor.callproc('myproc', [123, out_val]); print(out_val.getvalue())
ret = cursor.callfunc("myfunc", int, ["a string", 15, d])

# REF CURSOR as OUT param / function return
ref_cursor = connection.cursor()
cursor.callproc("find_employees", ['Smith', ref_cursor])
for row in ref_cursor: print(row)
rc = cursor.callfunc('example_package.f_get_cursor', oracledb.DB_TYPE_CURSOR)

# Associative array OUT (contiguous) vs sparse via DbObject
out_arr = cursor.arrayvar(str, 10, 100)
cursor.callproc("mypkg.DemoCollectionOut", [5, out_arr]); out_arr.getvalue()
coll = connection.gettype("MYPKG.UDT_STRINGLIST").newobject()
cursor.callproc("mypkg.DemoCollectionOut", [coll]); coll.asdict()

# PL/SQL record / %ROWTYPE
rec = connection.gettype("MYPKG.UDT_DEMORECORD").newobject()
rec.NUMBERVALUE = 6; rec.BOOLEANVALUE = False
cursor.callproc("mypkg.DemoRecordsInOut", [rec])

# Collection into TABLE()
obj = connection.gettype("NAME_ARRAY").newobject(); obj.extend(["Smith","Taylor"])
cursor.execute("select * from employees where last_name in (select * from table(:1))", [obj])
```
Sources: [PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html); [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**Caching type metadata**
- Keep the result of `gettype()` for reuse, because each call costs a round-trip. — [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**Input type handler**
- The hook is `connection.inputtypehandler = fn(cursor, value, arraysize)`. It returns a `cursor.var(type, arraysize=..., inconverter=...)`.
- Examples in the docs map NaN to NULL, and map a domain class `Building` to an Oracle object type.
- `setinputsizes()` pre-declares bind types and sizes. This reduces SQL version count (child cursors) when string lengths vary, and types NULLs. — [bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**Batch DML and PL/SQL**
```python
cursor.executemany("insert into ParentTable values (:1, :2)", data, batcherrors=True)
for e in cursor.getbatcherrors(): print(e.message, e.offset)
cursor.executemany("delete ... where ParentId = :1", rows, arraydmlrowcounts=True)
cursor.getarraydmlrowcounts()
outvals = cursor.var(oracledb.DB_TYPE_NUMBER, arraysize=len(data))
cursor.setinputsizes(None, outvals)
cursor.executemany("begin myproc(:1, :2); end;", data); outvals.values
```
Thick-mode `executemany` with PL/SQL OUT binds performs like repeated `execute()` calls. — [batch docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/batch_statement.html)

**DBMS_OUTPUT**
- Retrieved with `callproc("dbms_output.enable")`, then `dbms_output.get_lines` into `cursor.arrayvar(str, chunk_size)`. — [PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**Async and pipelining**
- The async classes are `AsyncConnection`, `AsyncConnectionPool`, `AsyncCursor` and `AsyncLOB`, and they are **Thin-only**.
- Async objects are not closed automatically at the end of scope. Do not mix the sync and async APIs.
- Pipelining API:
  - `oracledb.create_pipeline()` plus `add_execute/add_executemany/add_fetchone/add_fetchmany/add_fetchall/add_callproc/add_callfunc/add_commit`.
  - Run with `await conn.run_pipeline(p, continue_on_error=...)`, which returns `PipelineOpResult` objects with `.rows`, `.error` and `.warning`.
- "True pipelining only occurs" on **26ai+**. On older DBs it runs serially with no benefit.
- Pipelining limits:
  - Query results and OUT binds cannot feed later operations.
  - Each query returns only its first result set.
  - `call_timeout` is ignored.
- Source: [asyncio docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/asyncio.html)
```python
pipeline = oracledb.create_pipeline()
pipeline.add_fetchone("select temperature from weather")
pipeline.add_fetchall("select name from friends where active = true")
r1, r2 = await connection.run_pipeline(pipeline)
```

**Compilation warnings**
- `cursor.warning` with `full_code == "DPY-7000"` signals that compilation succeeded with errors; query USER_ERRORS for the details. — [PL/SQL docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

### Inferences
- The **`Var` object** (typed, sized, with `arraysize`, `getvalue(ix)`, `.values`, and in/out converters) is the main abstraction. It unifies scalar OUT binds, array binds, executemany OUT binds and RETURNING INTO. A PHP equivalent (`$stmt->var(Type::Number, size: 100, arraySize: n)`) would replace OCI8's loose by-reference binding.
- `gettype()` with runtime introspection (DbObjectType attributes, element type, `newobject()`) shows that the client does not need code generation for object types. Lazy metadata fetch plus a per-connection cache is the pattern to copy.
- python-oracledb is moving toward Thin-only features (async, pipelining, direct path, ezTLS). A PHP extension built on OCI/ODPI-C (Thick) will not get pipelining or async without implementing the network protocol itself.

### Gaps
- `outputtypehandler` and `prefetchrows`/`arraysize` semantics were not fetched this session. Known API: `outputtypehandler(cursor, metadata)` returning a `cursor.var(...)` with `outconverter`, and `cursor.prefetchrows`/`cursor.arraysize` tuning. These come from general knowledge.
- I did not verify the exact BOOLEAN bind type (`DB_TYPE_BOOLEAN`) or the VECTOR/JSON bind mappings (`array.array`, `SparseVector`, dict/list for JSON). Appendix A lists VECTOR as `array.array` and JSON without mode distinction.
- Whether python-oracledb `callproc` supports `keyword_parameters` (named notation) was not confirmed on the fetched page (training knowledge says yes: `callproc(name, parameters, keyword_parameters)`).

## 3. How node-oracledb exposes these features (now 26.0.0, formerly 6.x/7.x)

### Takeaway
node-oracledb uses **bind descriptor objects** (`{dir, type, maxSize, maxArraySize, val}`) instead of typed variable objects:
- REF CURSORs and implicit results come back as `ResultSet` objects, closed explicitly or via 7.0's Explicit Resource Management, or as arrays.
- Object types are bound by type-name string, or through `getDbObjectClass()` classes.
- The hooks are `fetchTypeHandler` (6.0+) and `dbObjectTypeHandler` (6.8+).
- Thin mode is the default since 6.0. Pipelining (Thin, 26ai) arrived in 7.0.0.

### Cited Findings

**Version timeline**
| Version | Date | Features |
|---|---|---|
| 6.0.0 | 24 May 2023 | Thin default; BOOLEAN SQL type; `fetchTypeHandler`; `error.code` |
| 6.5.0 | — | VECTOR (DB 23.4) |
| 6.6.0 | — | BINARY vectors |
| 6.8.0 | 27 Feb 2025 | SPARSE vectors (23.7); `dbObjectTypeHandler`; BigInt in DbObjects; `dbObject.copy()` |
| 6.9.0 | — | sessionless transactions; minimum Node 14.17 |
| 6.10.0 | Oct 2025 | Thin AQ |
| 7.0.0 | 2 Jun 2026 | Thin pipelining (26ai); Thin direct path loads; Explicit Resource Management for Connection/Pool/ResultSet; Thick drops Oracle Client libraries older than 19c |
| 26.0.0 | 8 Oct 2026 | year-based versioning; `rowsPerDataEvent` for `queryStream()`; Thin fix for DbObjects with more than 100 attributes |

Source: [release notes](https://node-oracledb.readthedocs.io/en/latest/release_notes.html)

**Code patterns**
```js
// OUT scalar
{ name: { dir: oracledb.BIND_OUT, type: oracledb.STRING, maxSize: 40 } }  // result.outBinds.name
// REF CURSOR
const r = await conn.execute(`BEGIN get_emp_rs(:sal, :cursor); END;`,
  { sal: 6000, cursor: { type: oracledb.CURSOR, dir: oracledb.BIND_OUT } },
  { fetchArraySize: 1000 });
const rs = r.outBinds.cursor; let row;
while ((row = await rs.getRow())) console.log(row);
await rs.close();
// Collection into TABLE()
await conn.execute(`... WHERE first_name IN (SELECT * FROM TABLE(:bv))`,
  { bv: { type: "NAME_ARRAY", val: ['Christopher','Hazel'] } });
// RETURNING INTO -> arrays
{ ids: { type: oracledb.NUMBER, dir: oracledb.BIND_OUT }, rids: { type: oracledb.STRING, dir: oracledb.BIND_OUT } }
```
Sources: [bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html); [PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**Associative arrays**
- They need `maxArraySize` in the bind descriptor. — [bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)

**Implicit results**
- `result.implicitResults` holds arrays of rows, or ResultSets when the `resultSet: true` option is used (recommended for large results).
- Requires node-oracledb 4.0+ and DB 12.1+.
- The docs sample has a `const i` increment bug.
- Thick crash fixed in 7.0.0. A Thin fix for JSON/LOB/VECTOR columns in implicit results is pending in 26.1.0.
- Sources: [PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html); [release notes](https://node-oracledb.readthedocs.io/en/latest/release_notes.html)

**DBMS_OUTPUT**
- Two ways to retrieve it: loop `DBMS_OUTPUT.GET_LINE(:ln, :st)` with OUT binds, or use a pipelined-function wrapper queried with SELECT. — [PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

**Compilation warnings**
- `result.warning` returns NJS-700. Thin and Thick report them differently: Thick also shows ORA-24344. — [PL/SQL docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)

### Inferences
- Bind descriptors are serializable and easy for users to compose, but they are stringly-typed for object types (`type: "NAME_ARRAY"`). A PHP API can offer both: a fluent typed builder (`Bind::out(Type::Cursor)`) that compiles to descriptor-like internal structures.
- Explicit Resource Management (7.0) shows that drivers now tie cursor/ResultSet lifetimes to language scope. In PHP, destructors and refcounting give this for free, but REF CURSOR lifetime rules still need documenting: a child cursor stays valid only while its parent statement and connection are open (the godror note says the same).

### Gaps
- `getDbObjectClass()` usage, DbObject attribute access, and `fetchTypeHandler` signature examples were not fetched. From training knowledge: `const C = await conn.getDbObjectClass('MYPKG.T'); new C([...])`, and `oracledb.fetchTypeHandler = (metadata) => ({ type: oracledb.STRING })` or `{ converter: fn }`.
- `executeMany` `batchErrors`/`dmlRowCounts` code examples were not fetched (option names are known from training: `{ batchErrors: true, dmlRowCounts: true }` → `result.batchErrors`, `result.dmlRowCounts`).
- I did not verify which Thin-mode limitations remain for object types, if any.

## 4. How JDBC (ojdbc), Go (godror) and others expose them

### Takeaway
JDBC uses the standard `CallableStatement` + `registerOutParameter` model, with Oracle extensions for REF CURSORs, STRUCT/ARRAY and SQLData. Its implicit-results support via `getMoreResults()` is known to deviate from the JDBC spec. godror (Go, built on ODPI-C) is the closest analogue to a PHP extension built on ODPI-C/OCI:
- `sql.Out` binds
- `driver.Rows` for cursors
- `GetObjectType`/`Object`/`ObjectCollection`
- DBMS_OUTPUT helpers
- `Batch` with `BatchErrors`
- `FetchArraySize`/`PrefetchCount` options

### Cited Findings

**JDBC**
- The JDBC 23 and 26 developer's guides have "Support for Implicit Results" sections (2.13 in 23c, 2.14 in 26) and a "REF CURSOR Type Category" section (4.5.2). — [JDBC 23 TOC](https://docs.oracle.com/en/database/oracle/oracle-database/23/jjdbc/toc.htm); [JDBC 26 TOC](https://docs.oracle.com/en/database/oracle/oracle-database/26/jjdbc/toc.htm)
- jOOQ (Lukas Eder) reports that Oracle's JDBC implicit-results handling does not follow the JDBC spec:
  - `getResultSet()` can throw ORA-17283 "No resultset available" even after `execute()` returned true.
  - With `PreparedStatement`, `execute()` can return false and update count -1 while implicit results still exist.
  - Robust consumption needs a defensive loop over `execute()`/`getMoreResults()`/`getUpdateCount()`.
  - Source: [jOOQ blog](https://blog.jooq.org/how-to-fetch-oracle-12c-implicit-cursors-with-jdbc-and-jooq/)
- The older (10g) API used `getCursor` for REF CURSORs. — [10g JDBC guide](https://www.comp.nus.edu.sg/~oradoc/doc10g/java.102/b14355/oraint.htm)

**godror (Go), v0.52.0 (13 Nov 2026)**
- Exposed API:
  - REF CURSOR via `sql.Out{Dest: &rset}` with `driver.Rows`. Keep the statement open while reading.
  - `GetObjectType(ctx, ex, typeName)` returns an `*ObjectType`. `NewObject`/`NewCollection` build values, and `Object.Get/Set/AsMap/FromMap` and `ObjectCollection.Append/Get/Len/Iter` access them.
  - `EnableDbmsOutput`/`ReadDbmsOutput`, on the same session.
  - `Batch` with `Add/Flush`, and `BatchErrors` with `Unwrap() []error`; the `PartialBatch()` option.
  - `FetchArraySize`, `PrefetchCount` and `ArraySize` (default 1024) for PL/SQL arrays.
  - A `PlSQLArrays` option, whose current status is unclear.
- Source: [godror on pkg.go.dev](https://pkg.go.dev/github.com/godror/godror)
```go
var rset driver.Rows
_, err := stmt.ExecContext(ctx, sql.Out{Dest: &rset})
```

### Inferences
- From JDBC experience (training knowledge, not re-verified this session):
  - REF CURSOR: `cs.registerOutParameter(2, OracleTypes.CURSOR); cs.execute(); ResultSet rs = (ResultSet) cs.getObject(2);`
  - Objects: `java.sql.Struct`/`Array` via `conn.createStruct`/`OracleConnection.createOracleArray`, or typed mapping with `SQLData` + a connection `typeMap` (`readSQL`/`writeSQL`).
  - Batching: `addBatch`/`executeBatch`, which throws `BatchUpdateException` with per-row counts.
  - Fetch size: `setFetchSize`.
  - Named notation: `cs.setString("p_name", ...)`.
- The **SQLData/typeMap** idea (user classes registered for an Oracle type name) and python's `inputtypehandler`/`outputtypehandler` (conversion hooks) are the two main patterns for user-defined mapping. A PHP library could combine them as a `TypeMap::register('HR.ADDRESS_T', Address::class)` that uses attribute reflection (`#[OracleType('HR.ADDRESS_T')]`) for hydration.
- ODPI-C is the shared C layer under python-oracledb Thick, node-oracledb Thick and godror. A PHP extension built on ODPI-C would inherit its object, collection, implicit-result, batch-error and SODA support. Based on the godror API surface; ODPI-C docs not fetched.

### Gaps
- I did not fetch JDBC guide pages directly, so the JDBC code above is from training knowledge and labeled as such.
- **ODP.NET** (`OracleRefCursor`, `OracleParameter.CollectionType = PLSQLAssociativeArray`, `ArrayBindCount`, UDT custom type mapping) and **rust-oracle** (`stmt.query_named`, `Object`/`ObjectType`, `RefCursor`, `implicit_result()`) were not researched in this session (tool-call budget).
- I did not determine whether godror supports implicit results (`NextResultSet`); the fetched page did not show it.

## 5. Best-practice design patterns for a typed, ergonomic PHP API

### Takeaway
Across drivers, these patterns converge:
- (1) Plain values for simple IN binds, plus **typed bind objects** for OUT, IN OUT, arrays and NULLs.
- (2) **callproc/callfunc** helpers that generate anonymous blocks, with named-notation support.
- (3) **Cursors as lazy iterators** with tunable arraysize/prefetch, explicit close and scope-based cleanup.
- (4) **Runtime object-type introspection** with a per-connection metadata cache, instead of code generation.
- (5) **Type handler hooks** for input, output and DbObject conversion, plus optional class mapping.
- (6) **Array DML** with batch errors and per-row counts.
- (7) Strict, informative errors in place of the silent failures some modes allow.

### Cited Findings
- Typed variable objects: python `cursor.var()/arrayvar()` ([bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)); node bind descriptors `{dir,type,maxSize,maxArraySize}` ([node bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)); Go `sql.Out` ([godror](https://pkg.go.dev/github.com/godror/godror)).
- Type introspection with caching: python `gettype()` (cache it, since each call is a round-trip) ([bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)); node object binds add round-trips for type info ([node bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)); godror `GetObjectType` ([godror](https://pkg.go.dev/github.com/godror/godror)).
- Conversion hooks: python `inputtypehandler` with `inconverter` ([bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)); node `fetchTypeHandler` (6.0) and `dbObjectTypeHandler` (6.8) ([node release notes](https://node-oracledb.readthedocs.io/en/latest/release_notes.html)).
- Streaming and lazy cursors: node ResultSet `getRow()`/`getRows(n)` + `close()`, `queryStream()` with `rowsPerDataEvent` (26.0), and Explicit Resource Management (7.0) ([node bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html); [release notes](https://node-oracledb.readthedocs.io/en/latest/release_notes.html)); python `async for` over AsyncCursor ([asyncio docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/asyncio.html)).
- Bulk and columnar: python `fetch_df_all()`/`fetch_df_batches()` (Arrow), `executemany` from data frames, and Direct Path Load (Thin) ([release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)).
- Safety helpers: python 4.0 added `enquote_literal()`/`enquote_name()` SQL-injection helpers ([release notes](https://python-oracledb.readthedocs.io/en/latest/release_notes.html)).
- Strictness: Thin modes raise DPY-2048 or NJS-149 where Thick silently fails on RETURNING binds ([python bind docs](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html); [node bind docs](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)). This shows that modern drivers prefer to fail loudly.

### Inferences (proposed PHP shape, design input only)
```php
// Typed binds + callproc/callfunc with named notation
$out  = $stmt->var(OracleType::Varchar, size: 100);
$conn->callProc('hr.pkg.find', ['p_name' => 'Smith', 'p_out' => $out]);   // generates BEGIN hr.pkg.find(p_name => :p_name, p_out => :p_out); END;
$n    = $conn->callFunc('hr.pkg.count_emps', OracleType::Number, [10]);

// REF CURSOR / implicit results as lazy generators
$cur = $conn->callFunc('pkg.get_cursor', OracleType::Cursor);   // returns Cursor implements IteratorAggregate
foreach ($cur as $row) { ... }                                   // fetch in arraySize chunks; prefetchRows configurable
foreach ($stmt->implicitResults() as $rs) { foreach ($rs as $row) {...} }

// Object/collection introspection (cached per connection) + optional class mapping
$t = $conn->getType('MYPKG.UDT_STRINGLIST');     // DbObjectType: attributes, elementType, isCollection
$c = $t->new(['a','b']); $c->toArray(); $c->toAssoc();
$conn->typeMap()->register('HR.ADDRESS_T', Address::class);  // SQLData-like hydration

// Array DML with batch errors / row counts
$r = $conn->executeMany($sql, $rows, batchErrors: true, rowCounts: true, batchSize: 10_000);
$r->errors();  // [offset => OracleException]
$r->rowCounts();

// Hooks
$conn->setOutputTypeHandler(fn(ColumnMeta $m) => $m->type === OracleType::Number ? Fetch::asString() : null);
```
- Map BOOLEAN to PHP `bool`, JSON to `array|object` (or a `JsonValue`), and VECTOR to a typed float/int list or a `SparseVector`. INTERVAL should map to a value object, and TIMESTAMP WITH TIME ZONE to `DateTimeImmutable`.
- Because PHP is synchronous, a pipelining equivalent would need a Thin (pure-PHP or Rust) protocol implementation. For an OCI-based extension, pipelining and async are out of scope for now.

### Gaps
- No PHP-specific prior art was researched here: legacy OCI8, PDO_OCI, the Doctrine DBAL Oracle driver, or Yajra Laravel-OCI8. Those are presumably covered by sibling research notes.
- Performance trade-offs between DbObject binds (round-trips for type metadata) and contiguous array binds were not quantified.
