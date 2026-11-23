# Core

The framework-agnostic language of the ecosystem: how PHP code talks to Oracle
Database through one typed API that runs unchanged over interchangeable
Backends. The other contexts reuse these terms.

## Language

### Packages and layers

**Core**:
The `openrajska/php-oracle` package: the public API that users call, plus the
Driver contract that every Backend implements.
_Avoid_: native core, engine, núcleo (for anything below the Driver contract)

**Backend**:
An interchangeable implementation of the Driver contract that actually talks to
the database (for example the Rust thin Backend or a pure-PHP thin Backend).
_Avoid_: driver, engine, adapter, núcleo

**Primary Backend**:
The Backend the project is built around: the Driver contract is designed
against it and the conformance suite runs on it first.
_Avoid_: default Backend, main driver

**Driver contract**:
The set of internal interfaces between Core and a Backend. Users never call it
directly.
_Avoid_: driver API, SPI

**Thin**:
Describes a Backend mode that speaks Oracle Net directly, without Oracle Client
libraries. It is a property of the mode, not of the language (the Rust Backend
and a pure-PHP Backend are both thin).
_Avoid_: pure, native (as synonyms for thin)

**Thick**:
Describes a Backend mode that depends on Oracle Client libraries (Instant
Client), such as an ODPI-C Backend.
_Avoid_: OCI mode, full client

### Connections

**Session**:
The server-side Oracle session that holds transaction state, package state,
NLS settings and DBMS_OUTPUT buffers.
_Avoid_: connection (when the server-side state is meant)

**Connection**:
The user's handle to the database. A Connection is bound to exactly one Session
for its entire life.
_Avoid_: link, handle, session

**Pool**:
A set of reusable Sessions that hands out Connections. It never swaps the
Session underneath a Connection in use.
_Avoid_: connection pool (when DRCP's server-side pool is meant, say DRCP)

**Standalone Connection**:
A Connection whose Session is opened for it alone, outside the Pool, and ends
when the Connection ends.
_Avoid_: dedicated connection, non-pooled connection

**Release**:
The end of a Connection's use of its Session: the Session goes back to the Pool,
or ends for a Standalone Connection. A Release always rolls back an open
transaction and never commits it.
_Avoid_: close (that is the user's call that triggers a Release), disconnect

**Broken Connection**:
A Connection whose Session was lost to a network or server failure. Every later
operation on it fails; it never reconnects by itself.
_Avoid_: dead connection, stale connection

**Round trip**:
One exchange of messages between the client and the Oracle server over the
network: a request and its response. The unit of latency.
_Avoid_: call, request (for the network exchange)

### Executing PL/SQL

**Call**:
An invocation of a stored procedure or function by name, for which Core
generates the PL/SQL using named notation.
_Avoid_: call (for an Anonymous block), RPC, exec

**Anonymous block**:
A `BEGIN … END;` PL/SQL text written by the user and executed as is. It accepts
the same typed binds as a Call and can produce Implicit results, but it is not a
Call.
_Avoid_: script, PL/SQL call

**Bind**:
A value sent to the database separately from the SQL text, matched to a named
placeholder (`:name`). Its direction is IN (sent), OUT (received) or IN OUT
(both). A bind's type is either inferred from the PHP value or stated
explicitly.
_Avoid_: parameter (that is a procedure's or function's formal parameter),
placeholder (that is the `:name` in the SQL text), bind variable

### Results

**Execution**:
One run of a SQL statement or a PL/SQL block (a Call included) and everything
it produced: the number of affected rows, the OUT values, the Implicit results
and, for a query, its rows.
_Avoid_: result (that word belongs to Implicit result), statement (that is the
SQL text, not one run of it)

**Implicit result**:
A result returned by PL/SQL through `DBMS_SQL.RETURN_RESULT` without being
declared as a parameter. A single execution can produce several, each with its
own columns.
_Avoid_: implicit cursor (that is a different Oracle concept)

**Cursor**:
A client-side, lazily consumed sequence of rows. A Cursor can come from a query,
a REF CURSOR or an Implicit result.
_Avoid_: result set, ResultSet, recordset

**REF CURSOR**:
A server-side pointer to an open query, handed to the client by PL/SQL as an OUT
parameter, a function return value or a nested `CURSOR()` column. The client
reads it as a Cursor.
_Avoid_: cursor (when the server-side object is meant), SYS_REFCURSOR (that is
one REF CURSOR type, not the concept)

### PL/SQL types

**Collection**:
A value of an Oracle SQL collection type: a nested table or a VARRAY.
_Avoid_: array, list, Collection (for `Illuminate\Support\Collection`, always
write "Laravel Collection")

**Associative array**:
A PL/SQL-only `INDEX BY` table, which may be sparse. It is not a Collection.
_Avoid_: index-by table, PL/SQL table, hash

### Values

**Wall clock**:
A date and time with no time zone, as DATE and TIMESTAMP hold it: what a clock
on the wall shows, not a point on the global timeline.
_Avoid_: local time, naive datetime

**Instant**:
A point on the global timeline, independent of any time zone, as TIMESTAMP WITH
TIME ZONE and WITH LOCAL TIME ZONE identify it.
_Avoid_: absolute time, UTC time

**LOB**:
A CLOB, NCLOB or BLOB value: character or binary data large enough that it is
read through a Locator rather than carried in the row.
_Avoid_: blob (for any LOB), large field

**Locator**:
A server-side reference to a LOB's data. It is valid only while the Connection
that received it keeps its Session.
_Avoid_: LOB handle, pointer
