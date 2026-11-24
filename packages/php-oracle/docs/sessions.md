# Pool, Release and long-running workers

## The Pool

Each PHP process keeps one Pool per [Pool key](connecting.md#the-pool-key),
created on its first `connect()`. There is no Pool object to manage, and it
works the same under PHP-FPM, FrankenPHP worker mode and Octane (threads of a
ZTS build share it).

- **Every `connect()` gets its own Session.** Two Connections in one request
  never share a transaction.
- **The Pool grows on demand**, one Session at a time, up to `maxConnections`
  (16). At the ceiling, `connect()` waits up to `acquireTimeout` (5 s) for a
  Release, then throws `PoolExhaustedException`: a second Connection in the
  same request fails instead of deadlocking.
- **A Session idle for 60 s or more is pinged** when handed out, and replaced
  if the ping fails.

`maxConnections` is per process: 20 FPM workers with the default can open up
to 320 Sessions. Size it, and the database's `processes` / `sessions`, together.
It is per Pool too, and PHP's default time zone is part of the Pool key: a
process that switches zones per request has one Pool, and up to
`maxConnections` Sessions, per zone ([details](connecting.md#the-pool-key)).

## Release

A Connection's Session goes back to the Pool (its **Release**) on `close()`,
or when the Connection object and every Cursor it produced are gone. A
Release always rolls back an open transaction and never commits it. The
server reports at the end of every call whether a transaction is open, so a
Release with none open makes no Round trip: under Autocommit, the default,
`connect()` and `close()` through the Pool cost none.

```php
$connection->begin();
$connection->run("update orders set status = 'cancelled' where id = 1");
$connection->close(); // rolls back: the order is not cancelled
```

- `close()` is idempotent and never throws, even when the Session already
  died, so `finally` blocks stay simple.
- After the Release, every call on the Connection, its Cursors and its `Lob`s
  throws `UsageException`: nothing can read through a Session that another
  request may now hold.
- A Cursor keeps its Connection's Session, so dropping the `$connection`
  variable while a Cursor lives delays the Release until the Cursor goes too.
  A `Lob` does not keep the Session.

### What a Release does not reset

A pooled Session carries everything else to its next user:

- package state: package variables, and anything a package initialises once;
- `ALTER SESSION` settings, NLS ones included (values reaching PHP never
  depend on NLS, but your SQL's `TO_CHAR()` does);
- DBMS_OUTPUT enabled through raw SQL (`DBMS_OUTPUT.ENABLE`); output enabled
  with `enableOutput()` is turned off before the Release;
- rows in `ON COMMIT PRESERVE ROWS` temporary tables;
- the Session time zone, set from PHP's default zone when the Session was
  created.

Tracing is not cleared at Release either, but the next `connect()` overwrites
all of it.

**When that matters, use a Standalone Connection.** With `standalone: true`,
`connect()` logs on a new Session outside the Pool, and the Release logs it
off. Every Standalone Connection starts clean, at the cost of a logon each
time. Use it for CLI commands, migrations and any code that changes Session
state.

```php no-run
$connection = Oracle::connect(new ConnectionConfig(
    dsn: $dsn,
    user: 'shop',
    password: $password,
    standalone: true,
));
$connection->run("alter session set nls_sort = 'BINARY_CI'");
// ... the setting ends with the Session
$connection->close();
```

## Long-running workers

Under FrankenPHP worker mode and Octane, PHP objects outlive the request, and
Core hooks no request boundary: the Release is tied to the Connection
object, not to the request. A Connection kept in a container singleton keeps
its Session, and any transaction left open, into the next request.

**The rule: at the end of each unit of work (a request, a job), end the
transaction or close the Connection.**

- Prefer `transaction()`: it always commits or rolls back before it returns.
- With `begin()`, reach `commit()` or `rollback()` on every path, exceptions
  included.
- Or take a Connection per unit of work and `close()` it in a `finally`; with
  the Pool, `connect()` is cheap.

```php
$handleRequest = function (Connection $connection, int $orderId): void {
    $connection->transaction(function (Connection $connection) use ($orderId): void {
        $connection->run("update orders set status = 'paid' where id = :id", ['id' => $orderId]);
        $connection->run("insert into customers (name) values ('Paid customer')");
    });
    // Nothing is left open: the next request starts in Autocommit.
};

$handleRequest($connection, 1);
```

Under PHP-FPM, the objects of a request are gone when it ends, so the Release
happens by itself; the rule still holds within a request.

## Broken Connections

When the Session is lost (killed, network failure, server crash) or the
extension fails internally, the Connection becomes **Broken**:

- the call that sees it throws `ConnectionLostException` with the server's
  code and message, and every later call throws it again, as `Connection is
  broken: <message>`;
- it never reconnects by itself, so half a unit of work is never committed in
  a new Session;
- the broken Session never goes back to the Pool, and the next `connect()`
  works.

Rows a Cursor already received are still served after the Connection breaks;
the next fetch fails.

## Fibers

Fibers may read one Connection's Cursors alternately. The result is correct,
but the calls do not run concurrently: one Connection makes one Round trip at
a time.
