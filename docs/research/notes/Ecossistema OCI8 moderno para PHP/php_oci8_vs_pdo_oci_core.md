# PHP core-level Oracle drivers: ext-oci8 vs PDO_OCI (capabilities, internals, limitations)

> Research date: 2026-11-19. Primary method: shallow clones of `php/pecl-database-oci8` (main, last commit 2026-01-16, tag v3.4.1) and `php/pecl-database-pdo_oci` (main, last commit 2026-10-29, tag v1.2.0), grepping the C source directly, plus GitHub issues, the PHP RFC, PECL, Oracle OCI docs and ODPI-C release notes. Line numbers below refer to `main` as of the clone date. Code snippets show the documented API and have **not** been run against a live database.

---

## 1. ext-oci8 API surface for PL/SQL (REF CURSOR, implicit results, binds, collections, LOBs, BOOLEAN, ADTs, JSON/VECTOR)

### Takeaway
ext-oci8 covers "classic" PL/SQL interop well: REF CURSOR in/out, nested cursors, implicit result sets, scalar OUT/IN OUT binds, PL/SQL index-by arrays of scalars, nested tables/VARRAYs of scalars, and full LOB/temp LOB/BFILE. It has **no** support for object types (ADTs), records, collections of objects, BOOLEAN in arrays, or the native 21c+/23ai types (JSON/OSON, VECTOR, SQL BOOLEAN). Those types are not handled at all and fall through to a generic string define.

### Cited Findings

