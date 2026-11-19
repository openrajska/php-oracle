# rust-oracledb: OUT binds, Implicit results and per-row batch errors

Research for [#3](https://github.com/openrajska/php-oracle/issues/3), part of
the wayfinder map [#1](https://github.com/openrajska/php-oracle/issues/1). It
feeds probes [#7](https://github.com/openrajska/php-oracle/issues/7),
[#8](https://github.com/openrajska/php-oracle/issues/8) and
[#10](https://github.com/openrajska/php-oracle/issues/10).

**Source:** crate `oracledb` 26.0.0-beta.6, read from
[oracle/rust-oracledb](https://github.com/oracle/rust-oracledb) at tag
`v26.0.0-beta.6` (commit
[`f1a0d18`](https://github.com/oracle/rust-oracledb/tree/f1a0d1890d13128c73ba26034d9da70677f3df6c)).
Every `file:line` link below is pinned to that commit. Upstream issues were
read on 2026-11-19. `main` was 6 commits ahead of the tag at that date (the
unreleased beta.7). Where those commits matter, this note says so.

Terms follow [`packages/php-oracle/GLOSSARY.md`](../../packages/php-oracle/GLOSSARY.md):
Call, Anonymous block, Implicit result, Cursor, REF CURSOR, Collection.

## Answer in one table

| Question | Answer |
|---|---|
| 1. OUT / IN OUT binds | **Implemented.** `ExecResult::out_bind_data() -> Row`, read with `Row::get` / `Row::take`. The client never declares the bind direction. The server reports it, and only OUT and IN OUT binds end up in the returned `Row`. |
| 2. Implicit results | **Not implemented**, and nothing upstream says it is planned. The driver also advertises the capability and asks for Implicit results on every execute, but has no handler for the reply. So an Anonymous block that calls `DBMS_SQL.RETURN_RESULT` should fail with `UnknownTtcMessageType(27)` *after* it has run (inferred from the code, to be confirmed by #8). |
| 3. Per-row batch errors | **Not implemented.** `execute_batch` stops at the first failing row and returns `Err(DbError)`. The error does not give the failing row's index, and the count of rows that succeeded is lost. A maintainer says batch errors and array DML row counts are "planned" ([rust-oracledb#41](https://github.com/oracle/rust-oracledb/issues/41#issuecomment-6069298784)). |

## 1. OUT and IN OUT binds

### API

| Step | API | Source |
|---|---|---|
| Execute once, positional | `Connection::execute(&self, sql: &str, params: &[&dyn ToDbValue]) -> Result<ExecResult, Error>` | [connection/mod.rs:148-156][c-conn-exec] |
| Execute once, named | `Connection::execute_named(&self, sql: &str, params: &[(&str, &dyn ToDbValue)]) -> Result<ExecResult, Error>` | [connection/mod.rs:167-174][c-conn-exec] |
| Same on a prepared statement | `Statement::execute(&mut self, …)` / `Statement::execute_named(&mut self, …)` | [statement/public.rs:132-172][c-stmt-exec] |
| Pure OUT bind | pass `&oracledb::DB_TYPE_*` (a `&'static DbType`, which implements `ToDbValue`) | [db_value.rs:800-808][c-dbtype-todb] |
| IN OUT bind | pass the initial Rust value (`&"text"`, `&42`, …) | [doc/batch_statement.md:212-216][c-doc-inout] |
| Read OUT values | `ExecResult::out_bind_data(&mut self) -> Row` (moves the data out; a second call returns an empty `Row`) | [exec_result.rs:79-88][c-execresult] |
| Read one value | `Row::get::<T>(impl ColumnIndex)` (copy or borrow), `Row::take::<T>(impl ColumnIndex)` (move; needed for `Cursor`) | [row.rs:208-252][c-row] |
| Index by position or name | `ColumnIndex` is implemented for `usize` and for `&str` (bind name, case-insensitive) | [column_index.rs:36-55][c-colidx] |
| OUT metadata | `Statement::out_metadata() -> &[Metadata]`: "includes OUT binds for PL/SQL statements" | [statement/public.rs:203-211][c-stmt-outmeta] |
| Batch | `ExecBatchResult::out_bind_data(&mut self) -> Vec<Row>`, one `Row` per execution | [exec_result.rs:144-155][c-execresult-batch] |

Call sequence for a Call with an IN, an OUT, an IN OUT and a REF CURSOR
parameter (built from
[examples/plsql_out_binds.rs:50-64][c-ex-out],
[examples/plsql_in_out_binds.rs:54-58][c-ex-inout],
[examples/ref_cursor.rs:52-62][c-ex-refcur] and
[examples/plsql_function.rs:49-54][c-ex-func]):

```rust
let mut result = connection.execute_named(
    "begin pkg.proc(p_in => :p_in, p_out => :p_out, p_io => :p_io, p_cur => :p_cur); end;",
    &[
        ("p_in", &100),                          // IN
        ("p_out", &oracledb::DB_TYPE_NUMBER),    // OUT: type only
        ("p_io", &"initial"),                    // IN OUT: initial value
        ("p_cur", &oracledb::DB_TYPE_CURSOR),    // REF CURSOR OUT
    ],
)?;
let mut out = result.out_bind_data();            // Row of OUT + IN OUT binds only
let n: Option<i64> = out.get("p_out")?;          // or out.get(0)
let s: String = out.get("p_io")?;                // or out.get(1)
let cur: oracledb::Cursor = out.take("p_cur")?;  // or out.take(2); Cursor: Iterator<Item = Result<Row, Error>>
for row in cur { let row = row?; /* … */ }
```

A function's return value is an OUT bind on the left of `:=`:
`"begin :1 := f(:2); end;"` with `&[&oracledb::DB_TYPE_NUMBER, &19]`, then
`out_bind_data().get(0)` ([examples/plsql_function.rs:49-54][c-ex-func]).

### How it works, and what follows from it

- **The server decides the bind direction.** The client sends every bind as
  data. The database answers with a TTC I/O vector message that carries one
  direction byte per bind ([messages/execute.rs:324-341][c-iovec]).
  `CachedStatement::set_bind_directions` then rebuilds `out_metadata` from the
  binds whose direction includes OUTPUT
  ([statement/mod.rs:419-432][c-setdir],
  [statement/bind_info.rs:85-95][c-bindinfo-dir]). The docs say so:
  "Applications do not set the bind direction explicitly"
  ([doc/batch_statement.md:106-111][c-doc-out]). A test even binds a pure OUT
  parameter with an ordinary value (`&0`) instead of a `DbType`
  ([tests/test_2700_execution.rs:79-93][c-t2701]).
- **Positional indexes in the OUT `Row` count only OUT and IN OUT binds.** They
  do not count all binds. In the example above, `p_out` is index 0 although it
  is the second placeholder. See also
  [examples/plsql_out_binds.rs:50-56][c-ex-out], where `:2` is read with
  `.get(0)`. Lookup by name is the safer choice for Core's generated Calls.
- **The bind name is the placeholder text.** `ref_cursor.rs` binds
  positionally but reads `take("ref_cursor")`, the placeholder's name
  ([examples/ref_cursor.rs:52-58][c-ex-refcur]).
- **NULL OUT values** come back as `None` when read as `Option<T>`
  ([tests/test_2700_execution.rs:176-184][c-t2705]).
- **REF CURSOR** comes back as `oracledb::Cursor`, which implements `Iterator`
  ([cursor.rs:109][c-cursor-iter]) and `FromDbValue`
  ([db_value.rs:481][c-cursor-fromdb]). It works from an Anonymous block
  ([tests/test_2700_execution.rs:713-732][c-t2726]) and with object and
  Collection columns inside the REF CURSOR
  ([tests/test_3700_dbobject.rs:388-401][c-t3700a],
  [tests/test_3700_dbobject.rs:680-700][c-t3700b]).
- **Batch execution of PL/SQL returns one OUT `Row` per execution**
  ([tests/test_2700_execution.rs:611-624][c-t2722],
  [examples/plsql_batch_out_binds.rs:50-64][c-ex-batch]).

### Buffer sizes and bind types (matters for #7)

- The OUT buffer is `max_size × buffer_size_factor` for sized types
  ([metadata.rs:403-411][c-bufsize]). For VARCHAR the factor is 4, and the
  default size of a bare `&DB_TYPE_VARCHAR` is 4000
  ([db_type.rs:484-488][c-varchar]).
- For an IN OUT string, `max_size` is the byte length of the *input* value
  (`String`/`&str`: `self.len()`,
  [db_value.rs:627-645][c-str-maxsize]). The buffer is therefore 4 × the input
  length. A test grows `"value"` (5 bytes) to `"value-updated"` (13 bytes),
  which fits in 20 ([tests/test_2700_execution.rs:88-91][c-t2701]). Output
  longer than the buffer is untested. An ORA-06502 is likely.
- `None::<String>` has `max_size` 0 ([db_value.rs:783-798][c-option]). An IN
  OUT VARCHAR2 bound as NULL could therefore get a 0-byte buffer. **#7 should
  probe this.**
- To get room for larger strings, the docs pass a padding string such as
  `" ".repeat(32767)` as the bind value
  ([doc/plsql_execution.md:297-305][c-doc-dbmsout]).
- Rust integers bind as NUMBER, but `f64` binds as BINARY_DOUBLE
  ([db_value.rs:673-683][c-f64]). No API sets a bind's type or size apart from
  its value (there is no `setinputsizes()`). Upstream issue
  [rust-oracledb#65](https://github.com/oracle/rust-oracledb/issues/65) asks
  for one, and the maintainer answered "I have another idea which may work
  better … Stay tuned!"
  ([comment](https://github.com/oracle/rust-oracledb/issues/65#issuecomment-6648114754)).
- In a batch, every row must give the same `DbType` for a bind column, or the
  call fails with `ErrorKind::DifferentTypes`
  ([bind_params.rs:97-117][c-validate],
  [tests/test_2700_execution.rs:303-316][c-t2711]).
- Collections cannot be bound. `DbObject` and `DbCollection` have no
  `ToDbValue` impl (see the `impl ToDbValue for` list in
  [db_value.rs:627-808][c-str-maxsize]), so they can be *fetched* but not
  passed IN or IN OUT. Whether `&DB_TYPE_OBJECT` works as a pure OUT bind is
  unknown. The bind metadata needs a resolved object type
  ([metadata.rs:375-381][c-meta-oid]), and a bare `DbType` does not carry one.

## 2. Implicit results

**Not implemented.**

Evidence:

1. The feature table says so: "Implicit Result Sets | No"
   ([doc/appendix_a.md:79][c-appendix]).
2. There is no public API. `ExecResult` holds only `column_info`,
   `out_bind_data`, `returned_data` and `rows_affected`
   ([exec_result.rs:42-47][c-execresult-struct]). Neither `Statement` nor
   `Cursor` exposes anything like `implicit_results()` or a next-result call.
   Grepping the crate for `implicit` finds only protocol constants.
3. The message constant exists but is unused, as its leading underscore
   shows: `_TTC_MSG_TYPE_IMPLICIT_RESULTSET: u8 = 27`
   ([constants.rs:206][c-const-ir]).
4. The TTC message dispatcher has no arm for type 27. Unknown types fall
   through to `_ => Err(resp.unknown_ttc_message_type(message_type))`
   ([messages/mod.rs:152-198][c-dispatch]). The result is
   `ErrorKind::UnknownTtcMessageType(u8, ResponseLocation)`, displayed as
   "internal error: unknown TTC message type 27 at packet …"
   ([error.rs:101][c-err-kind], [error.rs:412-417][c-err-display]).
5. **Yet the driver asks for Implicit results.** It advertises
   `CCAP_VAL_IMPLICIT_RESULTS` in its compile-time capabilities
   ([client/capabilities.rs:82][c-cap-const],
   [client/capabilities.rs:224-227][c-cap-set]). It also sets
   `TTC_EXEC_FLAGS_IMPLICIT_RESULTSET` on every execute that is not a define
   round trip ([messages/execute.rs:101-108][c-exec-flag],
   [constants.rs:279][c-const-flag]).

**Expected behaviour, inferred from points 4 and 5 and to be confirmed by
#8.** Take an Anonymous block that calls `DBMS_SQL.RETURN_RESULT`. The server
runs it to completion, since its side effects and DML stay in the open
transaction. The server then replies with message type 27, and parsing aborts
with `UnknownTtcMessageType(27, …)`. Because parsing stops, the following also
happens:

- OUT binds in the same block and the trailing error and row-count info are
  never read.
- `post_deserialize` never runs, so the statement's cursor id is not stored
  ([messages/execute.rs:392-400][c-postdeser]).
- The server-side cursors opened for the results are never closed by the
  client. They stay open until the Session ends.

The stream should stay in sync on 23ai/26ai. When the server supports
end-of-response, the client reads every packet up to the end marker *before*
it parses ([client/mod.rs:196-227][c-recv]). On a server without
end-of-response (19c), the unread packets may desynchronise the Connection.

**Planned?** Nothing found. No upstream issue mentions Implicit results or
`RETURN_RESULT`. GitHub issue search for "implicit", "RETURN_RESULT" and
"result set" on 2026-11-19 found nothing relevant. No branch targets it either:
the only branches are `main`, `gh-pages` and `enh-run-tests-action`. No `TODO`
or `todo!()` refers to it. Batch errors at least have a maintainer saying they
are planned (section 3). Implicit results have no such statement.

Options for the Rust Backend (to decide after #8, listed here only as leads):

- Map Implicit results to REF CURSOR OUT binds at the Core level, which does
  not help user-written Anonymous blocks.
- Patch or contribute the type-27 handler upstream. python-oracledb thin,
  which has the same lead maintainer, is the reference implementation of this
  message.
- Declare Implicit results unsupported on the thin Backend.

## 3. Per-row batch errors

**Not implemented. `execute_batch` aborts on the first failing row.**

API:

- `Connection::execute_batch<'a>(&self, sql: &str, params: impl Into<BindParameters<'a>>) -> Result<ExecBatchResult, Error>`
  ([connection/mod.rs:157-166][c-conn-batch]).
- `Statement::execute_batch(&mut self, params: BindParameters) -> Result<ExecBatchResult, Error>`
  ([statement/public.rs:146-156][c-stmt-batch]). Beta.7 widens this to
  `impl Into<BindParameters>`
  ([rust-oracledb#94](https://github.com/oracle/rust-oracledb/issues/94)).
- `BindParameters::Slice(&[&[&dyn ToDbValue]])`, one inner slice per row, or
  `BindParameters::Arrow(RecordBatch)` with the `arrow` feature
  ([bind_params.rs:39-46][c-bindparams]). There is no named-bind variant of
  `execute_batch`.
- `ExecBatchResult` exposes `rows_affected()` (the total),
  `out_bind_data() -> Vec<Row>` and `returned_data() -> Vec<Vec<Row>>`
  ([exec_result.rs:101-176][c-execresult-batch]). It has no per-row errors and
  no per-row counts.

Evidence that per-row errors are absent:

1. `ExecuteMessage` has `batch_errors` and `array_dml_row_counts` fields
   ([messages/execute.rs:45-46][c-exec-fields]). They are hard-coded to
   `false` in its only constructor
   ([messages/execute.rs:289-308][c-exec-new]), so the
   `TTC_EXEC_OPTION_BATCH_ERRORS` and DML-row-count bits are never sent
   ([messages/execute.rs:134-139][c-exec-opts]). `StatementBuilder` has no
   option to change them
   ([statement/builder.rs](https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/builder.rs)).
2. The response parser cannot read batch errors. When the counts of batch
   errors, offsets or messages are non-zero, it hits `todo!()`
   ([response/error_info.rs:72-83][c-errinfo-todo]). The same holds for array
   DML row counts ([messages/execute.rs:373-375][c-exec-todo]). The current row
   number and the successful-iteration count are read and thrown away
   ([response/error_info.rs:51][c-errinfo], [:68][c-errinfo-68]).
3. On the first error, `check_for_statement_error` turns the `DbError` into
   `Err(Error::db_error(..))`
   ([response/mod.rs:150-170][c-stmterr]), so no `ExecBatchResult` is built.
   The row count of the rows that succeeded is lost with it. `DbError` exposes
   only `code()`, `message()` and `offset()`
   ([response/error_info.rs:133-180][c-dberror]). `offset` is the server's
   *error position* (`error_pos`,
   [response/error_info.rs:56][c-errinfo-56]), not a documented row index.
   Whether Oracle fills it with anything useful for array DML is a question
   for #10.
4. The only test of a failing batch asserts the error code and then rolls
   back. It does not check whether the rows before the bad one were applied,
   or which row failed
   ([tests/test_2700_execution.rs:200-223][c-t2707]).
5. For PL/SQL, the first row runs alone and the rest run in a second round
   trip, whenever the statement is not yet parsed or its binds changed
   ([client/mod.rs:785-817][c-client-exec],
   [statement/mod.rs:414-416][c-single]). A PL/SQL batch can therefore fail
   in either of two round trips.
6. The batch chapter opens by promising that rust-oracledb "allows 'noisy'
   data … to be filtered for review while other, correct, values are
   inserted" ([doc/batch_statement.md:7-9][c-doc-noisy]), but no section
   implements it. The sentence appears to be inherited from python-oracledb,
   whose `batcherrors=True` does exist.

**Planned: yes, without a date or a design.** Issue
[rust-oracledb#41](https://github.com/oracle/rust-oracledb/issues/41) asks for
the `todo!()`s to return errors instead. The maintainer (Anthony Tuininga)
answered on 2026-10-08: "These aren't a concern until such time as the driver
implements batch errors and DML row counts -- both of which are planned"
([comment](https://github.com/oracle/rust-oracledb/issues/41#issuecomment-6069298784)).
A user then proposed an API, which has not been accepted
([comment](https://github.com/oracle/rust-oracledb/issues/41#issuecomment-6808928207)):

```rust
conn.statement(sql)?.batch_errors(true).array_dml_row_counts(true).build()?
    .execute_batch(&rows)?;
for e in result.batch_errors() { e.offset(); e.error(); }
let counts: &[u64] = result.row_counts();
```

Workarounds to try in #10, none verified:

- Without batch errors, Oracle stops array DML at the failing iteration and
  keeps earlier iterations in the transaction. This is server behaviour, not
  something the crate documents. If #10 confirms it, the Backend can roll back
  to a savepoint and re-run row by row to find the bad rows.
- DML error logging (`INSERT … LOG ERRORS INTO err$_t REJECT LIMIT
  UNLIMITED`) is handled entirely on the server. It needs nothing from the
  driver.
- `FORALL … SAVE EXCEPTIONS` in an Anonymous block would need array or
  Collection binds. The crate does not support those (section 1).

## Notes for the probe tickets

- **#7 (scalar OUT / IN OUT):**
  - Read OUT values by bind name. Positional indexes count only OUT binds.
  - Probe an IN OUT VARCHAR2 whose output is longer than 4 × its input, and
    one bound as `None::<String>` (0-byte buffer).
  - Bind numbers that need exact precision as integers or `OracleNumber`,
    not `f64`, which binds as BINARY_DOUBLE.
  - `rows_affected()` on PL/SQL is non-zero in beta.6 but always 0 from
    beta.7 ([rust-oracledb#93](https://github.com/oracle/rust-oracledb/issues/93)).
- **#8 (REF CURSOR / Implicit results):**
  - Several REF CURSOR OUT parameters each become one column of the OUT
    `Row`. Take each with `take::<Cursor>`.
  - A function returning a REF CURSOR is `begin :c := f; end;` bound with
    `&DB_TYPE_CURSOR`.
  - Nested `CURSOR()` columns are supported and tested
    ([tests/test_2700_execution.rs:494-507][c-nested],
    [tests/test_2600_metadata.rs:384-387][c-nested2]).
  - For Implicit results, expect `UnknownTtcMessageType(27)`, not silence.
    Also check that the block's side effects happened, that the Connection is
    still usable afterwards, and whether `open_cursors` grows.
- **#10 (batch errors):** expect fail-fast with no row index. Record the
  following:
  - which rows stayed applied;
  - what `DbError::offset()` holds;
  - whether `LOG ERRORS` works through `execute_batch`;
  - that all rows must share one `DbType` per bind column (see also
    [rust-oracledb#96](https://github.com/oracle/rust-oracledb/issues/96)
    about `None::<OracleTimestamp>`).
- **Collections:** `doc/appendix_a.md:52` still says "SQL and PL/SQL types and
  collections: No". Since beta.5 the crate *fetches* objects and Collections
  ([doc/release_notes.md:83][c-relnotes]), but it still cannot bind them.

[c-conn-exec]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/connection/mod.rs#L148-L174
[c-conn-batch]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/connection/mod.rs#L157-L166
[c-stmt-exec]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/public.rs#L132-L172
[c-stmt-batch]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/public.rs#L146-L156
[c-stmt-outmeta]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/public.rs#L203-L211
[c-dbtype-todb]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/db_value.rs#L800-L808
[c-str-maxsize]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/db_value.rs#L627-L645
[c-f64]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/db_value.rs#L673-L683
[c-option]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/db_value.rs#L783-L798
[c-cursor-fromdb]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/db_value.rs#L481
[c-cursor-iter]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/cursor.rs#L109
[c-varchar]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/db_type.rs#L484-L488
[c-bufsize]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/metadata.rs#L403-L411
[c-meta-oid]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/metadata.rs#L375-L381
[c-execresult-struct]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/exec_result.rs#L42-L47
[c-execresult]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/exec_result.rs#L50-L88
[c-execresult-batch]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/exec_result.rs#L101-L176
[c-row]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/row.rs#L208-L252
[c-colidx]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/column_index.rs#L36-L55
[c-iovec]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L324-L341
[c-setdir]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/mod.rs#L419-L432
[c-single]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/mod.rs#L414-L416
[c-bindinfo-dir]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/statement/bind_info.rs#L85-L95
[c-bindparams]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/bind_params.rs#L39-L46
[c-validate]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/bind_params.rs#L97-L117
[c-const-ir]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/constants.rs#L206
[c-const-flag]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/constants.rs#L279
[c-dispatch]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/mod.rs#L152-L198
[c-err-kind]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/error.rs#L101
[c-err-display]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/error.rs#L412-L417
[c-cap-const]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/client/capabilities.rs#L82
[c-cap-set]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/client/capabilities.rs#L224-L227
[c-exec-flag]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L101-L108
[c-exec-fields]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L45-L46
[c-exec-opts]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L134-L139
[c-exec-new]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L289-L308
[c-exec-todo]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L373-L375
[c-postdeser]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/messages/execute.rs#L392-L400
[c-recv]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/client/mod.rs#L196-L227
[c-client-exec]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/client/mod.rs#L785-L817
[c-errinfo]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/response/error_info.rs#L51
[c-errinfo-56]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/response/error_info.rs#L56
[c-errinfo-68]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/response/error_info.rs#L68
[c-errinfo-todo]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/response/error_info.rs#L72-L83
[c-dberror]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/response/error_info.rs#L133-L180
[c-stmterr]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/src/response/mod.rs#L150-L170
[c-appendix]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/doc/appendix_a.md?plain=1#L79
[c-relnotes]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/doc/release_notes.md?plain=1#L83
[c-doc-noisy]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/doc/batch_statement.md?plain=1#L7-L9
[c-doc-out]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/doc/batch_statement.md?plain=1#L106-L111
[c-doc-inout]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/doc/batch_statement.md?plain=1#L212-L216
[c-doc-dbmsout]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/doc/plsql_execution.md?plain=1#L297-L305
[c-ex-out]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/examples/plsql_out_binds.rs#L50-L64
[c-ex-inout]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/examples/plsql_in_out_binds.rs#L54-L58
[c-ex-batch]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/examples/plsql_batch_out_binds.rs#L50-L64
[c-ex-refcur]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/examples/ref_cursor.rs#L52-L62
[c-ex-func]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/examples/plsql_function.rs#L49-L54
[c-t2701]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L79-L93
[c-t2705]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L176-L184
[c-t2707]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L200-L223
[c-t2711]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L303-L316
[c-t2722]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L611-L624
[c-t2726]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L713-L732
[c-nested]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2700_execution.rs#L494-L507
[c-nested2]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_2600_metadata.rs#L384-L387
[c-t3700a]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_3700_dbobject.rs#L388-L401
[c-t3700b]: https://github.com/oracle/rust-oracledb/blob/f1a0d1890d13128c73ba26034d9da70677f3df6c/tests/test_3700_dbobject.rs#L680-L700
