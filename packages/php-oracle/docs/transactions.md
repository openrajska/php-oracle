# Transactions and Array DML

## Autocommit

Outside an Explicit transaction a Connection is in Autocommit: every
successful `run()`, `select()`, `procedure()`, `function()` and `runMany()`
commits as part of its own Round trip, so the commit costs nothing extra. A
failed one commits nothing.

**`SELECT … FOR UPDATE` loses its locks at once under Autocommit**, because
the query commits too; fetching more of it afterwards fails with ORA-01002.
Lock rows inside a transaction:

```php
$connection->transaction(function (Connection $connection): void {
    $order = $connection->select('select * from orders where id = :id for update', ['id' => 1])->first();
    $connection->run("update orders set status = 'paid' where id = :id", ['id' => 1]);
});
```

## Explicit transactions

`transaction()` runs a closure in a transaction and returns its value. It
commits when the closure returns; when the closure throws anything, it rolls
back and rethrows the same exception.

```php
$customerId = $connection->transaction(function (Connection $connection): int {
    $customer = $connection->run(
        "insert into customers (name, country) values (:name, 'NO') returning id into :id",
        ['name' => 'Ole-Johan Dahl', 'id' => Number::out()],
    );
    $id = $customer->out('id')->int();
    $connection->run('insert into orders (customer_id, total) values (:customer, 10)', ['customer' => $id]);

    return $id;
});
```

- If the rollback fails too, the rollback's exception is chained as the
  original's `previous`.
- If the commit fails (a deferred constraint, ORA-02091), that exception
  propagates. Either way the Connection is back in Autocommit.
- Inside the closure, `begin()`, `commit()`, `rollback()` and `transaction()`
  throw `UsageException`.

For manual control, `begin()` opens a transaction with no Round trip, and
`commit()` or `rollback()` ends it in one Round trip each, back to
Autocommit:

```php
$connection->begin();
try {
    $connection->run("update orders set status = 'shipped' where id = :id", ['id' => 1]);
    $connection->commit();
} catch (Throwable $exception) {
    $connection->rollback();
    throw $exception;
}
```

Transaction bugs fail loudly with `UsageException`: `commit()` or
`rollback()` outside a transaction, and `begin()` or `transaction()` inside
one. Transactions do not nest.

### Savepoints, `SET TRANSACTION` and raw `COMMIT`

They go through `run()`:

```php
$connection->transaction(function (Connection $connection): void {
    $connection->run('set transaction isolation level serializable'); // first, right after the transaction opens
    $connection->run("update orders set status = 'paid' where id = 1");
    $connection->run('savepoint before_discount');
    $connection->run('update orders set total = total * 0.9 where id = 1');
    $connection->run('rollback to savepoint before_discount'); // undoes only the discount
});
```

Core never inspects your SQL, so it does not know what such statements do:

- `run('COMMIT')` commits on the server, but the Connection still counts as
  inside the transaction until `commit()` or `rollback()`, and the work after
  it is not in Autocommit.
- DDL (`CREATE`, `ALTER`, `DROP`, `TRUNCATE`…) commits implicitly, before and
  after itself: inside a transaction, the work before it is committed and no
  `rollback()` undoes it.

A Release always rolls back, so a transaction left open never commits: see
[Pool, Release and long-running workers](sessions.md).

## Array DML

`runMany()` runs one statement once per row of binds, sending every row in
one execute and one Round trip:

```php
$arrayDml = $connection->runMany(
    'insert into orders (customer_id, total) values (:customer, :total) returning id into :id',
    [
        ['customer' => 1, 'total' => 30, 'id' => Number::out()],
        ['customer' => 2, 'total' => 45.5, 'id' => Number::out()],
    ],
);

$arrayDml->count();    // 2 rows sent
$arrayDml->affected(); // 2 rows inserted
$ids = array_map(fn (OutValue $id): ?int => $id->int(), $arrayDml->out('id')); // one OutValue per row
```

**It applies every row or none.** When rows fail, one `ArrayDmlException` lists
every failing row, and nothing of that call is applied:

```php
try {
    $connection->runMany('insert into customers (name, email) values (:name, :email)', [
        ['name' => 'Alan Turing', 'email' => 'alan@example.com'],
        ['name' => 'Duplicate', 'email' => 'ada@example.com'], // already taken
        ['name' => null, 'email' => 'nobody@example.com'],      // NOT NULL
    ]);
} catch (ArrayDmlException $exception) {
    $exception->getMessage(); // '2 of 3 rows failed; first: row 1: ORA-00001: …'
    foreach ($exception->rows() as $failure) {
        // $failure->index (0-based), $failure->code, $failure->message
    }
}
```

- Under Autocommit, the rows commit with the execute; a failed call costs one
  more Round trip to roll back.
- Inside a transaction, each call sets a savepoint first (one more Round
  trip) and a failed call rolls back to it: the transaction's earlier work
  stays, still uncommitted.
- There is no limit on the rows of one call: chunk large inputs yourself, with
  `array_chunk($rows, 5000)`.
- `runMany($sql, [])` makes no Round trip and returns an empty `ArrayDml`.

**Rows are checked before any Round trip.** Every row needs the same keys.
Each column takes one type, from its first non-null value (`int` and `float`
mix as an exact NUMBER; any other mix fails, naming the row and bind); a
string over 32767 bytes makes its whole column CLOB; a typed bind keeps its
class down its column; an all-null column binds as NULL.

`runMany()` refuses a query, `Cursor::out()` and a block that returns
Implicit results. A PL/SQL block runs too, but stops at its first failing row
with that row's server error instead of a row list.
