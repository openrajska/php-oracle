# yajra/laravel-oci8 + yajra/pdo-via-oci8: internals, architecture, workarounds and limitations (state as of Nov 2026)

Method note: both repositories were cloned at HEAD on 2026-11-19 (laravel-oci8 `f9fc97a` = v13.14.1, 2026-11-13; pdo-via-oci8 `b2547f2` = v3.9.0, 2026-11-13). Every code reference below was read directly from those sources. GitHub issues were read with `gh`; Packagist numbers come from the Packagist stats API on 2026-11-19.

Source URL shorthands:
- LO = https://github.com/yajra/laravel-oci8/blob/master/
- PVO = https://github.com/yajra/pdo-via-oci8/blob/master/

---

## 1. Architecture: how pdo-via-oci8 fakes PDO in userland on top of ext-oci8, and what that costs

### Takeaway
`Yajra\Pdo\Oci8 extends \PDO` and `Yajra\Pdo\Oci8\Statement extends \PDOStatement`. They override every method but never call `parent::__construct()`, so the native PDO machinery is never used. They exist only so that `instanceof PDO` checks and type hints in Laravel keep working. Every PDO concept is re-implemented in PHP on top of the `oci_*` functions: placeholders, fetch modes, attributes, transactions, errors and LOBs. The result is low fidelity. Several PDO methods throw, types are mostly stringly, LOBs are loaded eagerly, errors are plain strings with no SQLSTATE, and there is per-row PHP overhead.

### Cited Findings
**Why this design exists**
- The README states the rationale: the package "uses the tried and tested OCI8 functions instead of using the still experimental and not all that functional PDO_OCI library". — [PVO README](https://github.com/yajra/pdo-via-oci8/blob/master/README.md)
- I found no official PHP statement calling PDO_OCI "experimental" in 2026. PHP 8.4 unbundled both `oci8` and `pdo_oci` from core into PECL (`pecl install oci8`, `pecl install pdo_oci`), citing maintenance burden and the dependency on Oracle's proprietary client libraries. The `--with-oci8` and `--with-pdo-oci` configure flags were removed. — [php.watch PHP 8.4 OCI8/PDO_OCI unbundled](https://php.watch/versions/8.4/oci8-pdo_oci-unbundled); RFC linked from [LO issue #911](https://github.com/yajra/laravel-oci8/issues/911) (https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)

**Class skeleton and connection** (`src/Pdo/Oci8.php`, 694 lines)
- The class is declared as `class Oci8 extends PDO` with the docblock "This class extends PDO but overrides all of its methods. It does this so that instanceof checks and type-hinting of existing code will work seamlessly". The OCI connection resource sits in `private $dbh`. — [PVO src/Pdo/Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)
- The constructor parses a PDO-style DSN (`oci:host=..;port=..;dbname=..;charset=..`) with `parse_str` into an EZConnect string, or passes a TNS descriptor through unchanged. It then calls one of three functions:
  - `oci_pconnect` if `PDO::ATTR_PERSISTENT` is set
  - `oci_connect` by default (`'cached' => true`)
  - `oci_new_connect` if `cached = false`

  The charset defaults to `AL32UTF8`, and `utf8` is rewritten to `AL32UTF8`. Connection errors containing `'the password will expire within'` are silently ignored by default (`ignore_error_messages` option). — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)
- `oci_connect` caches and reuses the connection for identical credentials within a request, so two "separate" Laravel connections can share one Oracle session and its transaction. This was reported in [PVO #137 "Use oci_new_connect instead oci_connect"](https://github.com/yajra/pdo-via-oci8/issues/137) and is why the `cached` option exists.
- `getAttribute(PDO::ATTR_DRIVER_NAME)` returns the hard-coded string `'oci8'`, and `getAvailableDrivers()` injects `'oci'` into the list. `setAttribute()` just stores the value in an array and returns true, so no attribute is validated. — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)

