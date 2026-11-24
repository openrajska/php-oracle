# Running SQL and PL/SQL

| Verb | Runs | Returns |
|---|---|---|
| `run($sql, $binds)` | anything: a query, DML, DDL, an Anonymous block | `Execution` |
| `select($sql, $binds)` | a query; the same as `run()->rows()` | `Cursor` |
| `procedure($name, $binds)` | a stored procedure, by name | `Execution` |
| `function($name, $binds)` | a stored function, by name | `FunctionCall`, then a type terminal |
| `runMany($sql, $rows)` | one statement per bind row, in one Round trip ([Array DML](transactions.md#array-dml)) | `Batch` |

Binds are always named: `['id' => 1]` for `:id`. See [Binds](binds.md).

Core never classifies SQL. `select()` of an `UPDATE` runs the update, then
throws `UsageException` because there are no rows to return.

## Execution

```php
$execution = $connection->run("update orders set status = 'open' where status = :status", ['status' => 'closed']);

$execution->affected(); // rows changed by INSERT / UPDATE / DELETE / MERGE, 0 otherwise
$execution->warning();  // null, or a success-with-info text such as compilation errors
```

`out()` reads OUT and IN OUT values, `results()` the Implicit results and
`rows()` the rows of a query; each is below.

## Cursors

A Cursor is lazy: `foreach` brings `fetchSize` rows (100 by default) per
Round trip, so a large result streams in constant memory. A query of up to
`fetchSize` rows costs one Round trip in all.

```php
$cursor = $connection->select('select id, name, email from customers order by id');

foreach ($cursor as $position => $customer) {
    // $customer = ['ID' => 1, 'NAME' => 'Ada Lovelace', 'EMAIL' => 'ada@example.com']
}

$first = $connection->select('select * from customers order by id')->first();    // next row or null, then closes
$count = $connection->select('select count(*) from customers')->value();         // first column, then closes
$every = $connection->select('select * from orders')->all();                      // every remaining row
```

- **Single pass.** A second `foreach` continues where the first stopped,
  `all()` returns the rows not read yet, and a finished Cursor gives nothing.
- **Closing.** The server cursor closes on `close()` or when the object goes,
  riding on the next Round trip. Dropping Cursors half-read never exhausts
  the server's `open_cursors`.
- **An open Cursor blocks nothing.** Other statements, other Cursors,
  `commit()` and `rollback()` can run between two batches.
- **Columns.** `columns()` describes every column (`name`, `type`,
  `nullable`, `precision`, `scale`, `maxSize`, `objectType`, the VECTOR
  fields) before the first row, also for an empty result.

### Row shape

Rows are keyed by column name exactly as Oracle reports it (unquoted names
are uppercase). Choose another shape per Connection in `ConnectionConfig`,
or per Execution:

```php
$rows = $connection->select(
    'select id, name from customers order by id',
    options: new ExecuteOptions(keyCase: KeyCase::Lower),
)->all();
// [['id' => 1, 'name' => 'Ada Lovelace'], ...]

$lists = $connection->select(
    'select id, name from customers order by id',
    options: new ExecuteOptions(rowShape: RowShape::List),
)->all();
// [[1, 'Ada Lovelace'], ...]
```

Two columns with the same name under `RowShape::Assoc` throw
`UsageException` when the Cursor opens, never overwrite each other: alias
them, or use `RowShape::List`.

`ExecuteOptions` also takes `fetchSize`, `timeout` and `values`. Every Cursor
born of an Execution (its rows, REF CURSORs, nested Cursors, Implicit
results) inherits that Execution's options, and they cannot change
afterwards.

## Calls

`procedure()` and `function()` call a stored subprogram by name, in named
notation, so an omitted parameter takes its PL/SQL default. Binds are keyed by
parameter name. The name is 1 to 3 dot-separated parts (`schema.package.name`),
each an identifier or a double-quoted name; anything else fails before any
Round trip, so a name never injects SQL.

```php
$execution = $connection->procedure('billing.close_month', [
    'month_start' => Date::in(new DateTimeImmutable('first day of next month')),
    'closed' => Number::out(),
    'orders' => Cursor::out(),
]);

$closed = $execution->out('closed')->int();
foreach ($execution->out('orders')->cursor() as $order) {
    // ['ID' => 1, 'TOTAL' => '120.5']
}
```

`function()` runs when a type terminal names the return type:

```php
$total = $connection->function('billing.order_total', ['order_id' => 1])->number(); // '120.5'
```

The terminals mirror the [bind classes](binds.md#typed-binds): `varchar()`,
`number()`, `boolean()`, `date()`, `timestampTz()`, `clob()`, `json()`,
`cursor()`, `struct('type')`, `collection('type')` and the rest. A function
that also has OUT parameters is called through `run()` with an Anonymous
block:

```php no-run
$execution = $connection->run('BEGIN :total := billing.total_with_tax(:order_id, :tax); END;', [
    'total' => Number::out(),
    'order_id' => 1,
    'tax' => Number::out(),
]);
```

## OUT values

`out('name')` returns an `OutValue`, read with a getter that narrows and never
converts: `int()`, `string()`, `float()`, `bool()`, `dateTime()`,
`interval()`, `lob()`, `array()`, `cursor()`, or `value()` for whatever it is.
`int()` on `'12.5'` throws `UsageException` naming `ValueMapping`; a NULL
gives `null`.

`RETURNING … INTO` comes back through `out()` too:

```php
$insert = $connection->run(
    "insert into customers (name, country) values (:name, 'BR') returning id into :id",
    ['name' => 'Nise da Silveira', 'id' => Number::out()],
);
$customerId = $insert->out('id')->int();

$closed = $connection->run(
    "update orders set status = 'closed' where status = 'open' returning id into :id",
    ['id' => Number::out()],
);
$closedIds = $closed->out('id')->list(); // every returned row
```

A scalar getter needs exactly one returned row and throws on none or several;
`list()` always works. LOBs, Objects and Collections come back the same way,
one per returned row.

## REF CURSORs

A REF CURSOR from an OUT or IN OUT bind (`Cursor::out()`, `Cursor::inOut()`),
a function's return (`->cursor()`) or a `CURSOR()` column arrives as a
Cursor. Several from one Call can be read in any order, interleaved.

```php
$customers = $connection->select(
    'select c.name, cursor(select o.total from orders o where o.customer_id = c.id) as orders
       from customers c order by c.id',
)->all();

foreach ($customers as $customer) {
    $orders = $customer['ORDERS']->all(); // still readable after the parent was read
}
```

A nested Cursor stays readable after its parent was read, until the parent's
explicit `close()`. A REF CURSOR the procedure never opened fails on its first
read (ORA-01001). A cursor cannot be passed as IN yet.

## Implicit results

Rows a block returns with `DBMS_SQL.RETURN_RESULT` come back from
`results()`, as Cursors in the order they were returned, beside any OUT
value:

```php
[$customers, $totals] = $connection->procedure('billing.monthly_report')->results();

$customerRows = $customers->all();
$totalRows = $totals->all();
```

## DBMS_OUTPUT

```php
$connection->enableOutput();   // turns it on, with an empty buffer
$connection->procedure('billing.greet', ['name' => 'Ada']);
$lines = $connection->output(); // ['Hello, Ada']
$connection->disableOutput();
```

`output()` drains every line, 1000 per Round trip, and reads output enabled
through raw SQL too. Output enabled with `enableOutput()` is turned off before
the Session goes back to the Pool.

## Compilation warnings

Creating PL/SQL with compilation errors succeeds with a warning, as in
SQL*Plus. `warning()` returns it instead of throwing, so you decide what it
means:

```php
$execution = $connection->run('create or replace procedure unfinished_procedure is begin null end;');
$warning = $execution->warning(); // 'creation succeeded with compilation errors'
```