**Function surface (full list from the stub)**
- The public API is procedural and resource-based: `oci_parse`, `oci_execute`, `oci_bind_by_name`, `oci_bind_array_by_name`, `oci_define_by_name`, `oci_new_cursor`, `oci_get_implicit_resultset`, `oci_new_descriptor`, `oci_new_collection`, `oci_collection_*` (append/element_get/assign/element_assign/size/max/trim), `oci_lob_*`, `oci_set_prefetch`, `oci_set_prefetch_lob`, `oci_set_client_identifier`, `oci_set_module_name`, `oci_set_action`, `oci_set_client_info`, `oci_set_db_operation`, `oci_set_edition`, `oci_set_call_timeout`, `oci_register_taf_callback`, `oci_password_change`, `oci_pconnect`/`oci_new_connect`/`oci_connect`. Connections and statements are still `resource`s (the stub annotates `@param resource $statement` / `$connection`). Only `OCILob` and `OCICollection` are classes. — [oci8.stub.php](https://github.com/php/pecl-database-oci8/blob/main/oci8.stub.php)
- The RFC that unbundled oci8 names the resource-to-object migration as "a major effort" that "should be done by the corporate sponsor of the Oracle extensions." — [RFC: Unbundle ext/imap, ext/pspell, ext/oci8](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)

**Bind type constants exposed**
- `OCI_B_BFILE, OCI_B_BIN, OCI_B_BLOB, OCI_B_BOL, OCI_B_CFILEE, OCI_B_CLOB, OCI_B_CURSOR, OCI_B_INT, OCI_B_NTY, OCI_B_NUM, OCI_B_ROWID` plus `SQLT_AFC, SQLT_AVC, SQLT_BDOUBLE, SQLT_BFILEE, SQLT_BFLOAT, SQLT_BIN, SQLT_BLOB, SQLT_BOL, SQLT_CFILEE, SQLT_CHR, SQLT_CLOB, SQLT_FLT, SQLT_INT, SQLT_LBI, SQLT_LNG, SQLT_LVC, SQLT_NTY, SQLT_NUM, SQLT_ODT, SQLT_RDD, SQLT_RSET, SQLT_STR, SQLT_UIN, SQLT_VCS`, and `OCI_TEMP_BLOB/OCI_TEMP_CLOB`, `OCI_RETURN_LOBS`, `OCI_RETURN_NULLS`, `OCI_NO_AUTO_COMMIT`, `OCI_DESCRIBE_ONLY`, `OCI_CRED_EXT`, `OCI_SYSDBA/SYSOPER`, and the `OCI_FO_*` TAF constants. **There is no JSON, VECTOR, TIMESTAMP-as-native, or object constant.** — [oci8.stub.php](https://github.com/php/pecl-database-oci8/blob/main/oci8.stub.php)

**REF CURSOR / nested cursors**
- Binding with `SQLT_RSET` requires a statement resource made by `oci_new_cursor`. The bind validator rejects anything else (`case SQLT_RSET: if (Z_TYPE_P(zv) != IS_RESOURCE)` → error). — [oci8_statement.c ~L976](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- In result-set columns, a `SQLT_RSET` column (a `CURSOR(...)` expression in a SELECT) is defined as a nested statement handle (`outcol->is_cursor = 1; define_type = SQLT_RSET; dynamic = OCI_DYNAMIC_FETCH`). Fetching it gives a statement resource that must itself be `oci_execute`d and fetched. — [oci8_statement.c ~L701-710](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- The test suite has `cursor_bind.phpt`, `cursors.phpt`, `cursor_bind_err.phpt`, and `refcur_prefetch_1..4.phpt` (prefetch on REF CURSORs). — [tests/](https://github.com/php/pecl-database-oci8/tree/main/tests)

**Implicit result sets (DBMS_SQL.RETURN_RESULT)**
- `oci_get_implicit_resultset()` is implemented with `OCIStmtGetNextResult` (oci8_statement.c L146) and reads `OCI_ATTR_IMPLICIT_RESULT_COUNT`. It is compiled only when `OCI_MAJOR_VERSION >= 12`; the code block for older clients is under `#if (OCI_MAJOR_VERSION < 12)` at L137-189. The tests are `imp_res_cursor.phpt`, `imp_res_get_cursor.phpt`, and `imp_res_prefetch.phpt`. — [oci8_statement.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)

**OUT / IN OUT scalar binds**
- `oci_bind_by_name` uses `OCIBindByName` with `OCIBindDynamic` for callbacks. The post-execute code converts results back by bind type (NUM/INT/LNG → int, FLT → float, ODT → date string, char types → string). — [oci8_statement.c ~L1028-1100, L1295-1302](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)

**PL/SQL associative arrays (`oci_bind_array_by_name`)**
- `php_oci_bind_array_by_name()` accepts only `SQLT_NUM/SQLT_INT/SQLT_LNG`, `SQLT_FLT`, `SQLT_AFC/CHR/VCS/AVC/STR/LVC`, and `SQLT_ODT`. Any other type hits `default:` and is rejected. **BOOLEAN, LOB, RAW, TIMESTAMP and object element types are not possible.** — [oci8_statement.c L1608-1653](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- It calls `OCIBindByName(... maxarr_len = max_table_length, curelep = &current_length ...)`, which is the OCI *PL/SQL index-by table* bind. It is not DML array binding. — [oci8_statement.c L1668-1690](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- The tests include array_bind_001..014, `_bdouble`, `_bfloat`, `_date`, and `_float`. — [package.xml](https://github.com/php/pecl-database-oci8/blob/main/package.xml)

**Collections (`oci_new_collection` / OCICollection)**
- `oci_new_collection` describes the type with `OCIDescribeAny`/`OCITypeByName`. It accepts only `OCI_TYPECODE_TABLE` (nested table) and `OCI_TYPECODE_VARRAY`. — [oci8_collection.c L100-160](https://github.com/php/pecl-database-oci8/blob/main/oci8_collection.c)
- Element types handled in append, get and assign: `OCI_TYPECODE_DATE`, `OCI_TYPECODE_VARCHAR2`, and the numeric family (UNSIGNED16/32, REAL, DOUBLE, INTEGER, SIGNED16/32, DECIMAL, FLOAT, NUMBER, SMALLINT). Anything else raises `E_NOTICE "Unknown or unsupported type of element: %d"`. **This rules out object-type elements, nested collections, LOB, TIMESTAMP, RAW, NVARCHAR2/CHAR typecodes and BOOLEAN.** — [oci8_collection.c L436-459, L513-570, L731-745](https://github.com/php/pecl-database-oci8/blob/main/oci8_collection.c)
- Collections bind with `OCI_B_NTY` (`SQLT_NTY`) and must be an `OCICollection` object (`case SQLT_NTY ... if (Z_TYPE_P(zv) != IS_OBJECT)` → "Invalid variable used for bind"). — [oci8_statement.c L948-958](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)

**Object types (ADTs)**
- The only object-cache APIs oci8 calls are `OCIObjectNew`/`OCIObjectFree`, `OCITypeByName`/`OCITypeByRef`, and `OCIColl*`. These are used only to create collections. It never calls `OCIObjectGetAttr`/`OCIObjectSetAttr` or `OCIObjectPin`, so there is no attribute-level access to user-defined object types. — grep of OCI calls in [oci8 sources](https://github.com/php/pecl-database-oci8)

**LOBs**
- LOB support is complete. It uses `OCILobRead2`, `OCILobWrite`, `OCILobAppend`, `OCILobCopy`, `OCILobErase`, `OCILobTrim`, `OCILobCreateTemporary`/`FreeTemporary`, buffering (`OCILobEnableBuffering`/`FlushBuffer`), BFILE (`OCILobFileOpen`/`Close`), `OCILobIsEqual`, and `OCILobGetChunkSize`. The PHP API is the `OCILob` class plus `oci_lob_*`, `OCI_TEMP_CLOB/BLOB` and `OCI_RETURN_LOBS`. — [oci8 sources](https://github.com/php/pecl-database-oci8), [oci8.stub.php](https://github.com/php/pecl-database-oci8/blob/main/oci8.stub.php)
- A memory leak with the `OCI_RETURN_LOBS` fetch flag was fixed in July 2025 (Michael Voříšek, #32). — [commit log](https://github.com/php/pecl-database-oci8/commits/main)

**BOOLEAN**
- PL/SQL BOOLEAN binding via `oci_bind_by_name` was added in PECL OCI8 2.0.7, which is old (PHP 5.x era). — [PECL oci8 2.0.7](https://pecl.php.net/package/oci8/2.0.7)
- In the code, `SQLT_BOL` binds are compiled only when `OCI_MAJOR_VERSION >= 12`. The PHP value is passed through `convert_to_boolean` and sent as a 0/1 `zend_long`. — [oci8_statement.c L964-966, L1236-1249](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- `SQLT_BOL` does **not** appear in the column-define switch (L700-760) or in the array-bind type list (L1628-1653). A 23ai SQL `BOOLEAN` column therefore goes down the generic `default:` define path as `SQLT_CHR`, and a PL/SQL array of BOOLEAN cannot be bound. — [oci8_statement.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- The php.net datatypes page documents `SQLT_BOL` only for PL/SQL BOOLEAN binding and does not mention SQL BOOLEAN columns. — [php.net OCI8 datatypes](https://www.php.net/oci8.datatypes.php)

**JSON / VECTOR (21c+/23ai/26ai)**
- There is no reference to `json`, `vector`, `OCIJson*`, `OCI_DTYPE_JSON`, or `SQLT_VEC` anywhere in the oci8 sources (case-insensitive grep). — [oci8 sources](https://github.com/php/pecl-database-oci8)
- Oracle's OCI docs say a JSON column can be defined as `SQLT_CHR`, `SQLT_CLOB` or `SQLT_BLOB` to get JSON text, but "If SQLT_CHR is used as define type, then the JSON text size is restricted to 32K only." Native binary OSON needs the `OCIJson` descriptor (`OCI_DTYPE_JSON`), `OCIJsonDomDocGet`/`Set`, and `OCIJsonToTextBuffer`. — [Oracle OCI: Support for JSON (26)](https://docs.oracle.com/en/database/oracle/oracle-database/26/lnoci/oci-support-json.html)
- A web search found no PHP OCI8 documentation or changelog entry covering SQL BOOLEAN, VECTOR or JSON column fetches. By contrast, python-oracledb 2.2 (ODPI-C based) added VECTOR support. — [python-oracledb 2.2 and VECTOR (C. Jones, Medium)](https://medium.com/oracledevs/python-oracledb-2-2-and-the-vector-type-in-oracle-database-23ai-7eb258a2ea3f)

**Default numeric typing**
- oci8 returns NUMBER columns as PHP strings. cmb69 noted that `fetch.phpt` expects strings and reclassified the issue as a feature request. Users asked for an opt-in int mode, and the maintainer (mvorisek) answered "PR welcomed." It is still open. — [pecl-database-oci8 #5](https://github.com/php/pecl-database-oci8/issues/5)

### Code snippets (ext-oci8, documented API)

```php
<?php
$c = oci_connect('hr', 'pw', 'localhost/FREEPDB1', 'AL32UTF8');

// 1) REF CURSOR as OUT param (OCI_B_CURSOR)
//   PROCEDURE get_emps(p_dept IN NUMBER, p_rc OUT SYS_REFCURSOR)
$s   = oci_parse($c, 'BEGIN pkg.get_emps(:dept, :rc); END;');
$rc  = oci_new_cursor($c);
$dept = 50;
oci_bind_by_name($s, ':dept', $dept);
oci_bind_by_name($s, ':rc', $rc, -1, OCI_B_CURSOR);
oci_execute($s);
oci_set_prefetch($rc, 500);      // prefetch must be set on the cursor before executing it
oci_execute($rc);                // the REF CURSOR must itself be executed
while ($row = oci_fetch_assoc($rc)) { /* ... */ }
oci_free_statement($rc);

// 2) Nested cursor in a SELECT list
$s = oci_parse($c, 'SELECT d.department_name,
                           CURSOR(SELECT last_name FROM employees e
                                  WHERE e.department_id = d.department_id) AS emps
                    FROM departments d');
oci_execute($s);
while ($d = oci_fetch_assoc($s)) {
    $nested = $d['EMPS'];           // statement resource
    oci_execute($nested);
    while ($e = oci_fetch_assoc($nested)) { /* ... */ }
}

// 3) Implicit result sets (12c+ client & DB): DBMS_SQL.RETURN_RESULT
$s = oci_parse($c, 'DECLARE c1 SYS_REFCURSOR; c2 SYS_REFCURSOR; BEGIN
     OPEN c1 FOR SELECT * FROM regions;   DBMS_SQL.RETURN_RESULT(c1);
     OPEN c2 FOR SELECT * FROM countries; DBMS_SQL.RETURN_RESULT(c2);
   END;');
oci_execute($s);
while (($child = oci_get_implicit_resultset($s)) !== false) {
    while ($r = oci_fetch_assoc($child)) { /* ... */ }
}

// 4) PL/SQL associative array (index-by) IN and OUT binds
//   TYPE num_tab IS TABLE OF NUMBER INDEX BY PLS_INTEGER;
//   PROCEDURE double_all(p_in IN num_tab, p_out OUT num_tab);
$s   = oci_parse($c, 'BEGIN pkg.double_all(:a_in, :a_out); END;');
$in  = [1, 2, 3, 4];
$out = [];
oci_bind_array_by_name($s, ':a_in',  $in,  count($in), -1, SQLT_NUM);
oci_bind_array_by_name($s, ':a_out', $out, 100,        -1, SQLT_NUM); // max_table_length
oci_execute($s);   // $out now = [2,4,6,8]
// String arrays: SQLT_CHR with explicit max_item_length, e.g. oci_bind_array_by_name($s, ':n', $names, 100, 50, SQLT_CHR);

// 5) Nested table / VARRAY of scalars (SQL-level type required)
//   CREATE TYPE num_list AS TABLE OF NUMBER;
$coll = oci_new_collection($c, 'NUM_LIST', 'HR');
foreach ([10, 20, 30] as $v) { $coll->append($v); }
$s = oci_parse($c, 'BEGIN pkg.process(:ids); END;');
oci_bind_by_name($s, ':ids', $coll, -1, OCI_B_NTY);
oci_execute($s);
// Collections of OBJECT types (e.g. TABLE OF emp_obj) => "Unknown or unsupported type of element"

// 6) PL/SQL BOOLEAN IN/OUT (12c+ client)
$s = oci_parse($c, 'BEGIN :r := pkg.is_active(:id); END;');
$id = 7; $r = null;
oci_bind_by_name($s, ':id', $id);
oci_bind_by_name($s, ':r', $r, -1, OCI_B_BOL);
oci_execute($s);

// 7) Temporary LOB passed to PL/SQL
$lob = oci_new_descriptor($c, OCI_D_LOB);
$s   = oci_parse($c, 'BEGIN pkg.store_doc(:doc); END;');
oci_bind_by_name($s, ':doc', $lob, -1, OCI_B_CLOB);
$lob->writeTemporary(str_repeat('x', 1_000_000), OCI_TEMP_CLOB);
oci_execute($s, OCI_NO_AUTO_COMMIT);
oci_commit($c);
$lob->close(); $lob->free();
```

### Inferences
- PL/SQL APIs that use `%ROWTYPE`/RECORD params, object types, or collections of objects cannot be called directly from oci8. The usual workaround is a PL/SQL wrapper that flattens them into scalars, index-by arrays of scalars, REF CURSORs or JSON CLOBs. A new ecosystem layer would either need native ADT support at the C level or an automated "wrapper generator" approach.
- Because `default:` defines unknown types as `SQLT_CHR`, 23ai `BOOLEAN`, `JSON` (≤32 KB as text) and possibly `VECTOR` columns will probably come back as strings through Oracle's implicit conversion. Large JSON documents need `JSON_SERIALIZE(... RETURNING CLOB)` or a cast in SQL. This is inferred from the code path and Oracle's OCI JSON doc, not tested.
- OUT-bind handling for `SQLT_BOL`: the post-execute conversion switch (L1028+) has no `SQLT_BOL` case. Whether an OUT BOOLEAN reliably comes back as PHP `bool` should be verified with `tests/bind_boolean_1.phpt`.

### Gaps
- No live test was run against 23ai or 26ai to confirm what oci8 returns for SQL BOOLEAN, JSON (native OSON describe type) and VECTOR columns (string? ORA-error?).
- No source was found on whether oci8 can bind a PL/SQL associative array of VARCHAR2 *indexed by VARCHAR2*. The OCI index-by bind only supports PLS_INTEGER-indexed, dense arrays.
- PL/SQL RECORD/`%ROWTYPE` binds (supported natively since OCI 12c+/18c via object-type mapping in ODPI-C) were not checked against the oci8 code beyond the absence of object attribute APIs.

---

## 2. Performance and HA features in ext-oci8

### Takeaway
oci8 exposes the mature OCI performance stack: statement cache (OCIStmtPrepare2), row and LOB prefetch, OCI session pool behind `oci_connect`, persistent connections, DRCP (connection_class and purity), FAN, TAF, call timeout and end-to-end tracing attributes. It does **not** offer DML array binding (executemany), scrollable cursors, AQ, CQN, SODA, sharding keys, pipelining or async.

### Cited Findings
- The php.ini directives are `oci8.max_persistent`, `oci8.persistent_timeout`, `oci8.ping_interval`, `oci8.privileged_connect`, `oci8.statement_cache_size`, `oci8.default_prefetch`, `oci8.old_oci_close_semantics`, `oci8.connection_class`, `oci8.events`, and `oci8.prefetch_lob_size`. — [oci8.c](https://github.com/php/pecl-database-oci8/blob/main/oci8.c), [oci8.ini](https://github.com/php/pecl-database-oci8/blob/main/oci8.ini)
- **Statement caching**: statements are prepared with `OCIStmtPrepare2`/`OCIStmtRelease` and the size is set via `OCI_ATTR_STMTCACHESIZE`. — [oci8 sources](https://github.com/php/pecl-database-oci8)
- **Session pool**: non-persistent `oci_connect` defaults to an internal client-side OCI session pool (`bool use_spool = 1; /* Default is to use client-side session pool */`). It uses `OCISessionPoolCreate`/`OCISessionGet`/`OCISessionRelease`, and persistent connections keep a `private_spool`. — [oci8.c L800-801, L1119-1145, L1833](https://github.com/php/pecl-database-oci8/blob/main/oci8.c), [php_oci8_int.h L134-168](https://github.com/php/pecl-database-oci8/blob/main/php_oci8_int.h)
- **DRCP**: `OCI_ATTR_CONNECTION_CLASS` and `OCI_ATTR_PURITY` (`PURITY_NEW`/`PURITY_SELF`) are set from `oci8.connection_class`. Pool stats come from `OCI_ATTR_SPOOL_BUSY_COUNT`/`OPEN_COUNT`. — [oci8 attributes grep](https://github.com/php/pecl-database-oci8/blob/main/oci8.c)
- **FAN**: `oci8.events` (boolean, PHP_INI_SYSTEM) turns on OCI events mode. **TAF**: `oci_register_taf_callback`/`oci_unregister_taf_callback` with `OCI_ATTR_FOCBK` and `OCI_FO_*` constants. — [oci8.c L181](https://github.com/php/pecl-database-oci8/blob/main/oci8.c), [oci8_failover.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_failover.c)
- **Prefetch**: `OCI_ATTR_PREFETCH_ROWS` via `oci8.default_prefetch`/`oci_set_prefetch`, also on REF CURSORs (refcur_prefetch tests). **LOB prefetch**: `OCI_ATTR_LOBPREFETCH_SIZE`/`LENGTH` via `oci_set_prefetch_lob` and `oci8.prefetch_lob_size`, compiled only for client ≥ 12.2 (`#if OCI_MAJOR_VERSION > 12 || (==12 && MINOR >= 2)`). — [oci8_interface.c L1641-1650](https://github.com/php/pecl-database-oci8/blob/main/oci8_interface.c), [oci8_statement.c L822](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- **Call timeout**: `oci_set_call_timeout` sets `OCI_ATTR_CALL_TIMEOUT` and requires client ≥ 18 (`#if (OCI_MAJOR_VERSION >= 18)`). — [oci8_interface.c L1873-1878](https://github.com/php/pecl-database-oci8/blob/main/oci8_interface.c)
- **Tracing / end-to-end**: `OCI_ATTR_CLIENT_IDENTIFIER`, `OCI_ATTR_MODULE`, `OCI_ATTR_ACTION`, `OCI_ATTR_CLIENT_INFO`, `OCI_ATTR_DBOP` (`oci_set_db_operation`), `OCI_ATTR_EDITION`, and `OCI_ATTR_DRIVER_NAME`. DTrace probes come from `oci8_dtrace.d`. — [oci8 sources](https://github.com/php/pecl-database-oci8)
- **No DML array binding**: `OCIStmtExecute` is called with `iters = 0` for SELECT and `iters = 1` otherwise, so there is no multi-row executemany. Fetch uses `OCIStmtFetch2(..., OCI_FETCH_NEXT, ...)` only, so there are no scrollable cursors; bulk fetch relies on prefetch. — [oci8_statement.c L269, L345, L534-550](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)
- **Missing OCI subsystems**: there are no calls to AQ (`OCIAQ*`), CQN/subscriptions (`OCISubscription*`), SODA, sharding keys, or `OCIJson*`. The full list of OCI functions used is in the [oci8 sources](https://github.com/php/pecl-database-oci8) (grep of `OCI[A-Z]\w+`).

```ini
; typical tuned php.ini for oci8
oci8.statement_cache_size = 50
oci8.default_prefetch     = 200
oci8.prefetch_lob_size    = 32768
oci8.connection_class     = MYAPP     ; DRCP: pair with (SERVER=POOLED) in connect string
oci8.events               = On        ; FAN (needs RAC/ADG + ONS)
oci8.ping_interval        = 60
```

### Inferences
- The client result cache (`RESULT_CACHE` hint / table annotation) is transparent in OCI but requires statement caching. oci8 uses OCIStmtPrepare2 with a cache, so it should benefit; PDO_OCI uses plain `OCIStmtPrepare` (section 3), so it likely cannot. This is based on Oracle's documented requirement, and no PHP-specific source was found.
- Because there is no executemany, bulk inserts in oci8 need either PL/SQL `FORALL` over `oci_bind_array_by_name` arrays or JSON/collection unpacking server-side. This is a meaningful gap compared with ODPI-C (`dpiStmt_executeMany`).

### Gaps
- No independent benchmark of oci8 prefetch/statement-cache gains was gathered for 2025-2026.
- Whether `oci8.events` FAN works with the 23ai/26ai client "Application Continuity" or Transparent Application Continuity was not checked. The oci8 code has no `OCIRequestBegin/End` calls, so AC request boundaries are not explicitly marked (inference from the grep).

---

## 3. PDO_OCI: what it supports vs. not, known bugs, Pdo\Oci subclass

### Takeaway
PDO_OCI is a thin, much weaker driver. It has no REF CURSOR binds (`PDO::PARAM_STMT` is rejected at bind time), no implicit results/nextRowset, no collections, no array binds, no statement cache, no session pool/DRCP class, and it ignores most `PDO::PARAM_*` types. It is fine for plain SQL, but not a basis for PL/SQL-heavy work. PHP 8.4+ has no `Pdo\Oci` subclass.

### Cited Findings
- **REF CURSOR not supported**: in the param-alloc hook, `case PDO_PARAM_STMT: return 0;`, which means binding a statement or cursor fails. — [oci_statement.c L338-340](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_statement.c)
- **Only STR and LOB are real bind types**: `PDO_PARAM_LOB` → `SQLT_BLOB` locator. Everything else (`PARAM_STR`, default, and therefore INT) → `SQLT_CHR` with a default size of 1332 bytes ("maximum size before value is interpreted as a LONG value"). — [oci_statement.c L342-356](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_statement.c)
- **BOOL**: `PDO_PARAM_BOOL` is bound as the characters `'1'`/`'0'` (string), not `SQLT_BOL`. — [oci_statement.c L230-238](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_statement.c)
  - Bug: "PDO OCI binds boolean false as NULL" was fixed via PR #29. — [pdo_oci #12](https://github.com/php/pecl-database-pdo_oci/issues/12)
- **PARAM_INT ignored** (open since 2023). SakiTakamachi: "The current pdo_oci is designed to ignore all PARAM_TYPE specifications. Fixing only the INT case is half-hearted, and fixing everything will have too much of an impact." — [pdo_oci #26](https://github.com/php/pecl-database-pdo_oci/issues/26)
- **NULL**: a PHP `null` is bound with indicator -1 (proper NULL). — [oci_statement.c L224-228](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_statement.c)
- **LOB fetchAll bug**: "pdo_oci pointing all records to the last resource blob when using fetchAll" is open, with PR #32 proposed. — [pdo_oci #27](https://github.com/php/pecl-database-pdo_oci/issues/27)
  - The 1.2.0 release notes say "Improved multiple binding and LOB handling". — [pdo_oci package.xml](https://github.com/php/pecl-database-pdo_oci/blob/main/package.xml)
- **Other recent bugs**:
  - `zend_mm_heap corrupted when using PDO::PARAM_INPUT_OUTPUT` (closed Oct 2025). — [#20](https://github.com/php/pecl-database-pdo_oci/issues/20)
  - "PHP 8.6.0RC3: crash when doing execute()+fetch_all() twice using the same prepared statement" (closed Oct 2026, fixed by Ilia Alshanetsky, commits "Fix crash when re-executing a statement after closeCursor()" and "Fix cursor cleanup for non-query statements"). — [#44](https://github.com/php/pecl-database-pdo_oci/issues/44), [commits](https://github.com/php/pecl-database-pdo_oci/commits/main)
  - "Output parameter bound to a typed property receives a string with no type check" (open, Oct 2026). — [#45](https://github.com/php/pecl-database-pdo_oci/issues/45)
  - "Version attribute does not return full version" (open). — [#43](https://github.com/php/pecl-database-pdo_oci/issues/43)
  - "possible crash with Apache 2.4.66 on Windows" (closed). — [#39](https://github.com/php/pecl-database-pdo_oci/issues/39)
- **No statement cache / no session pool**: PDO_OCI prepares with plain `OCIStmtPrepare(...)` (not `OCIStmtPrepare2`) and connects with `OCIServerAttach` + `OCISessionBegin`. There is no `OCISessionPoolCreate`, `OCI_ATTR_CONNECTION_CLASS`, `OCIStmtGetNextResult`, `OCIColl*` or `OCIObject*` call anywhere. — [oci_driver.c L270, L315](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_driver.c), grep of [pdo_oci sources](https://github.com/php/pecl-database-pdo_oci)
- **lastInsertId** is not supported (`NULL, /* last_id not supported */`). — [oci_driver.c L711](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_driver.c)
- **Driver attributes**:
  - Only `PDO::OCI_ATTR_ACTION`, `OCI_ATTR_CLIENT_INFO`, `OCI_ATTR_CLIENT_IDENTIFIER`, `OCI_ATTR_MODULE` and `OCI_ATTR_CALL_TIMEOUT`, plus generic `PDO::ATTR_PREFETCH`. — [oci_driver.c L453-634](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_driver.c)
  - On PHP ≥ 8.5 these constants are declared directly on the base `PDO` class (`php_pdo_get_dbh_ce()` + `zend_declare_class_constant_long`). On older versions they use `REGISTER_PDO_CLASS_CONST_LONG`. **No `Pdo\Oci` driver subclass is registered** (unlike `Pdo\Mysql`, `Pdo\Pgsql`, `Pdo\Sqlite` in PHP 8.4). — [pdo_oci.c L81-100](https://github.com/php/pecl-database-pdo_oci/blob/main/pdo_oci.c)
- **Column types fetched**: LONG/LONG RAW are handled piecewise, BLOB/CLOB become streams, DATE/NUMBER/ROWID/TIMESTAMP*/IBFLOAT/IBDOUBLE are defined as `SQLT_CHR` (strings). There is no `SQLT_RSET` define path, so nested `CURSOR()` columns are not supported. — [oci_statement.c L518-610, L856-880](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_statement.c)
- The OCI8 repo's issue #35 is about a "Placeholder rewriter [that] corrupts SQL inside Oracle q'…' literals." The rewriting of `?` to `:pN` described there is PDO's SQL scanner. — [pecl-database-oci8 #35](https://github.com/php/pecl-database-oci8/issues/35)

### Inferences
- For a PL/SQL-centric ecosystem, PDO_OCI cannot serve as the base. Calling a procedure that returns `SYS_REFCURSOR` needs either oci8 or a server-side workaround (pipelined function, or JSON CLOB OUT param).
- Because there is no `Pdo\Oci` subclass, there is no place to hang Oracle-specific methods (e.g. `getImplicitResults()`, `newCollection()`) in the modern PDO 8.4 model without core-level work in the PECL repo.

### Gaps
- The older bugs.php.net history (e.g. long-standing REF CURSOR feature requests against PDO_OCI) was not individually retrieved. The source code itself (`PDO_PARAM_STMT → return 0`) is the authoritative evidence.
- The exact behaviour of `PDO::PARAM_INPUT_OUTPUT` with numeric OUT params (string conversion) was not tested beyond the issue reports.

---

## 4. Packaging status (PHP 8.4 unbundling, versions, maintainers, PHP 8.5/8.6, installation)

### Takeaway
Since PHP 8.4, both drivers live outside php-src, in `php/pecl-database-oci8` and `php/pecl-database-pdo_oci`. They are maintained mainly by one Oracle employee (Sharad Chandran R), with community help (Michael Voříšek, Shivam Mathur, Ilia Alshanetsky). Releases are infrequent but they track new PHP versions (8.5 supported, 8.6 fixes landed). Distribution is moving from PECL to PIE.

### Cited Findings
- **RFC**: "Unbundle ext/imap, ext/pspell, ext/oci8" targeted PHP 8.4. The oci8/PDO_OCI vote passed **26 yes / 1 no** (voting 1-15 Nov 2023). Stated reasons: "Requires a proprietary library from large commercial enterprise", many open bugs, "Most of the recent changes were all to skip tests", and the resource→object refactor. — [RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)
- **oci8 releases**:
  - 3.4.1 (2025-12-23, PHP ≥ 8.2, "tested with PHP 8.2, PHP 8.3, PHP 8.4 and PHP 8.5"), 3.4.0 (2024-07-24), 3.3.0 (2023-04-20), 3.2.1 (2021-12-12, for PHP 8.1), 3.0.1 (2020-12-21, for PHP 8.0), 2.2.0 (2018-12-10, for PHP 7). — [PECL oci8](https://pecl.php.net/package/oci8), [package.xml](https://github.com/php/pecl-database-oci8/blob/main/package.xml)
  - Client requirement: "Requires Oracle Client libraries from 11.2 or later." — [package.xml](https://github.com/php/pecl-database-oci8/blob/main/package.xml)
- **PHP 8.6 readiness**: commit "PHP 8.6: WRONG_PARAM_COUNT has been removed..." (Shivam Mathur, 2025-12-18) is merged in oci8 main. pdo_oci was tested on 8.6.0RC3 (issue #44, Oct 2026). — [oci8 commits](https://github.com/php/pecl-database-oci8/commits/main), [pdo_oci #44](https://github.com/php/pecl-database-pdo_oci/issues/44)
- **pdo_oci releases**: 1.2.0 (2026-01-07), "for PHP 8.3 and newer versions. Improved multiple binding and LOB handling, Added PIE migration support"; minimum PHP 8.3.0. 1.1.0 was the earlier tag. — [pdo_oci package.xml](https://github.com/php/pecl-database-pdo_oci/blob/main/package.xml)
  - Request "new official release for php 8.5" closed Jan 2026. — [pdo_oci #28](https://github.com/php/pecl-database-pdo_oci/issues/28)
- **Maintainers**:
  - In package.xml, Sharad Chandran R (user `sharadchan87`) is the only `<active>yes</active>` lead. Christopher Jones (`sixd`) is listed `<active>no</active>`. composer.json lists Sharad with an `@oracle.com` email. — [package.xml](https://github.com/php/pecl-database-oci8/blob/main/package.xml), [composer.json](https://github.com/php/pecl-database-oci8/blob/main/composer.json)
  - **Conflict**: the PECL web page still lists Christopher Jones as "lead, active". — [PECL oci8](https://pecl.php.net/package/oci8)
  - Christopher Jones' recent public writing is on python-oracledb (e.g. the VECTOR post). — [Medium](https://medium.com/@cjones-oracle/python-oracledb-2-2-and-the-vector-type-in-oracle-database-23ai-7eb258a2ea3f)
  - Nearly all 2025-2026 oci8 commits are by Sharad Chandran R (packaging, tests, PIE) or community contributors (Michael Voříšek for leak fixes and CI, Shivam Mathur for CI and PHP 8.6). **No new Oracle-type features have been added since 3.4.0.** — [oci8 commit log](https://github.com/php/pecl-database-oci8/commits/main)
- **PIE**:
  - oci8 is published on Packagist as `oci8/oci8` (`pie install oci8/oci8`, "type": "php-ext", requires php ≥ 8.2). — [README](https://github.com/php/pecl-database-oci8/blob/main/README.md), [composer.json](https://github.com/php/pecl-database-oci8/blob/main/composer.json)
  - pdo_oci is published as `pecl/pdo_oci`. — [pdo_oci README](https://github.com/php/pecl-database-pdo_oci/blob/main/README.md)
  - The PECL page notes PECL is deprecated in favour of PIE. — [PECL oci8](https://pecl.php.net/package/oci8)
- **Installation pain points (GitHub issues)**:
  - Thread-safe builds fail with "OCIEnvNlsCreate() failed" (open). — [#37](https://github.com/php/pecl-database-oci8/issues/37)
  - Debian Trixie's 64-bit time transition affected the `libaio` dependency of Instant Client. — [#33](https://github.com/php/pecl-database-oci8/issues/33)
  - Windows DLL naming confusion (`php_oci8_19.dll` not recognised; "oci8-19 not enabled" warning). — [#34](https://github.com/php/pecl-database-oci8/issues/34), [#26](https://github.com/php/pecl-database-oci8/issues/26)
  - CRLF line endings in the 3.4.1/1.2.0 release tarballs (open). — [oci8 #46](https://github.com/php/pecl-database-oci8/issues/46), [pdo_oci #38](https://github.com/php/pecl-database-pdo_oci/issues/38)
  - Tests not compatible with Instant Client 23. — [#7](https://github.com/php/pecl-database-oci8/issues/7)
  - "Initial DB Connection Error in Data Affinity-Enabled 26ai RAC Environment with Oracle 26ai Client" (open, July 2026). — [#49](https://github.com/php/pecl-database-oci8/issues/49)
  - `OCI_ATTR_PASSWORD` length > 1024 (token/long-password scenario, open). — [#47](https://github.com/php/pecl-database-oci8/issues/47)
- **Windows**: precompiled DLLs exist on PECL for 3.4.1, 3.4.0, 3.3.0, 3.2.1, 3.0.1 and 2.2.0. — [PECL oci8](https://pecl.php.net/package/oci8)
- **Build**: `--with-oci8=instantclient,/path/to/lib` needs the Instant Client Basic/Basic Light plus SDK. — [README](https://github.com/php/pecl-database-oci8/blob/main/README.md), [composer.json configure-options](https://github.com/php/pecl-database-oci8/blob/main/composer.json)

### Inferences
- Bus factor is about 1 at Oracle, and Oracle's active driver investment is in python-oracledb, node-oracledb and ODPI-C, which get regular 23ai/26ai features (see section 5). A new PHP ecosystem should not assume Oracle will add JSON, VECTOR or ADT support to oci8.
- PIE plus prebuilt Docker images is the realistic installation story. Each image still has to ship Instant Client, which carries Oracle license terms and `libaio`.

### Gaps
- Status of Instant Client for macOS arm64 and its interplay with `pie install` on Apple Silicon: no source retrieved in this pass.
- Official or community Docker images (e.g. which popular PHP images bundle oci8) were not surveyed.
- No source was found on Oracle's formal support commitment (if any) for the PECL oci8 package.

---

## 5. Internals: OCI-direct vs ODPI-C, and the gap

### Takeaway
ext-oci8 and PDO_OCI call raw OCI directly, with no abstraction layer. Every new Oracle type or feature (OSON JSON, VECTOR, SQL BOOLEAN, ADTs, AQ, CQN, SODA, executemany, sessionless transactions, pipelining) must be hand-written against OCI in PHP's C code. ODPI-C, which python-oracledb thick mode, node-oracledb and godror build on, already wraps all of these and ships frequent releases. A 2023 request to move OCI8 onto ODPI-C was closed without action.

### Cited Findings
- **OCI calls used by oci8**:
  - Env, sessions and pools: `OCIEnvNlsCreate`, `OCIServerAttach`, `OCISessionBegin/End/Get/Release`, `OCISessionPoolCreate/Destroy`, `OCIPing`.
  - Statements: `OCIStmtPrepare2`, `OCIStmtRelease`, `OCIStmtExecute`, `OCIStmtFetch2`, `OCIStmtGetNextResult`, `OCIStmtGetPieceInfo/SetPieceInfo`.
  - Binds and defines: `OCIBindByName`, `OCIBindDynamic`, `OCIBindObject`, `OCIDefineByPos`, `OCIDefineDynamic`.
  - LOBs: `OCILob*`.
  - Objects and collections: `OCIColl*`, `OCIObjectNew/Free`, `OCITypeByName/ByRef`, `OCIDescribeAny`.
  - Number/date/string helpers: `OCINumberFromReal/ToReal`, `OCIDateFromText/ToText`, `OCIStringAssignText`.
  - Other: `OCIPasswordChange`, `OCITransCommit/Rollback`.
  — grep of [oci8 sources](https://github.com/php/pecl-database-oci8)
- **PDO_OCI uses a smaller, older set**: `OCIEnvNlsCreate`, `OCIServerAttach`, `OCISessionBegin`, `OCIStmtPrepare` (v1), `OCIStmtExecute`, `OCIStmtFetch` (v1), `OCIBindByName/ByPos`, `OCIBindDynamic`, `OCIDefineByPos/Dynamic`, `OCILob*` basics, and `OCITransCommit/Rollback`. — grep of [pdo_oci sources](https://github.com/php/pecl-database-pdo_oci)
- **ODPI-C request closed**: issue "Add support for Oracle's open source library (ODPI-C) in OCI8 extension" (filed 2023-02-14 in php-src, later moved). The reporter noted python-oracledb is "much easier" to get working; a core developer (Danack) argued Oracle should fund such work; the reporter asked to close it, and Sharad closed it "as per request". — [pecl-database-oci8 #48](https://github.com/php/pecl-database-oci8/issues/48)
- **ODPI-C cadence and features**:
  - Releases 5.0 (Aug 2023) through 5.6.4 (Nov 2025), 6.0.0 (2026-05-04) and **26.0.0 (2026-09-10)**, which moved to year-based versioning.
  - 6.0.0: dropped Oracle Client < 19c; added `dpiConn_setAppContext`/`clearAppContext`, `dpiConn_stmtFromHandle()` ("attach an external OCIStmt handle") and `dpiStmt_getHandle()`, `dpiConn_getPdbName()`, sparse vectors with zero indices, and AQ JSON payload fixes for 23ai.
  - 26.0.0: transaction priority, proxy users with token auth, and mandatory `libclntsh` symlink loading.
  — [ODPI-C releasenotes.rst](https://github.com/oracle/odpi/blob/main/doc/src/releasenotes.rst), [ODPI-C releases](https://github.com/oracle/odpi/releases)
- **ODPI-C covers what oci8 lacks**: the release notes reference `dpiVectorInfo` (VECTOR), `dpiQueue_deqMany` (AQ), `dpiSubscr_prepareStmt` (CQN), and SODA (`dpiSodaDb`). — [ODPI-C releasenotes.rst](https://github.com/oracle/odpi/blob/main/doc/src/releasenotes.rst)
- python-oracledb (Oracle's flagship driver, based on ODPI-C in thick mode) added VECTOR support in 2.2 for 23ai. — [Medium, C. Jones](https://medium.com/oracledevs/python-oracledb-2-2-and-the-vector-type-in-oracle-database-23ai-7eb258a2ea3f)

### Feature matrix (from the code evidence above; "ODPI-C" column per its release notes and API)

| Capability | ext-oci8 3.4.1 | PDO_OCI 1.2.0 | ODPI-C 26.0 |
|---|---|---|---|
| REF CURSOR OUT bind | Yes (OCI_B_CURSOR) | **No** (PARAM_STMT rejected) | Yes |
| Nested CURSOR() column | Yes | No | Yes |
| Implicit results | Yes (12c+) | No | Yes |
| PL/SQL index-by array bind | Scalars only (num/float/char/date) | No | Yes (incl. more types) |
| Nested table / VARRAY | Scalar elements only | No | Yes, incl. objects |
| Object types (ADT) / RECORD | **No** | No | Yes (`dpiObject`) |
| PL/SQL BOOLEAN bind | Yes (12c+) | Bound as '1'/'0' string | Yes |
| SQL BOOLEAN column (23ai) | Untyped fallback (SQLT_CHR) | Untyped fallback | Yes |
| JSON native (OSON) | No (text ≤32K via CHR fallback, inferred) | No | Yes |
| VECTOR (23ai) | No | No | Yes |
| LOB / temp LOB / BFILE | Full | Basic, with fetchAll bug | Full |
| DML array bind (executemany) | **No** (iters=1) | No | Yes |
| Scrollable cursors | No | No | Yes |
| Statement cache | Yes (OCIStmtPrepare2) | **No** (OCIStmtPrepare) | Yes |
| Session pool / DRCP class+purity | Yes | No | Yes |
| FAN / TAF | Yes / Yes | No / No | Yes |
| Call timeout | Yes (18c+) | Yes (attr) | Yes |
| Module/action/client id/dbop | Yes | Partial (no dbop) | Yes |
| AQ, CQN, SODA | No | No | Yes |
| Typed API (objects, not resources) | No (resources) | PDO objects | n/a (C) |
| Numbers returned as | strings | strings | native/configurable |

### Inferences
- Adding OSON JSON, VECTOR, ADTs or executemany to oci8 means writing hundreds of lines of raw OCI descriptor and object-cache code per feature. ODPI-C already encapsulates this and is maintained by Oracle's driver team with releases every 1-3 months.
- A modern PHP Oracle ecosystem has three foundation options:
  - (a) Build on top of ext-oci8 as-is, accepting the ADT/JSON/VECTOR gaps and using PL/SQL wrapper generation.
  - (b) Write a new PHP extension on ODPI-C. ODPI-C 6.0's `dpiConn_stmtFromHandle`/`dpiStmt_getHandle` hint at interop possibilities, but sharing raw OCI handles with oci8 is speculative.
  - (c) Use PHP FFI to ODPI-C as a prototype path.
- ODPI-C 6.0 requires a 19c+ client, while oci8 still supports 11.2+. A new ODPI-C-based extension would drop very old clients, which matches Oracle's support matrix.

### Gaps
- No performance comparison exists between oci8 (raw OCI) and an ODPI-C wrapper for PHP.
- No source was found for any community PHP-on-ODPI-C project (e.g. FFI bindings) in this pass. That needs a separate search.
- Licensing compatibility of ODPI-C (UPL/Apache 2.0 dual) with a PHP-licensed extension was not verified here.
