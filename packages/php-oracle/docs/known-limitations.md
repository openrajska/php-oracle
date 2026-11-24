# Known limitations

What v1 does differently from what you might expect, and what it does not do
yet. Anything not supported fails loudly, with `UnsupportedException` or a
server error, unless this page says otherwise.

## Wrong data to watch for

- **BOOLEAN attributes of Objects built in SQL read as `false`.** A TRUE
  BOOLEAN attribute (or Collection element) of an Object built by SQL, or
  stored in a table, comes back `false`. Objects built in PL/SQL read
  correctly, and writing is correct.

## Sessions and the Pool

- A pooled Session keeps its package state, `ALTER SESSION` settings (NLS
  included) and DBMS_OUTPUT enabled through raw SQL for its next user. Use
  `standalone: true` when that matters ([details](sessions.md#what-a-release-does-not-reset)).
- After a PHP fatal error (memory or time limit), no destructor runs, so
  unread output enabled with `enableOutput()` stays with the pooled Session.
  The next user's `enableOutput()` still starts empty.
- The Session time zone is set when the Session is created. After
  `date_default_timezone_set()`, the next `connect()` uses a separate Pool
  for the new zone; Connections already open keep theirs. TIMESTAMP WITH
  LOCAL TIME ZONE values are converted with PHP's default zone at fetch time.
- DRCP gives no gain under PHP-FPM yet: the client does not release the DRCP
  server between uses, so each worker's idle Sessions keep theirs.
- An unreachable host fails after `connectTimeout` plus about one second.
- Rows a Cursor already received are still served after its Connection
  breaks; the next fetch fails.

## Timeouts

- The Call timeout bounds each wait for the server on the network (a socket
  read or write), not a whole Round trip: a response that arrives in many
  packets, each within the limit, can take longer in total.
- `ExecuteOptions`' `timeout` covers the statement and its fetches. Describing
  an Object type on its first use in a Session and the `commit()` /
  `rollback()` Round trips run under the Connection's `callTimeout` instead.

## Connecting

- TLS checks the server certificate against the host name, never its DN.
  Trust for public CAs comes from CA roots bundled in the extension, not from
  the operating system's store.
- Autonomous Database `tnsnames.ora` entries carry
  `(security=(ssl_server_dn_match=yes))`, which is rejected: remove it. One-way
  TLS to Autonomous Database is untested against a real instance.
- The database's national character set must be AL16UTF16 (the default).

## Running SQL

- `?` placeholders are not supported; a `?` reaches the server as a syntax
  error.
- The Fetch size of a REF CURSOR, a nested Cursor or an Implicit result is
  its Execution's; it cannot change afterwards. `first()` and `value()` still
  bring a whole `fetchSize` batch.
- Two columns with the same name in an Implicit result, under
  `RowShape::Assoc`, make the whole Execution throw.
- `warning()`: the same warning twice in a row on one Session (two
  `create or replace` with the same compilation error) is reported only the
  first time.
- A cursor cannot be passed back as IN.
- Under Autocommit, `SELECT … FOR UPDATE` commits as it runs and loses its
  locks at once; a later fetch from it fails with ORA-01002. Lock rows inside
  a transaction.

## Binds

- `Char::out()` and `NChar::out()` behave like `Varchar::out()`. A PL/SQL
  `CHAR` or `NCHAR` parameter declared without a length takes its length from
  the bind, so its OUT value comes back blank-padded to 32767 bytes (16383
  characters for NCHAR).
- `Date::in()` travels as a TIMESTAMP: compared with an indexed DATE column,
  it can keep the index from being used. Write `cast(:value as date)`.
- `TimestampTz::in()` refuses a `DateTimeInterface` whose offset has seconds
  (a historical LMT zone) or exceeds ±15:59.
- A stream bound with `Clob::in()`, `NClob::in()` or `Blob::in()` is read to
  its end, never rewound or closed. `Clob::in('')` is an empty LOB, not NULL.
- A LOB bind, or a string over 32767 bytes, costs one Round trip plus one per
  MiB before the statement's own. Its temporary LOB lives while any Cursor or
  `Lob` born of that Execution does.
- `RETURNING … INTO` a LOB, an Object or a Collection is refused in SQL with
  `UnsupportedException`; write the statement inside a PL/SQL block instead.

## Array DML

- A PL/SQL block in `runMany()` gets no per-row errors: it stops at its first
  failing row with that row's server error, and nothing is applied.
- A placeholder written twice in the statement is not supported.
- `''` in a column that became CLOB binds as NULL. Each CLOB cell costs one
  Round trip plus one per MiB.
- A block returning Implicit results is refused only after it ran; under
  Autocommit its work is already committed.
- Over 65535 failing rows, the exception carries no row list.
- A failed call costs one more Round trip under Autocommit (the rollback);
  inside a transaction every call costs two (the savepoint and the execute).

## Values

- NUMBER strings differ from oci8's: `'0.5'`, not `'.5'`.
- A DATE or TIMESTAMP Wall clock that falls in a daylight-saving gap of PHP's
  default zone is shifted by PHP. `TemporalMode::String` gives the stored text.
- A TIMESTAMP WITH TIME ZONE stored with a region name, `CURRENT_TIMESTAMP`
  included, comes back as the right Instant in UTC, not in its region.
- A database whose `DBTIMEZONE` is a region name cannot fetch TIMESTAMP WITH
  LOCAL TIME ZONE values.
- `LobMode::Inline` costs one extra Round trip per non-empty LOB, and a LOB
  too large for `memory_limit` throws `UnsupportedException`.
- A `Lob` reads in one pass: once read, `contents()` gives `''`.

## JSON and VECTOR

- JSON key order is not kept, and `{}` and `[]` both decode to `[]`.
- A DATE inside JSON comes back as a TIMESTAMP.
- `Json::in()` cannot produce JSON's extended RAW, INTERVAL, BINARY_DOUBLE or
  VECTOR scalars, so a fetched JSON value holding them may not bind back
  unchanged. Text must be valid UTF-8.
- A JSON column holding an OSON id fails with `UnsupportedException`.
- A sparse VECTOR has at most 65535 non-zero values and is never BINARY.

## Objects, Collections and Associative arrays

- An Object type's description is cached per Session and never refreshed.
  After `ALTER TYPE … ADD ATTRIBUTE`, values of the evolved type cannot be
  read in any Session (`UnsupportedException`).
- Nested tables and VARRAYs are both `Collection`, and the client never
  checks a VARRAY's limit.
- Types reached through a public synonym do not resolve: write the owner,
  `SYS.ODCINUMBERLIST`.
- An Associative array is never NULL in PL/SQL: `Indexed::in($type, null)`
  arrives as an empty one.
- CHAR and NCHAR attributes bound in an Object are stored unpadded.
- Attribute lengths are checked in bytes from the type's description: a
  `VARCHAR2(10 CHAR)` attribute accepts up to 40 bytes before the server sees
  it.
- Attributes of type LOB, BFILE, REF, JSON, VECTOR, LONG or XMLTYPE, a
  subtype instance and a TIMESTAMP WITH TIME ZONE attribute holding a region
  name fail loudly. TIMESTAMP WITH LOCAL TIME ZONE attributes were only
  checked on a database whose `DBTIMEZONE` is `+00:00`.

## Not in v1

**Later:** a cursor passed as IN, per-row affected counts in Array DML, a
"keep the valid rows" Array DML mode, BFILE and XMLTYPE values, LONG binds
over 32767 bytes, cancelling a Call, privileged connects (SYSDBA…),
changing a password, LOB writes through a Locator and random-access LOB
reads, savepoint nesting, `inTransaction()`, deadlock retry, a `size:` on
binds, `@dblink` in Call names, Easy Connect Plus parameters, Column `domain`
and `annotations`, DB operation tracing (`dbOp`), a per-Execution Statement
cache opt-out, AQ, CQN, SODA, two-phase commit, sessionless transactions,
scrollable cursors, sharding, Application Continuity, async, and Oracle
Database 19c / 21c.

**Never on this Backend:** `INDEX BY VARCHAR2` Associative arrays,
`SYS.ANYDATA`, and Native Network Encryption, Kerberos, RADIUS, LDAP,
external authentication, SEPS, TAF and FAN.
