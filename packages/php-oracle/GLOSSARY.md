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
the database (for example the oci8 Backend or the Rust thin Backend).
_Avoid_: driver, engine, adapter, núcleo

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
Client), such as the oci8 Backend or an ODPI-C Backend.
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

### Results

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