**Placeholder rewriting**
- `prepare()` rewrites positional `?` placeholders to the pseudo-named placeholders `:p0, :p1, …`, because OCI8 only supports named binds. It uses a PCRE `(*SKIP)(*F)` regex that skips `'…'` strings with `''` escapes, Oracle `q'[…]'`-style quoted literals, `/* */` comments and `--` comments. The rewrite is skipped for statements starting with `alter table` or `create table`. `Statement::bindParam(1, …)` maps the 1-based integer index to `':p'.($i-1)`. — [PVO Oci8.php `rewritePositionalPlaceholders`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php), [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- This regex rewriter has a long bug history:
  - [PVO #37 "Question mark in string literal is replaced with pseudo named parameter"](https://github.com/yajra/pdo-via-oci8/issues/37) (2016)
  - [LO #933 "Placeholder rewriter corrupts SQL inside Oracle q'…' literals"](https://github.com/yajra/laravel-oci8/issues/933) (2025, closed)
  - [PVO #64 "bindParam using wrong number when using ? in statement"](https://github.com/yajra/pdo-via-oci8/issues/64)

**Regex sniffing inside `prepare()`**
- `prepare()` sniffs `insert into <table>` with a regex and stores the table name in `$this->table`. `lastInsertId()` later uses it. Every prepare also calls `oci_statement_type()` and regex-matches `\breturn(?:ing)?\b` to decide whether LOB locators may be replaced (`OPTION_MAY_REPLACE_LOB_LOCATOR`). — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)
- `lastInsertId($name = null)` assumes a sequence named `{$table}_id_seq` and runs two extra round trips:
  - `SELECT count(*) FROM ALL_SEQUENCES WHERE SEQUENCE_NAME=UPPER('$name') …`, with the name string-interpolated into the SQL
  - `SELECT $name.CURRVAL FROM DUAL`

  It returns 0 if the sequence is missing. — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)

**Transactions**
- Transactions are a PHP boolean flag. `beginTransaction()` only sets `$inTransaction = true` and sends nothing to Oracle. `Statement::execute()` uses `OCI_COMMIT_ON_SUCCESS` unless the flag is set, in which case it uses `OCI_DEFAULT`. So outside a "transaction" every statement auto-commits. `commit()` and `rollBack()` call `oci_commit` / `oci_rollback`. — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php), [PVO Statement.php `execute`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- If a statement binds a writable LOB, `execute()` forces `OCI_DEFAULT`. After execution it writes the LOB contents (`$blob->save(...)` or `oci_lob_copy`) and then calls `$this->connection->commit()` itself when no transaction is active. — [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)

**Binding: PDO type to OCI type mapping in `Statement::bindParam`**

| PDO / OCI type passed | OCI type used | Notes |
|---|---|---|
| `PARAM_BOOL` | `SQLT_INT` | |
| `PARAM_INT` | `SQLT_INT` | |
| `PARAM_NULL` | `SQLT_CHR` | |
| `PARAM_STR` | `SQLT_CHR` | |
| `PARAM_LOB` | `OCI_B_BLOB` | Creates a temporary BLOB descriptor via `writeTemporary`. Since 3.8.0, stream resources are copied in 1 MiB chunks. |
| `PARAM_STMT` | `OCI_B_CURSOR` | Calls `oci_new_cursor` |
| raw `SQLT_NTY` | `SQLT_NTY` | Calls `oci_new_collection($options['type_name'], $options['schema'])` |
| raw `SQLT_CLOB` | `OCI_B_CLOB` | Temporary CLOB |
| raw `SQLT_BOL` | `SQLT_BOL` | |
| anything else | `SQLT_CHR` | default |

  - `PDO::PARAM_INPUT_OUTPUT` is masked off (`$dataType &= ~PDO::PARAM_INPUT_OUTPUT`), and `maxLength = null` becomes `-1`.
  - Array values are routed to `oci_bind_array_by_name`.
  - Source: [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- `bindValue()` simply calls `bindParam()`, which binds by reference. The class keeps references alive in `$this->bindings[]` so the bound PHP variables are not garbage collected before execute. — [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- Binding bugs reported over the years:
  - SQLT_BOL support was missing until a request in [PVO #127](https://github.com/yajra/pdo-via-oci8/issues/127) and a follow-up bug in [PVO #131 "Undefined constant Yajra\Pdo\Oci8\SQLT_BOL"](https://github.com/yajra/pdo-via-oci8/issues/131). Note that `SQLT_BOL` only exists when ext-oci8 is built against Oracle client 12c or later.
  - [PVO #133 "Binding variable with ociType SQLT_NTY gets cleared"](https://github.com/yajra/pdo-via-oci8/issues/133)
  - [PVO #144 "$stmt->bindParam shifts default null values"](https://github.com/yajra/pdo-via-oci8/issues/144), open
  - [PVO #20 "Conversion of PDO INOUT param to OCI in 64-bit"](https://github.com/yajra/pdo-via-oci8/issues/20)

**Fetching**
- `fetch()` re-implements the fetch modes FETCH_BOTH, ASSOC, NUM, COLUMN, OBJ, INTO, CLASS and CLASS|PROPS_LATE on top of `oci_fetch_array($sth, $mode | OCI_RETURN_NULLS | OCI_RETURN_LOBS)`. Other modes throw `'Requested fetch mode is not supported by this implementation'`. — [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- Per row, in PHP:
  - `PDO::ATTR_CASE == CASE_LOWER` is emulated with `array_change_key_case`.
  - `ATTR_ORACLE_NULLS` is emulated.
  - For FETCH_OBJ and FETCH_CLASS, every column of every row calls `oci_field_type($sth, $idx+1)`. If the type is `NUMBER` and `ATTR_STRINGIFY_FETCHES` is off, the value is cast with `$value + 0`.
  - A `ROWID` column throws `'ROWID output is not yet supported. Please use ROWIDTOCHAR(ROWID)…'`.

  Source: [PVO Statement.php `fetch`, `castToNumeric`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- `fetchAll()` has a hidden behaviour. If the first value of a fetched row is a resource (a nested REF CURSOR column), it wraps that resource in a new `Statement`, executes it, and flattens its rows into the outer result set. — [PVO Statement.php `fetchAll`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)

**PDO methods not implemented, and other API bugs**
- `bindColumn()` throws `'bindColumn has not been implemented'`.
- `nextRowset()` and `debugDumpParams()` both throw, with the copy-paste message `'setFetchMode has not been implemented'`. There is therefore no support for multiple or implicit result sets. — [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- `Statement::getAttribute()` returns `[]` instead of null for unset attributes ([PVO #123](https://github.com/yajra/pdo-via-oci8/issues/123)). `ATTR_STATEMENT_CLASS` is not supported ([PVO #69](https://github.com/yajra/pdo-via-oci8/issues/69), open). `setFetchMode(FETCH_CLASS, X)` + `fetchAll()` returns stdClass ([PVO #120](https://github.com/yajra/pdo-via-oci8/issues/120), open). `getColumnMeta` ignores `CASE_LOWER` ([PVO #79](https://github.com/yajra/pdo-via-oci8/issues/79), open).
- `quote()` returns numeric strings unquoted and otherwise does `"'".str_replace("'", "''", $s)."'"`, with a `@todo Implement support for $paramType`. — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)

**Errors**
- `errorCode()` / `errorInfo()` always return SQLSTATE `'HY000'` on error and `'00000'` otherwise, so there is no real SQLSTATE mapping.
- `execute()` throws `Oci8Exception` with a multi-line message built from code, message, offset and statement text, plus the bound values (`'Bindings : [' . displayBindings() . ']'`).
- [PVO #50 "Request: improved support for error handling"](https://github.com/yajra/pdo-via-oci8/issues/50) is open since 2017.
- Sources: [PVO Statement.php `execute`, `displayBindings`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php); [PVO Oci8.php `errorInfo`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)

**Extra non-PDO methods** used by laravel-oci8
- `getNewCursor()`, `getNewDescriptor()`, `getNewCollection($type, $schema)`, `closeCursor($cursor)`, `setCallTimeout()`, `setClientIdentifier()`, `getResource()`, `getOptions()`. — [PVO Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)

**Recent activity in pdo-via-oci8**
- v3.8.0 (2026-10-17) added BLOB stream binding (PR #167).
- v3.9.0 (2026-11-13) added `scale` to `getColumnMeta` (PR #171).
- composer requires `php ^8.0`, `ext-pdo`, `ext-oci8`.
- Source: local `git log` of [yajra/pdo-via-oci8](https://github.com/yajra/pdo-via-oci8); [PVO composer.json](https://github.com/yajra/pdo-via-oci8/blob/master/composer.json)
- Open leak report: [PVO #156 "Possible memory leak with PHPUnit?"](https://github.com/yajra/pdo-via-oci8/issues/156) (2025, open).

### Inferences
- **The PDO facade is a compatibility costume, not an abstraction.** Laravel's `Connection` is coupled to `PDO`/`PDOStatement` signatures, so every OCI capability that PDO cannot express has to be smuggled through:
  - magic integer constants passed as the PDO type (`SQLT_NTY`, `SQLT_CLOB`, `SQLT_BOL`)
  - an `$options` array (`type_name`, `schema`)
  - out-of-band methods (`getNewCursor`)

  A new design should drop the PDO costume and expose a native OCI-first statement API, adding a Laravel `Connection` adapter on top.
- **Performance costs that follow from the design:**
  - PHP-level per-row loops (case folding, the NUMBER cast with one `oci_field_type` call per column per row, LOB `load()`)
  - extra round trips for `lastInsertId`
  - a regex rewrite plus `oci_statement_type` on every `prepare`

  A C or FFI-backed layer, or simply pushing the logic into `oci_fetch_all` and column-metadata caching, would remove most of this. This is inferred from the code; I found no benchmark comparing pdo-via-oci8 with raw oci8 or pdo_oci.
- **Fidelity bugs that follow from the design:**
  - Auto-commit by default and a flag-only transaction model: every statement outside `beginTransaction` commits immediately.
  - The silent implicit commit after LOB writes.
  - No SQLSTATE.
  - Bound values embedded in exception messages, which can leak PII or secrets into logs.

  These are design-level issues.

### Gaps
- I found no published benchmark measuring the overhead of the userland PDO emulation against raw `oci_*` or `pdo_oci`.
- I found no authoritative 2026 statement on the maintenance state or feature gaps of PECL `pdo_oci`, beyond its unbundling in PHP 8.4.

---

## 2. Laravel integration: Connection, Connector, Grammars, Processor, Builder, Sequence/Trigger, Eloquent

### Takeaway
laravel-oci8 is a thick dialect layer over Illuminate\Database, about 8.1k lines of PHP. The two grammars alone are about 3k lines. It handles four main concerns:
- **Identifiers:** uppercase quoted identifiers plus lowercase result keys.
- **Session settings:** NLS settings via `ALTER SESSION` on every connect.
- **Pagination:** OFFSET/FETCH only when the user declares `server_version >= 12c`; otherwise ROWNUM wrapping, which is the default because `server_version` defaults to `'11g'`.
- **Auto-increment:** emulated with SEQUENCE + TRIGGER pairs.

Many behaviours rely on hacks such as `debug_backtrace()` introspection, string-sniffing of SQL, and Oracle version gating from config rather than detection.

### Cited Findings
**Bootstrap: `Oci8ServiceProvider`**
- It registers `Connection::resolverFor('oracle', …)`, which runs these steps:
  1. Runs the optional `dynamic` config callable.
  2. Creates the PDO via `OracleConnector`. This happens eagerly; there is no lazy PDO closure.
  3. Wraps it in `Oci8Connection`.
  4. Unless `skip_session_vars` is set, runs `ALTER SESSION SET` with:
     - `NLS_TIME_FORMAT='HH24:MI:SS'`
     - `NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS'`
     - `NLS_TIMESTAMP_FORMAT='YYYY-MM-DD HH24:MI:SS'`
     - `NLS_TIMESTAMP_TZ_FORMAT='YYYY-MM-DD HH24:MI:SS TZH:TZM'`
     - `NLS_NUMERIC_CHARACTERS='.,'`
     - plus `sessionVars`, `CURRENT_SCHEMA` (from `schema`), `session` and `EDITION`.
- It also registers an `oracle` auth user provider. Config is merged from `config/oracle.php`.
- Source: [LO src/Oci8/Oci8ServiceProvider.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8ServiceProvider.php), [LO src/config/oracle.php](https://github.com/yajra/laravel-oci8/blob/master/src/config/oracle.php)
- `setSessionVars()` builds the SQL by string interpolation (`"$option  = '$value'"`, unquoted for CURRENT_SCHEMA and EDITION), with no escaping. — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)

**Connector: `Connectors/OracleConnector`**
- Default PDO options are `ATTR_CASE => CASE_LOWER`, `ERRMODE_EXCEPTION`, `ORACLE_NULLS => NULL_NATURAL`, `DEFAULT_FETCH_MODE => FETCH_OBJ`.
- It builds a full TNS descriptor string `(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=..)(PORT=..)) … (CONNECT_DATA=(SID=..|SERVICE_NAME=..)))` from host/port/database/service_name.
  - A multi-host config adds `(LOAD_BALANCE=yes)(FAILOVER=on)` and `SERVER=DEDICATED`.
  - It optionally adds `CONNECT_TIMEOUT` (11g+), `RETRY_COUNT`, `RETRY_DELAY` and `TRANSPORT_CONNECT_TIMEOUT` (12c+), and `EXPIRE_TIME` (19c+). Which of these are emitted is gated on the `server_version` config string.
- `OCI_CRED_EXT` session mode, for external or OS authentication, sets username to `/`.
- `createPdoConnection()` returns `new \Yajra\Pdo\Oci8(...)`.
- Source: [LO src/Oci8/Connectors/OracleConnector.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Connectors/OracleConnector.php)

**Server version is configured, not detected**
- `Oci8Connection::serverVersion()` returns `$this->getConfig('server_version') ?? '11g'`, and all the `isVersionAbove*` / `isVersionBelow*` checks compare against it. `getServerVersion()` (querying `v$version`) exists but is not used for gating. — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- Features gated by this string:
  - OFFSET/FETCH pagination (12c)
  - `TRUNCATE … CASCADE` (12c)
  - JSON query operators, which throw `'JSON query operations require Oracle 12c or newer.'` below 12c
  - native `json` column type (21c; otherwise `clob`)
  - identity-column introspection (12c+)
  - collation (12cR2)

  Source: [LO Query/Grammars/OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php), [LO Schema/Grammars/OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/Grammars/OracleGrammar.php)

**Pagination**
- `compileSelect` → `compileAnsiOffset`, two paths:
  - **If 12c+ and no lock:**
    - Rewrites the first `select` to `select /*+ FIRST_ROWS(n) */`. The hint is skipped when a lock is used, because it would raise ORA-02014.
    - Appends `offset N rows fetch next M rows only`.
  - **Otherwise:** wraps the query as
    ```
    select t2.* from ( select rownum AS "rn", t1.* from (<sql>) t1 ) t2 where t2."rn" between a and b
    ```
    For `limit 1` it uses `where rownum = 1`.
  - `compileLimit()` and `compileOffset()` return `''`.

  Source: [LO Query/Grammars/OracleGrammar.php `compileAnsiOffset`, `compileRowConstraint`, `compileTableExpression`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)
- `OracleProcessor::processSelect` strips the synthetic `rn` column from rows on pre-12c paths. — [LO Query/Processors/OracleProcessor.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Processors/OracleProcessor.php)
- Users report the ROWNUM wrapper as extremely slow. One example: 23 s for `paginate(25)` over 500k rows, against 0.8 s with `ROW_NUMBER() OVER (ORDER BY pk)`. — [LO #870 "Improving pagination performance"](https://github.com/yajra/laravel-oci8/issues/870) (open since 2024-07); also [LO #563](https://github.com/yajra/laravel-oci8/issues/563), [LO #632](https://github.com/yajra/laravel-oci8/issues/632)
- `OracleBuilder::getCountForPagination()` reads `$results[0]->AGGREGATE ?? ->aggregate`, with the in-code comment "to solve the Oracle issue: auto-convert field to uppercase". — [LO Query/OracleBuilder.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/OracleBuilder.php)

**Identifier case**
- `OracleGrammar::wrapValue()` upper-cases every identifier and double-quotes it (`'"'.Str::upper($value).'"'`). Combined with `PDO::CASE_LOWER` on fetch, users write lowercase names in PHP, the database stores uppercase, and results come back lowercase. — [LO Query/Grammars/OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)
- Consequences reported in issues:
  - Tables created with lowercase quoted names cannot be queried through the builder: [LO #743 "Errors when trying to query tables with lower cased names"](https://github.com/yajra/laravel-oci8/issues/743) (open) and [PVO #130](https://github.com/yajra/pdo-via-oci8/issues/130) (open).
  - Raw-query column aliases come back lowercased: [LO #1000 "Raw Query return the Column Name in Lower Case"](https://github.com/yajra/laravel-oci8/issues/1000).
- Case-insensitive search is an explicit opt-in toggle: `useCaseInsensitiveSession()` sets `NLS_COMP=LINGUISTIC, NLS_SORT=BINARY_CI`. A past default of LINGUISTIC caused severe slowdowns because it disables normal index use ([LO #602 "NLS_COMP = LINGUISTIC is a bad feature"](https://github.com/yajra/laravel-oci8/issues/602)); the maintainer said he planned to revert it. — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)

**Identifier length**
- `max_name_len` defaults to 30, the pre-12.2 limit, and is configurable (12.2+ allows 128). It is used to truncate auto-generated sequence, trigger and index names with `mb_substr`. — [LO config/oracle.php](https://github.com/yajra/laravel-oci8/blob/master/src/config/oracle.php), [LO Schema/OracleAutoIncrementHelper.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/OracleAutoIncrementHelper.php); related: [LO #542 "Length of index names in Oracle 12.2 or higher"](https://github.com/yajra/laravel-oci8/issues/542)

**IN-list limit (ORA-01795, 1000 items)**
- `OracleBuilder::whereIn()` checks `count($values) > 1000`. If so, it `array_chunk`s the values into groups of 1000 and nests them in a closure as `orWhereIn` chunks, or `whereNotIn` AND-ed chunks for NOT IN.
- `OracleGrammar::whereInRaw` / `whereNotInRaw` do the same for integer raw lists via `resolveClause`.
- Each value is still a separate bind variable.
- Source: [LO Query/OracleBuilder.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/OracleBuilder.php), [LO Query/Grammars/OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)
- This is a long-standing pain point, especially with Eloquent eager loading:
  - [LO #73 "Eager loading and ORA-01795"](https://github.com/yajra/laravel-oci8/issues/73)
  - [LO #479](https://github.com/yajra/laravel-oci8/issues/479)
  - [LO #399](https://github.com/yajra/laravel-oci8/issues/399)
  - [LO #590 "whereIn with more than two thousand records doesn't work"](https://github.com/yajra/laravel-oci8/issues/590)

**Inserts and IDs**
- `compileInsertGetId` appends `returning "ID" into ?`. `Oci8Connection::insertGetId` binds an extra OUT param with `$statement->bindParam(count($bindings)+1, $id, PDO::PARAM_INT, -1)`. The fix "run insertGetId through Laravel query lifecycle" landed in v13.14.1 (2026-11-13), so before that it bypassed Laravel's query events and lifecycle. — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php); `git log` of LO
- Both `compileInsertGetId` and `OracleProcessor::incrementBySequence` use `debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, …)[2|3]['object']` to find the calling Eloquent builder and model, so they can read `$model->sequence` / `$model->incrementing` and inject the sequence's next value. — [LO Query/Grammars/OracleGrammar.php `compileInsertGetId`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php), [LO Query/Processors/OracleProcessor.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Processors/OracleProcessor.php)
- Batch insert compiles to `insert into t (cols) select ?,? from dual union all select ?,? from dual …`. It does not use INSERT ALL or array binding. — [LO Query/Grammars/OracleGrammar.php `compileInsert`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)
- Upsert and `insertOrIgnore` compile to `MERGE INTO … USING (select … from dual union all …)`. `insertOrIgnore` was a long-open request ([LO #772](https://github.com/yajra/laravel-oci8/issues/772)). — [LO Query/Grammars/OracleGrammar.php `compileUpsert`, `compileInsertOrIgnore`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)
- Joined updates are compiled via ROWID (`compileUpdateWithJoinsUsingRowId`). Lock compiles to `for update`, or a raw string such as `for update skip locked` passed manually; [LO #876 "Lock for update implementation"](https://github.com/yajra/laravel-oci8/issues/876) is open. — same file

**Binding of all values**
- `Oci8Connection::bindValues()` binds every value as `PDO::PARAM_STR`, except strings longer than 3999 bytes, which are bound as `SQLT_CLOB`:
  ```php
  $statement->bindValue(is_string($key) ? $key : $key + 1, $value,
      is_string($value) && strlen($value) > 3999 ? SQLT_CLOB : PDO::PARAM_STR);
  ```
  So ints, floats, booleans and DateTimes all go to Oracle as VARCHAR2 and rely on implicit conversion through the NLS formats set at connect. — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- Historic CLOB bind errors: [LO #363 "Error saving field of type CLOB" (ORA-01461, 21 comments)](https://github.com/yajra/laravel-oci8/issues/363), [LO #756](https://github.com/yajra/laravel-oci8/issues/756).

**Dates**
- `setDateFormat()` re-issues `ALTER SESSION SET NLS_DATE_FORMAT / NLS_TIMESTAMP_FORMAT`. `getDateFormat()` reads `sessionVars.NLS_DATE_FORMAT` from config. Date correctness depends on session NLS rather than typed binds. — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- Related bugs: [LO #964 "whereDate() cannot be used for greater than/less than queries"](https://github.com/yajra/laravel-oci8/issues/964) (2026), [LO #470](https://github.com/yajra/laravel-oci8/issues/470).

**Schema / DDL**
- Auto-increment works as follows. `OracleAutoIncrementHelper::createAutoIncrementObjects` creates a sequence `{table}_{col}_seq` and a trigger `{table}_{col}_trg`, truncated to `max_name_len`:
  ```sql
  create trigger … before insert on T for each row begin
    if :new.ID is null then select SEQ.nextval into :new.ID from dual; end if;
  end;
  ```
  Native identity columns (`generated by default [on null] as identity`) are only used when the Blueprint column has `generatedAs`.

  The drop logic finds the PK column via an `all_constraints` query (string-interpolated) and drops the trigger inside an anonymous PL/SQL block that swallows ORA-04080. Source: [LO Schema/OracleAutoIncrementHelper.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/OracleAutoIncrementHelper.php), [LO Schema/Trigger.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/Trigger.php), [LO Schema/Grammars/OracleGrammar.php `modifyGeneratedAs`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/Grammars/OracleGrammar.php)
- Related bug: [LO #965 "db:wipe fails with ORA-32794: cannot drop a system-generated sequence"](https://github.com/yajra/laravel-oci8/issues/965) (2026).
- Type mapping:

  | Laravel type | Oracle type |
  |---|---|
  | `string` | `varchar2(n)` |
  | `text` / `longText` | `clob` |
  | `boolean` | `char(1)` |
  | `uuid` | `char(36)` |
  | `binary` | `blob` |
  | `json` / `jsonb` | `json` if 21c+, else `clob` (constrained) |
  | `dateTimeTz` | `timestamp with time zone` |

  Source: [LO Schema/Grammars/OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/Grammars/OracleGrammar.php)
- Schema transactions were disabled in 2026 ("fix: disable Oracle schema transactions"), because Oracle DDL auto-commits. — LO `git log`, 2026-07-26
- `Sequence` helper class: `create`, `drop`, `nextValue`, `currentValue`, `exists`. `Oci8Connection::getSequence()` / `getTrigger()` expose these helpers. — [LO Schema/Sequence.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/Sequence.php)

**Schema prefix**
- The `prefix_schema` config forces a `SCHEMA.` prefix on tables, and `setSchema()` runs `ALTER SESSION SET CURRENT_SCHEMA`. Laravel 12's schema rework produced breaking changes: [LO #905 "ORA-00942 … after update to v12.0.0" (26 comments)](https://github.com/yajra/laravel-oci8/issues/905), where the maintainer said "Using prefix_schema config will always append the schema name in the query, a breaking change in v12"; [LO #919](https://github.com/yajra/laravel-oci8/issues/919).
- Laravel 13 regression: [LO #980 "Schema::hasTable() hangs indefinitely on Oracle 19c — v13.0.0" (24 comments)](https://github.com/yajra/laravel-oci8/issues/980).

**Eloquent: `Eloquent/OracleEloquent`**
- It is an optional base model with:
  - a `public $sequence` property, plus `nextValue()`, `getSequenceName()`, `setSequenceName()`
  - a `protected $binaries = []` list of BLOB columns

  When binaries are dirty, `performInsert` / `performUpdate` divert to `insertLob` / `updateLob`. Those compile to:
  ```sql
  insert … values (…, EMPTY_BLOB()) returning BLOBCOL, ID into ?, ?
  ```
  and the processor then binds `PDO::PARAM_LOB` descriptors (`OracleProcessor::saveLob`). Source: [LO Eloquent/OracleEloquent.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Eloquent/OracleEloquent.php), [LO Query/Grammars/OracleGrammar.php `compileInsertLob`/`compileUpdateLob`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php), [LO Query/Processors/OracleProcessor.php `saveLob`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Processors/OracleProcessor.php)
- Issues with this model:
  - [LO #998 "OracleEloquent public $sequence property shadows a sequence column"](https://github.com/yajra/laravel-oci8/issues/998) (2026)
  - [LO #784 "$model->update() BLOB do not work, $model->fill()->save() yes"](https://github.com/yajra/laravel-oci8/issues/784)

**Reading LOBs**
- LOBs are always loaded eagerly into strings (`OCI_RETURN_LOBS` + `$lob->load()`) unless a single column is fetched with FETCH_COLUMN. — [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- [LO #700 "Low performance with CLOB columns"](https://github.com/yajra/laravel-oci8/issues/700): 5 s for 200 rows. The maintainer replied "CLOB / BLOB are loaded as an object … `$lob->load()` which I think is the reason … Open to suggestions".

**Other integration points**
- `causedByLostConnection` matches ORA-03113, 03114, 03135, 12170, 12537, 27146, 25408 and 56600, plus user-configured `reconnect_errors`.
- `isUniqueConstraintError` matches `ORA-00001`.
- `threadCount()` swallows ORA-00942 on `V$SESSION`.
- `OracleSchemaState` handles schema dumps.
- `Oci8DatabasePresenceVerifier` handles validation.
- There are PHPStan extensions for the `DB` facade.
- Source: [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)

### Inferences
- **Hacks a new design should avoid:**
  - `debug_backtrace()` for model discovery
  - string-sniffed `insert into` for `lastInsertId`
  - all values bound as VARCHAR2
  - version gating from a config string that defaults to `11g`, which leaves new installs on ROWNUM pagination unless users know to set `DB_SERVER_VERSION`
  - an eager connection plus 1 extra `ALTER SESSION` round trip per connection
  - triggers for auto-increment

  Each of these has a modern replacement: typed binds, identity columns (12c+), `RETURNING INTO`, version detection via `oci_server_version()` (or detection at first use), and `FETCH FIRST`.
- **Case folding ties the package to unquoted-uppercase schemas.** Folding identifiers up and keys down is the root cause of a whole class of bugs: lowercase or mixed-case objects, raw query alias casing, `AGGREGATE` vs `aggregate`.
- **The IN-list workaround only addresses the 1000-element limit.** Very large lists still create one bind per value and produce huge statements that defeat cursor sharing. A collection bind (`TABLE(:arr)` with `SYS.ODCINUMBERLIST` / `ODCIVARCHAR2LIST`) or a GTT would be the modern fix. This is my inference; neither package offers it.

### Gaps
- I did not verify how often users actually set `server_version` in production, or whether the docs prominently warn about it.
- I did not run the code, so the performance claims come from issues and code reading rather than measurement.

---

## 3. PL/SQL support: procedures, functions, OUT/IN OUT, REF CURSOR, collections, BOOLEAN, implicit results, pipelined

### Takeaway
PL/SQL support is three thin string-building helpers on `Oci8Connection` (`executeProcedure`, `executeFunction`, `executeProcedureWithCursor`) plus the escape hatch of raw `DB::getPdo()->prepare('BEGIN … END;')` with `bindParam`. The helpers support only named binds, at most one REF CURSOR, and no OUT values returned to the caller. Anything richer must be hand-coded, including:
- multiple cursors
- collections and object types
- records
- implicit result sets (`DBMS_SQL.RETURN_RESULT`)
- typed IN OUT
- PL/SQL associative arrays

### Cited Findings
**Implementation of the helpers** ([LO src/Oci8/Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)):
```php
public function executeProcedure(string $procedureName, array $bindings = []): bool
{
    $stmt = $this->createStatementFromProcedure($procedureName, $bindings);
    $stmt = $this->addBindingsToStatement($stmt, $bindings);
    return $stmt->execute();
}

public function executeFunction(string $functionName, array $bindings = [],
    int $returnType = PDO::PARAM_STR, ?int $length = null): mixed
{
    $stmt = $this->createStatementFromFunction($functionName, $bindings); // 'begin :result := %s(%s); end;'
    $stmt = $this->addBindingsToStatement($stmt, $bindings);
    $stmt->bindParam(':result', $result, $returnType, $length);
    $stmt->execute();
    return $result;
}

public function executeProcedureWithCursor($procedureName, array $bindings = [], string $cursorName = ':cursor'): array
{
    $stmt = $this->createStatementFromProcedure($procedureName, $bindings, $cursorName);
    $stmt = $this->addBindingsToStatement($stmt, $bindings);
    $cursor = null;
    $stmt->bindParam($cursorName, $cursor, PDO::PARAM_STMT);
    $stmt->execute();
    $statement = new Statement($cursor, $this->getPdo(), $this->getPdo()->getOptions());
    $statement->execute();
    $results = $statement->fetchAll(PDO::FETCH_OBJ);
    $statement->closeCursor();
    return $results;
}

public function createSqlFromProcedure(string $procedureName, array $bindings, bool|string $cursor = false): string
{
    $paramsString = implode(',', array_map(fn ($param) => ':'.$param, array_keys($bindings)));
    $prefix = count($bindings) ? ',' : '';
    $cursor = $cursor ? $prefix.$cursor : null;
    return sprintf('begin %s(%s%s); end;', $procedureName, $paramsString, $cursor);
}
```
- How the SQL is built:
  - It is positional: `begin proc(:a,:b,:cursor); end;`, in array-key order. It is not named notation (`p => :p`), so the array order must match the parameter order. See [LO #482 "Can we use named parameters in Procedures?"](https://github.com/yajra/laravel-oci8/issues/482).
  - The cursor is always appended as the last argument.
  - The procedure name is interpolated raw with `sprintf`.
- `addBindingsToStatement()` accepts either a scalar or an array `['value'=>…, 'type'=>…, 'length'=>…, 'options'=>…]` per binding. `options` is forwarded to `bindParam`, e.g. `type_name` / `schema` for `SQLT_NTY`. Driver options were ignored until a fix: [LO #813](https://github.com/yajra/laravel-oci8/issues/813). — [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- **OUT values are lost with `executeProcedure`:**
  - `executeProcedure(string $procedureName, array $bindings)` takes `$bindings` by value. `addBindingsToStatement` binds references into its own local copy, and the method returns only `bool`. So OUT values never reach the caller.
  - The official docs nevertheless show this example:
    ```php
    $bindings = ['p_price'=>19.99,'p_quantity'=>5,'p_total'=>null];
    DB::executeProcedure('calculate_total',$bindings);
    // The total is now in $bindings['p_total']
    ```
  - The docs also show `DB::executeProcedure('get_user', ['p_user_id'=>123])` for a procedure with an `OUT SYS_REFCURSOR`, which binds no cursor and would not compile to a matching call.

  Sources: [yajrabox docs: stored-procedure](https://yajrabox.com/docs/laravel-oci8/master/stored-procedure) (docs) versus [LO Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php) (code). My reading of the code contradicts the docs; I did not execute it.
- Officially documented manual patterns (via the PDO facade):
  ```php
  $pdo = DB::getPdo();
  $p1 = 8;
  $stmt = $pdo->prepare("BEGIN myproc(:p1, :p2); END;");
  $stmt->bindParam(':p1', $p1, PDO::PARAM_INT);
  $stmt->bindParam(':p2', $p2, PDO::PARAM_INT);
  $stmt->execute();          // $p2 === 16

  // IN OUT
  $stmt = $pdo->prepare("BEGIN double_value(:p_value); END;");
  $stmt->bindParam(':p_value', $value, PDO::PARAM_INT | PDO::INPUT_OUTPUT);
  ```
  Also documented: wrapping a call in `DB::transaction(fn() => DB::executeProcedure(...))`. Note that the docs write `PDO::INPUT_OUTPUT`; the real constant is `PDO::PARAM_INPUT_OUTPUT`, and the driver masks it off anyway. — [yajrabox docs: stored-procedure](https://yajrabox.com/docs/laravel-oci8/master/stored-procedure); [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- **REF CURSOR**:
  - Only one cursor is supported per helper call. For more cursors the maintainer says to execute manually ([LO #452 "Execute stored procedure with multiple cursors"](https://github.com/yajra/laravel-oci8/issues/452), where a user wrote their own `ODB` class).
  - Other cursor issues: [LO #197](https://github.com/yajra/laravel-oci8/issues/197), [LO #219](https://github.com/yajra/laravel-oci8/issues/219), [LO #732 "Stored Procedures with only output"](https://github.com/yajra/laravel-oci8/issues/732) (closed by stale bot without an answer), [PVO #76 "How to call Procedures with cursor???"](https://github.com/yajra/pdo-via-oci8/issues/76) (open).
  - Passing a cursor IN to a procedure is not supported: [LO #554](https://github.com/yajra/laravel-oci8/issues/554).
  - Cursor rows are fully materialized with `fetchAll(FETCH_OBJ)`; there is no streaming or lazy iteration.
- **Collections and object types (`SQLT_NTY`)**:
  - Possible only by passing `SQLT_NTY` as the "PDO type" with `options['type_name']`. The driver then calls `oci_new_collection`, and you get back a raw `OCICollection` object that you must walk manually (`->size()`, `->getElem()`).
  - Object types (`CREATE TYPE … AS OBJECT`) and records cannot be bound at all, because ext-oci8 itself only supports collections of scalars.
  - Users stuck on this: [LO #412 "Oci8 bindParam output parameter of type SQLT_NTY"](https://github.com/yajra/laravel-oci8/issues/412), where the output was `object(OCI-Collection)` with no guidance and the maintainer pointed to Stack Overflow; also [PVO #73](https://github.com/yajra/pdo-via-oci8/issues/73), [PVO #133](https://github.com/yajra/pdo-via-oci8/issues/133), [PVO #39 "ORA-00932 with tabletype columns"](https://github.com/yajra/pdo-via-oci8/issues/39) (open since 2016).
- **PL/SQL index-by arrays**: array PHP values are routed to `oci_bind_array_by_name` in `bindParam`, but `bindArray` always passes the max table length as `count($variable)`, so OUT arrays larger than the input cannot be returned. — [PVO Statement.php `bindArray`](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)
- **BOOLEAN**:
  - `SQLT_BOL` can be passed as a raw type since about 2023 ([PVO #127](https://github.com/yajra/pdo-via-oci8/issues/127), [#131](https://github.com/yajra/pdo-via-oci8/issues/131)).
  - `PDO::PARAM_BOOL` maps to `SQLT_INT`, not to a PL/SQL BOOLEAN.
  - Schema `boolean` is `char(1)`, not the Oracle 23ai `BOOLEAN` SQL type.
  - Historic boolean issues: [LO #20](https://github.com/yajra/laravel-oci8/issues/20), [LO #122 "Boolean false being translated to null"](https://github.com/yajra/laravel-oci8/issues/122).

  Sources: [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php), [LO Schema/Grammars/OracleGrammar.php `typeBoolean`](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/Grammars/OracleGrammar.php)
- **Implicit result sets** (12c `DBMS_SQL.RETURN_RESULT`): unsupported. `Statement::nextRowset()` throws, and there is no use of `oci_get_implicit_resultset()` in either package. — [PVO Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php); grep of both repos
- **Pipelined functions / `TABLE()`**: there is no dedicated API. They work only as raw SQL (`DB::select('select * from table(pkg.fn(?))', [...])`), and the grammar has no `fromTable()` / `fromFunction()` helper. — grep of [LO Query/OracleBuilder.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/OracleBuilder.php) and the grammar; no matches for `pipelined` / `table(` helpers.
- **Packages**: package procedures are called by passing `'pkg.proc'` as the name. — [yajrabox docs: stored-procedure](https://yajrabox.com/docs/laravel-oci8/master/stored-procedure)
- **Documentation gap**: the stored-procedure-with-cursor docs URL I tried returned 404; the main stored-procedure page only links to the function and cursor pages. — [yajrabox docs](https://yajrabox.com/docs/laravel-oci8/master/stored-procedure)

### Inferences
- **PL/SQL is the weakest area by far.** A modern replacement should offer a typed call builder that:
  - uses named notation (`p_x => :p_x`)
  - declares per-parameter direction, type and size (IN, OUT, IN OUT)
  - returns OUT values in a result object
  - supports N REF CURSORs as lazy iterators
  - supports implicit results (`oci_get_implicit_resultset`)
  - supports `SQLT_BOL` and the 23ai BOOLEAN
  - provides collection hydration helpers (OCICollection → PHP array) and record/object bridging (for example via generated wrapper PL/SQL or JSON marshalling, since ext-oci8 cannot bind objects)
  - provides `TABLE(fn())` query-builder sources
- **Eager cursor materialization blocks memory-safe large exports.** The cursor helper's `fetchAll` plus `FETCH_OBJ` plus the nested-cursor flattening in `fetchAll` make memory-safe large exports impossible through the helper.

### Gaps
- I could not retrieve the separate yajrabox docs pages for functions and cursors (404 on my guessed URL), so their exact examples are not quoted here.
- I did not test whether `executeProcedure` OUT values work in practice. The finding is based on PHP by-value semantics in the source.

---

## 4. Known issues and recurring problems (GitHub issues/discussions)

### Takeaway
Recurring themes in the issues:
- pagination performance
- the ORA-01795 IN-list limit
- CLOB/BLOB binding and read performance
- cursor and OUT-param usability
- identifier case
- schema-prefix regressions on each major Laravel bump (v12 and v13)
- placeholder-rewrite bugs
- transaction surprises
- PHP 8.x signature churn
- PHP 8.4 unbundling worries

Many PL/SQL questions get closed by a stale bot rather than being solved.

### Cited Findings
- **Pagination slowness**: [LO #870](https://github.com/yajra/laravel-oci8/issues/870) (open, ROWNUM 23 s vs ROW_NUMBER 0.8 s), [LO #563 "Pagination is extremely slow on larger datasets"](https://github.com/yajra/laravel-oci8/issues/563), [LO #632](https://github.com/yajra/laravel-oci8/issues/632).
- **ORA-01795**: [LO #73](https://github.com/yajra/laravel-oci8/issues/73), [LO #399](https://github.com/yajra/laravel-oci8/issues/399), [LO #464](https://github.com/yajra/laravel-oci8/issues/464), [LO #479](https://github.com/yajra/laravel-oci8/issues/479), [LO #590](https://github.com/yajra/laravel-oci8/issues/590).
- **LOBs**:
  - [LO #363 ORA-01461 CLOB (21 comments)](https://github.com/yajra/laravel-oci8/issues/363)
  - [LO #313 "CLOB load() error ORA-24812"](https://github.com/yajra/laravel-oci8/issues/313)
  - [LO #700 CLOB read performance](https://github.com/yajra/laravel-oci8/issues/700)
  - [LO #756 ORM insert CLOB > 4000 chars](https://github.com/yajra/laravel-oci8/issues/756)
  - [LO #784 BLOB update](https://github.com/yajra/laravel-oci8/issues/784)
  - [PVO #24 "CLOBS that have more than 32.767 characters"](https://github.com/yajra/pdo-via-oci8/issues/24)
  - [PVO #119 "Call to a member function save() on null"](https://github.com/yajra/pdo-via-oci8/issues/119) (open)
- **Procedures and cursors**: [LO #452](https://github.com/yajra/laravel-oci8/issues/452), [LO #482](https://github.com/yajra/laravel-oci8/issues/482), [LO #520](https://github.com/yajra/laravel-oci8/issues/520), [LO #554](https://github.com/yajra/laravel-oci8/issues/554), [LO #732](https://github.com/yajra/laravel-oci8/issues/732), [LO #412](https://github.com/yajra/laravel-oci8/issues/412), [LO #813](https://github.com/yajra/laravel-oci8/issues/813), [PVO #76](https://github.com/yajra/pdo-via-oci8/issues/76).
- **Transactions**:
  - [LO #501 "DB rollBack not working properly"](https://github.com/yajra/laravel-oci8/issues/501): an update was persisted despite `DB::rollBack()`.
  - [LO #33 "Multiple connections on rollback"](https://github.com/yajra/laravel-oci8/issues/33).
  - [LO #892 "ORA-02396: exceeded maximum idle time"](https://github.com/yajra/laravel-oci8/issues/892).
  - [PVO #137](https://github.com/yajra/pdo-via-oci8/issues/137): `oci_connect` caching shares sessions.
- **Upgrades and regressions**: [LO #905](https://github.com/yajra/laravel-oci8/issues/905) (v12 `prefix_schema` break), [LO #919](https://github.com/yajra/laravel-oci8/issues/919), [LO #980](https://github.com/yajra/laravel-oci8/issues/980) (v13 `hasTable` hang), [LO #891 "Randomly ORA-00907"](https://github.com/yajra/laravel-oci8/issues/891), [LO #915 "withExists/withCount Oracle Compatibility Issue"](https://github.com/yajra/laravel-oci8/issues/915), [LO #659 getSchemaState missing](https://github.com/yajra/laravel-oci8/issues/659).
- **PHP 8.x compatibility in pdo-via-oci8**:
  - [PVO #82 "PHP8 Compatibility"](https://github.com/yajra/pdo-via-oci8/issues/82)
  - [PVO #86](https://github.com/yajra/pdo-via-oci8/issues/86): `PDO::query` signature incompatibility in PHP 8
  - [PVO #108](https://github.com/yajra/pdo-via-oci8/issues/108): reset() on object deprecated in 8.1
  - [PVO #109 "PHP 8.1 Support"](https://github.com/yajra/pdo-via-oci8/issues/109)
  - [PVO #87 "always empty collection by binding to statement in php 8"](https://github.com/yajra/pdo-via-oci8/issues/87) (open)

  These follow from extending `PDO`: every change to a PDO signature in core breaks the subclass.
- **PHP 8.4 unbundling**:
  - [LO #911 "OCI8 and PHP 8.4 Unbundled"](https://github.com/yajra/laravel-oci8/issues/911): a user reports that `pdo_oci` (PECL) conflicts with `ldap` on 8.4 via `libclntsh.so`. The maintainer replied: "I will maintain the package as long as it's maintainable as we heavily use Oracle in my work … I think OCI8 will still be maintained as a recent update now supports ARM chipsets."
  - [LO #948 "Connection/response issue … after activating the oci8 extension for PHP 8.4"](https://github.com/yajra/laravel-oci8/issues/948).
  - Current composer requires `ext-oci8 >=3.0.1`. — [LO composer.json](https://github.com/yajra/laravel-oci8/blob/master/composer.json)
- **Identifier case**: [LO #743](https://github.com/yajra/laravel-oci8/issues/743) (open), [PVO #130](https://github.com/yajra/pdo-via-oci8/issues/130) (open), [LO #1000](https://github.com/yajra/laravel-oci8/issues/1000).
- **Missing features (open)**: [LO #876 lock for update](https://github.com/yajra/laravel-oci8/issues/876), [LO #806 materialized view](https://github.com/yajra/laravel-oci8/issues/806), [LO #296 DB health check](https://github.com/yajra/laravel-oci8/issues/296), [PVO #132 compound primary key](https://github.com/yajra/pdo-via-oci8/issues/132), [PVO #50 error handling](https://github.com/yajra/pdo-via-oci8/issues/50).
- **Issue hygiene**: a github-actions stale bot closes issues after 30+7 days of inactivity (seen on [LO #732](https://github.com/yajra/laravel-oci8/issues/732)).

### Inferences
- **Root causes.** The volume of LOB, cursor and case issues over about 12 years shows these are structural limitations rather than bugs. The PDO-emulation layer cannot represent OCI types cleanly, and the case-folding strategy is fundamental to the package.
- **PHP 8.4 risk.** Unbundling to PECL adds install friction (Docker images, Windows DLLs, Instant Client linking). It does not by itself break laravel-oci8, which depends only on ext-oci8, not on pdo_oci.

### Gaps
- I did not systematically search Stack Overflow, Laracasts or blogs; findings are from GitHub issues.
- I did not quantify issue counts per theme.

---

## 5. Current status (November 2026): versions, support matrix, maintenance, downloads

### Takeaway
Both packages are actively maintained as of November 2026:
- laravel-oci8 v13.14.1 (2026-11-13) for Laravel 13 / PHP ^8.3
- pdo-via-oci8 v3.9.0 (2026-11-13)

A second major contributor (Szobonya Csaba / `csaba215`) drove a big 2026 schema-grammar overhaul. Downloads are about 130k–150k per month: modest but steady.

### Cited Findings
- **laravel-oci8 latest release**:
  - v13.14.1, released 2026-11-13.
  - composer requires `php ^8.3`, `ext-oci8 >=3.0.1`, `ext-pdo`, `illuminate/database ^13` (also pagination, support and validation ^13), and `yajra/laravel-pdo-via-oci8 ^3.7.1`.
  - Dev tooling: phpunit ^12, orchestra/testbench ^11, larastan ^3, rector ^2, pint.

  Sources: [Packagist p2 metadata](https://repo.packagist.org/p2/yajra/laravel-oci8.json); [LO composer.json](https://github.com/yajra/laravel-oci8/blob/master/composer.json)
- **Earlier v13 releases**: v13.14.0 (2026-09-15), v13.13.0 (2026-09-12), v13.12.0 (2026-09-09). — [Packagist](https://repo.packagist.org/p2/yajra/laravel-oci8.json)
- **Versioning**: the package version tracks the Laravel major (README matrix: Laravel 5.1 through 13.x map one-to-one to package 5.1 through 13.x). Maintenance branches exist for 10.x, 11.x, 12.x and older; master is 13.x-dev. — [LO README](https://github.com/yajra/laravel-oci8/blob/master/README.md); `git ls-remote`
- **pdo-via-oci8 latest release**: v3.9.0 (2026-11-13), v3.8.0 (2026-10-17); requires `php ^8.0`. — [Packagist p2](https://repo.packagist.org/p2/yajra/laravel-pdo-via-oci8.json)
- **Downloads** (Packagist, 2026-11-19):

  | Package | Total | Monthly | Daily |
  |---|---|---|---|
  | `yajra/laravel-oci8` | 3,675,134 | 130,014 | 5,879 |
  | `yajra/laravel-pdo-via-oci8` | 4,254,737 | 147,973 | 6,436 |
  | `doctrine/dbal` (for scale) | 663M | 17.4M | — |

  Source: [Packagist stats API](https://packagist.org/packages/yajra/laravel-oci8/stats.json)
- **GitHub stats** (2026-11-19):
  - laravel-oci8: 872 stars, 246 forks, 21 open issues/PRs, last push 2026-11-13.
  - pdo-via-oci8: 91 stars, 69 forks, 17 open.

  Source: GitHub API for [yajra/laravel-oci8](https://github.com/yajra/laravel-oci8) and [yajra/pdo-via-oci8](https://github.com/yajra/pdo-via-oci8)
- **Commit activity since 2025-11**: Szobonya Csaba 76 commits, Arjay Angeles (maintainer) 47 + 5 (as `yajra`), semantic-release-bot 19. — local `git log --since=2025-11-01` of [yajra/laravel-oci8](https://github.com/yajra/laravel-oci8)
- **2026 feature work**, mostly in July 2026:
  - Schema features: invisible columns, collation, `virtualAs`, timestamp precision, online unique indexes, spatial index drop, `compileSchemas`, multi-column distinct count.
  - Schema fixes: foreign-key introspection fixes, schema transactions disabled.
  - A "fresh Laravel install" integration test was mentioned in #980.

  Sources: local `git log` of [yajra/laravel-oci8](https://github.com/yajra/laravel-oci8); [LO #980](https://github.com/yajra/laravel-oci8/issues/980)
- **Tooling**: the repo ships a `context7.json` and PHPStan extensions (`extension.neon`). The composer `docker` script still references `deepdiver/docker-oracle-xe-11g`. — [LO composer.json](https://github.com/yajra/laravel-oci8/blob/master/composer.json)

### Inferences
- **Bus factor.** The project is effectively maintained by one maintainer plus one very active contributor. Its stated motivation is the maintainer's own employer usage ([LO #911](https://github.com/yajra/laravel-oci8/issues/911)).
- **Structure is unchanged.** Maintenance focuses on keeping pace with Laravel's grammar and schema API changes rather than revisiting the PDO-emulation architecture or the PL/SQL story.

### Gaps
- There is no official end-of-life or support policy per branch; I did not check whether 11.x and 12.x still receive fixes.

---

## 6. Comparable packages: Doctrine DBAL OCI8 driver, forks, alternatives

### Takeaway
There is no real alternative Laravel Oracle driver; the Laravel ecosystem converges on yajra. Packagist forks (naldi, anzims, etc.) are older or repinned copies. Doctrine DBAL ships first-party `OCI8` and `PDO\OCI` drivers. They are cleaner (no PDO subclassing, real auto-commit control), but they are deliberately minimal: no OUT/cursor API and no `lastInsertId`.

### Cited Findings
- **Doctrine DBAL** (latest 4.6.0, 2026-11-14) has a native `Driver/OCI8` namespace with `Connection`, `Statement`, `Result`, `ConvertPositionalToNamedPlaceholders`, `ExecutionMode`, `Middleware` and `Exception`. — [doctrine/dbal src/Driver/OCI8](https://github.com/doctrine/dbal/tree/4.6.x/src/Driver/OCI8) (listed via GitHub API); release tag via GitHub API
- **DBAL OCI8 Statement**:
  - It has only `bindValue(param, value, ParameterType)`, which maps `BINARY → OCI_B_BIN`, `LARGE_OBJECT → OCI_B_BLOB` (via `oci_new_descriptor`), and everything else to `SQLT_CHR`. There is no `bindParam` by reference, so no OUT or REF CURSOR support.
  - `Connection::lastInsertId()` throws `IdentityColumnsNotSupported`.
  - `beginTransaction()` calls `$this->executionMode->disableAutoCommit()`, the same OCI_DEFAULT approach but explicit.

  Source: [doctrine/dbal src/Driver/OCI8/Statement.php](https://github.com/doctrine/dbal/blob/4.6.x/src/Driver/OCI8/Statement.php), [Connection.php](https://github.com/doctrine/dbal/blob/4.6.x/src/Driver/OCI8/Connection.php)
- **Forks**: search results show naldi/laravel-oci8 (targets Laravel 4–7), anzims/laravel-oci8, etc-develop/laravel-oci8, jidago/pdo-via-oci8 and others. They are forks of the yajra code. nawie/eloquent wraps yajra rather than replacing it. — [Packagist naldi/laravel-oci8](https://packagist.org/packages/naldi/laravel-oci8), [Packagist anzims/laravel-oci8](https://packagist.org/packages/anzims/laravel-oci8), [GitHub etc-develop/laravel-oci8](https://github.com/etc-develop/laravel-oci8), [GitHub jidago/pdo-via-oci8](https://github.com/jidago/pdo-via-oci8), [GitHub nawie/eloquent](https://github.com/nawie/eloquent)

### Inferences
- **DBAL's design choices.** DBAL shows a cleaner layering: a driver interface that is not a PDO subclass, explicit execution mode, and a placeholder converter. It still offers nothing for PL/SQL. Combined with yajra's thin helpers, this confirms an open niche for a PHP Oracle layer with first-class PL/SQL, typed binding, streaming LOBs/cursors and implicit results.
- **Laravel adapter cost.** A Laravel adapter that is not a PDO subclass would need to override `Connection` methods that call `getPdo()` directly: `select`, `statement`, `affectingStatement`, `cursor`, transactions and `bindValues`. That is feasible; Laravel's `Connection` exposes these as overridable methods.

### Gaps
- I did not inspect DBAL's `PDO\OCI` driver or any Symfony/Doctrine ORM Oracle-specific platform quirks (`OraclePlatform`, sequences, identity) in detail.
- I did not search for non-Packagist or commercial alternatives, or for PHP FFI-based ODPI-C bindings.
