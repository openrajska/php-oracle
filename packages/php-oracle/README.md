# openrajska/php-oracle

A typed API for Oracle Database in PHP, with full PL/SQL fidelity: REF
CURSORs, typed OUT and IN OUT binds, Implicit results, Objects and
Collections, Array DML with per-row errors, and exact values that never
depend on NLS settings. It runs on the `rajska_oracle` extension, a Rust thin
Backend: no Oracle Instant Client, nothing to compile.

> [!WARNING]
> Pre-alpha. Nothing is released yet, and the API may still change.

## Requirements

- PHP 8.4 (FPM, CLI, FrankenPHP worker mode or Octane; sync only)
- Oracle AI Database 26ai
- The `rajska_oracle` extension, from `openrajska/php-oracle-ext`

## Installing

```bash
pie install openrajska/php-oracle-ext   # a pre-built binary
composer require openrajska/php-oracle
php --ri rajska_oracle                    # check it is loaded
```

CLI and FPM read different `php.ini` files: check the extension is enabled
for the SAPI that serves your application. Without it, the first
`Oracle::connect()` throws a `ConfigurationException` that says so. Loading
Core's classes and building a `ConnectionConfig` never need the extension.

**Build stages and static analysis.** Core requires `ext-rajska_oracle`, so
Composer refuses to install where the extension is not loaded, such as a
Docker build stage or a PHPStan job. Tell it the extension comes later:

```bash
composer install --ignore-platform-req=ext-rajska_oracle
```

**Upgrading.** Core declares the oldest extension it works with
(`ext-rajska_oracle: ^0.1`), and the extension is always released before the
Core release that needs it. So upgrade the extension first, then Core. An
extension outside the range Core accepts makes `connect()` throw a
`ConfigurationException` naming both versions and the `pie install` command
to run.

## Quick start

```php no-run
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Oracle;

$connection = Oracle::connect(new ConnectionConfig(
    dsn: 'db.example.com:1521/FREEPDB1',
    user: 'shop',
    password: getenv('DATABASE_PASSWORD'),
));
```

`connect()` takes a Session from a Pool the process keeps for that config, so
call it whenever you need a Connection: there is no Pool object to manage.

```php
$customers = $connection->select(
    'select id, name from customers where country = :country order by id',
    ['country' => 'GB'],
);

foreach ($customers as $customer) {
    $name = $customer['NAME'];
}

$count = $connection->select('select count(*) from orders')->value(); // int

$execution = $connection->run(
    'update orders set status = :status where customer_id = :customer',
    ['status' => 'shipped', 'customer' => 1],
);
$updated = $execution->affected();
```

Outside a transaction every statement commits on its own. Group statements
with `transaction()`:

```php
$orderId = $connection->transaction(function (Connection $connection): int {
    $insert = $connection->run(
        'insert into orders (customer_id, total) values (:customer, :total) returning id into :id',
        ['customer' => 1, 'total' => Number::in('42.00'), 'id' => Number::out()],
    );

    return $insert->out('id')->int();
});
```

Call a stored procedure or function by name:

```php
$total = $connection->function('billing.order_total', ['order_id' => 1])->number();

$execution = $connection->procedure('billing.close_month', [
    'month_start' => Date::in(new DateTimeImmutable('first day of this month')),
    'closed' => Number::out(),
    'orders' => Cursor::out(),
]);
$closed = $execution->out('closed')->int();
$closedOrders = $execution->out('orders')->cursor()->all();
```

When you are done, `close()` the Connection, or let it go out of scope: either
way its Session goes back to the Pool, and an open transaction rolls back.

## Guides

- [Connecting](docs/connecting.md): `ConnectionConfig`, TLS, proxy users,
  DRCP, timeouts and Tracing
- [Pool, Release and long-running workers](docs/sessions.md): what a Release
  resets and what it does not, `standalone`, FrankenPHP and Octane
- [Running SQL and PL/SQL](docs/running.md): Cursors, Calls, OUT values, REF
  CURSORs, Implicit results, DBMS_OUTPUT
- [Binds](docs/binds.md): plain values, typed binds, LOBs, Objects and
  Collections, `TABLE(:ids)`
- [Values](docs/values.md): what each Oracle type becomes in PHP, and the
  faster opt-ins
- [Transactions and Array DML](docs/transactions.md)
- [Errors](docs/errors.md)
- [Testing your application](docs/testing.md): a throwaway 26ai Free
  database in CI
- [Known limitations](docs/known-limitations.md)

Every PHP example in these docs runs in Core's test suite. Examples assume
`use` imports of the classes they name: `Rajska\Oracle\*`, the bind classes
in `Rajska\Oracle\Bind\*` (so `Cursor` there is `Bind\Cursor`) and the
exceptions in `Rajska\Oracle\Exceptions\*`.

The vocabulary (Session, Release, Round trip, Call, Implicit result…) is
defined in the [glossary](GLOSSARY.md).

## License

MIT or Apache-2.0, at your option.
