# Connecting

`Oracle::connect()` takes a `ConnectionConfig` and returns a Connection bound
to one Session for its whole life. Durations are seconds (`int|float`).

```php no-run
$connection = Oracle::connect(new ConnectionConfig(
    dsn: 'db.example.com:1521/FREEPDB1',
    user: 'shop',
    password: $password,
    connectTimeout: 5,          // default 20
    callTimeout: 30,            // default null: no limit
    fetchSize: 500,             // default 100 rows per Round trip
));
```

`ConnectionConfig` validates in pure PHP, without the extension: a `fetchSize`
below 1 or a timeout that is not a positive number throws
`ConfigurationException`. `password` and `walletPassword` never show in
`var_dump()`, `print_r()`, messages or stack traces.

## The `dsn`

Three forms work:

| Form | Example |
|---|---|
| Easy Connect | `db.example.com:1521/FREEPDB1`, `tcps://db.example.com:2484/FREEPDB1` |
| Descriptor | `(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=db.example.com)(PORT=1521))(CONNECT_DATA=(SERVICE_NAME=FREEPDB1)))` |
| TNS alias | `sales`, looked up in `tnsnames.ora` in `configDir`, else in `$TNS_ADMIN` |

Settings the Backend would silently ignore are rejected with a
`ConfigurationException` that names what to use instead, before any network
I/O:

- Easy Connect Plus parameters (`?transport_connect_timeout=…`, `?wallet_location=…`);
- in a descriptor or a `tnsnames.ora` entry: `WALLET_LOCATION` (use
  `walletLocation`), `TRANSPORT_CONNECT_TIMEOUT` (use `connectTimeout`),
  `SSL_SERVER_DN_MATCH`, `SSL_SERVER_CERT_DN` and `POOL_PURITY` (remove them).

## TLS and mTLS

Use `tcps://` (or `PROTOCOL=TCPS`). The server certificate is checked against
the host name; its DN is never checked.

- **A public CA** needs no wallet. Trust comes from the CA roots bundled in
  the extension, not from the operating system's store.
- **A private CA, or mTLS** (a client certificate): point `walletLocation` at
  the directory holding `ewallet.pem`. If its private key is encrypted, set
  `walletPassword`.

```php no-run
$connection = Oracle::connect(new ConnectionConfig(
    dsn: 'tcps://db.example.com:2484/FREEPDB1',
    user: 'shop',
    password: $password,
    walletLocation: '/etc/oracle/wallet',
    walletPassword: $walletPassword,
));
```

Only `ewallet.pem` is read. A directory holding just `cwallet.sso` or
`ewallet.p12` fails with the conversion command:

```bash
openssl pkcs12 -in ewallet.p12 -out ewallet.pem   # add -legacy if OpenSSL 3 refuses it
```

**Autonomous Database.** Its wallet zip already contains `ewallet.pem`. Its
`tnsnames.ora` entries carry `(security=(ssl_server_dn_match=yes))`, which is
rejected as above: remove that clause (the host name is still verified), then
use the alias with `configDir` pointing at the unzipped wallet. One-way TLS to
Autonomous Database has not been tested against a real instance yet.

## Proxy authentication

`proxyUser` connects as that client user through the proxy account in
`user` (Oracle's `user[proxyUser]`), pooled or standalone. The database must
allow it: `alter user report_reader grant connect through shop`.

```php no-run
new ConnectionConfig(dsn: $dsn, user: 'shop', password: $password, proxyUser: 'report_reader');
```

## DRCP

Enable Database Resident Connection Pooling in the `dsn`: `db.example.com/FREEPDB1:pooled`,
or `(SERVER=POOLED)` in `CONNECT_DATA`. Without `drcpConnectionClass`, every
process of the application shares one connection class derived from `user`.

DRCP gives no gain under PHP-FPM yet: each worker keeps its pooled Sessions,
and the DRCP server stays attached to them while idle, because the client
does not release it between uses (see [Known limitations](known-limitations.md)).

## Timeouts

| Field | Default | Bounds |
|---|---|---|
| `connectTimeout` | 20 | Opening the network connection to the listener |
| `acquireTimeout` | 5 | Waiting for a free Session when the Pool is at `maxConnections` ([details](sessions.md)) |
| `callTimeout` | `null` | Each wait for the server, see below |

The **Call timeout** bounds each wait for the server on the network, so one
runaway query cannot hold a worker forever. It is not a limit on a whole
Execution: reading a large result takes many Round trips, each with its own
limit. `ExecuteOptions`' `timeout` overrides it for one Execution and the
Cursors it produces:

```php
try {
    $rows = $connection->select(
        'select * from orders order by placed_at',
        options: new ExecuteOptions(timeout: 2.5),
    )->all();
} catch (TimeoutException $exception) {
    // "Call timeout of 2500 ms exceeded."
}
```

When the limit passes, the client asks the server to stop the call and throws
`TimeoutException`. If the server stops it, the Connection goes on. If the
server ignores the request (`DBMS_SESSION.SLEEP` does), the Session is lost:
the Connection becomes Broken and its next call throws
`ConnectionLostException`.

An unreachable host fails with `ConnectionException` (code 12170) after
`connectTimeout`, plus about one second the client waits before giving up.

## Statement cache

Each Session keeps up to `statementCacheSize` (20) parsed statements, so a
repeated statement is not parsed again. `0` disables it. It is fixed when the
Session is created.

## Tracing

`Tracing` sets what a DBA sees for the Session in `V$SESSION`: `MODULE`,
`ACTION`, `CLIENT_IDENTIFIER` and `CLIENT_INFO`.

```php no-run
new ConnectionConfig(dsn: $dsn, user: 'shop', password: $password, tracing: new Tracing(module: 'checkout'));
```

Every `connect()` overwrites all four with the config's values (empty where
unset), so a client identifier set by the Session's previous user never
reaches yours, nor your VPD policies and audit trail. `trace()` replaces the
whole set mid-Connection; neither costs a Round trip of its own, the next one
carries it:

```php
$connection->trace(new Tracing(module: 'billing', action: 'close-month', clientIdentifier: 'user-42'));
```

## Server version

`serverVersion()` gives the database release from the connect handshake, with
no Round trip, ready for `version_compare()`:

```php
$version = $connection->serverVersion(); // '23.26.3.0.0' on 26ai
$hasVectors = version_compare($version, '23.4', '>=');
```

## The Pool key

These fields identify the Pool a `connect()` uses: `dsn`, `user`,
`password`, `proxyUser`, `walletLocation`, `walletPassword`, `configDir`,
`connectTimeout`, `drcpConnectionClass`, `maxConnections`, `acquireTimeout`,
`statementCacheSize` and `standalone`, plus PHP's default time zone
(`date_default_timezone_get()` when `connect()` runs), which every Session
of the Pool uses as its Session time zone. Configs that differ only in
`tracing`, `callTimeout`, `fetchSize`, `values`, `rowShape` or `keyCase`
share one Pool. A changed password creates a new Pool; the old one stays
idle until the process ends.

An application that switches the default zone per request (one zone per
tenant or per user, say) gets one Pool per zone, each with its own
`maxConnections`: a process serving three zones can hold up to three times
`maxConnections` Sessions. Size `maxConnections` and the database's
`processes` / `sessions` for the number of zones a process sees, or keep
PHP's default zone fixed and convert in PHP (`setTimezone()`).
