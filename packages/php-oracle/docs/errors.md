# Errors

Everything the library throws implements `Rajska\Oracle\Exceptions\OracleException`.
It never emits PHP warnings or notices, so your error handler only sees
exceptions.

```
OracleException (interface)                     sql(), call()
├─ QueryException          RuntimeException     a server error; offset()
│  ├─ UniqueViolationException                  ORA-00001
│  └─ BatchException                            rows(): the failing rows of runMany()
├─ ConnectionException     RuntimeException     no Session obtained (wrong password, unreachable host)
│  ├─ PoolExhaustedException                    acquireTimeout expired
│  └─ ConnectionLostException                   a Broken Connection
├─ TimeoutException        RuntimeException     the Call timeout
├─ UnsupportedException    RuntimeException     a feature not supported yet
├─ ConfigurationException  LogicException       a rejected dsn or wallet, an invalid config, the extension missing or incompatible
└─ UsageException          LogicException       a programming mistake, caught before any Round trip
```

```php
try {
    $connection->run(
        'insert into customers (name, email) values (:name, :email)',
        ['name' => 'Ada again', 'email' => 'ada@example.com'],
    );
} catch (UniqueViolationException $exception) {
    $exception->getCode();    // 1
    $exception->getMessage(); // 'ORA-00001: unique constraint (SHOP.SYS_C0012254) violated on table SHOP.CUSTOMERS columns (EMAIL)'
    $exception->sql();        // the SQL that ran
}
```

- `getCode()` is the ORA number, or 0 for errors the library raises itself.
- `getMessage()` is the server's text verbatim. Messages the library writes
  are in English and name the bind, row or identifier at fault.
- `sql()` is the SQL that ran; for a Call, the block Core generated
  (`BEGIN billing.close_month(…); END;`). `call()` is the Call's name.
- `QueryException::offset()` is where the server located a syntax error.

**Bound values never appear** in messages, `sql()` or stack traces: every
`$binds` and `$rows` parameter is `#[\SensitiveParameter]`. On 26ai the server
itself can add the offending row's values to an error (`ORA-03301: (ORA-00001
details) row with column values …`); the extension turns that off
(`error_message_details`) when it creates a Session. An `ALTER SESSION` of
yours can turn it back on, for that pooled Session's later users too.

## `UsageException`: fix the code

Mistakes in the calling code are thrown before anything reaches the server:
bind keys, a value that needs a typed bind, a narrowing getter on the wrong
type, transaction state, use after `close()`, `runMany()` rows that disagree.
The message names the fix:

```php
try {
    $connection->select('select * from orders where placed_at > :since', ['since' => new DateTimeImmutable()]);
} catch (UsageException $exception) {
    // "Bind 'since': a DateTimeInterface does not bind by itself; use Date::in() or Timestamp::in()."
}
```

## `UnsupportedException`: not yet

A type or feature outside v1 that your code reaches (an XMLTYPE or BFILE
value, a cursor passed as IN, an `INDEX BY VARCHAR2` Associative array, a LOB
too large for memory with `LobMode::Inline`) throws `UnsupportedException`.
It never returns wrong data instead.

## Connection failures

- `ConnectionException`: no Session was obtained; wrong credentials (code
  1017), an unreachable host (12170 after `connectTimeout`), a listener error.
- `PoolExhaustedException`: the Pool was at `maxConnections` for the whole
  `acquireTimeout`.
- `ConnectionLostException`: the Connection is Broken. The call that saw it
  carries the original code and message; every later call throws again with
  the same code and `Connection is broken: <message>`. Take a new Connection;
  this one never recovers. See [Broken Connections](sessions.md#broken-connections).
- `TimeoutException`: the [Call timeout](connecting.md#timeouts) passed. The
  Connection survives when the server stopped the call; when it did not, the
  message says the Session is lost and the Connection is Broken.

An internal failure in the extension becomes a `ConnectionLostException`
(`internal error in rajska_oracle: …`) on a Broken Connection, never a crashed
PHP process.
