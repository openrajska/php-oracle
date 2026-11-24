# Binds

A Bind is a value sent apart from the SQL text, matched to a `:name`
placeholder. Binds are always named, keyed without the `:`, and matched
ignoring case:

```php
$orders = $connection->select(
    'select * from orders where customer_id = :customer and status = :status',
    ['customer' => 1, 'status' => 'open'],
)->all();
```

These fail with `UsageException` before any Round trip: a `list` or any
integer key, a key starting with `:`, two keys differing only in case, a
missing bind and an extra one. `?` placeholders are not supported.

## Plain PHP values

| PHP | Binds as |
|---|---|
| `int` | NUMBER |
| `float` | NUMBER, exactly as PHP prints it: `0.1` stores `0.1`, not a binary approximation |
| `string` up to 32767 bytes | VARCHAR2 |
| `string` over 32767 bytes | CLOB, through a temporary LOB |
| `bool` | BOOLEAN |
| `null` | NULL (as VARCHAR2) |
| `BackedEnum` | its backing value |
| `Stringable` | VARCHAR2 |
| `DateTimeInterface` (Carbon too) | **error**: use `Date::in()`, `Timestamp::in()`… |
| array, `JsonSerializable`, any other object | **error**: use `Json::in()` or a typed bind |

Anything else needs a typed bind. In particular:

- **Binary data goes through `Raw` or `Blob`.** A PHP string is bytes, and a
  plain string binds as text: bytes that are not valid UTF-8 fail, naming
  `Raw::in()` and `Blob::in()`.

  ```php
  $connection->run(
      'insert into documents (title, checksum) values (:title, :checksum)',
      ['title' => 'Invoice', 'checksum' => Raw::in(hash('sha256', 'invoice', binary: true))],
  );
  ```

- **`CHAR` columns compare blank-padded only with `Char::in()`.** A plain
  string binds as VARCHAR2, and Oracle compares a CHAR column with a VARCHAR2
  value without padding, so `'gold'` never equals a `CHAR(10)` holding
  `'gold      '`:

  ```php
  $goldCustomers = $connection->select(
      'select name from customers where tier = :tier',
      ['tier' => Char::in('gold')], // matches; a plain 'gold' would not
  )->all();
  ```

- **A numeric string stays text.** Use `Number::in()` for an exact decimal:
  it takes a decimal literal with `.` and no exponent, whatever the Session's
  NLS settings say.

  ```php
  $connection->run(
      'insert into orders (customer_id, total) values (:customer, :total)',
      ['customer' => 1, 'total' => Number::in('19.90')],
  );
  ```

- **Dates need their type**, because a `DateTimeInterface` carries a zone
  that a DATE does not have:

  | Bind | Stores |
  |---|---|
  | `Date::in($dateTime)` | the object's Wall clock, its zone ignored, fractions dropped |
  | `Timestamp::in($dateTime)` | the object's Wall clock, its zone ignored |
  | `TimestampTz::in($dateTime)` | the Instant, with the object's offset at that Instant |
  | `TimestampLtz::in($dateTime)` | the Instant |
  | `IntervalDs::in($interval)` / `IntervalYm::in($interval)` | a `DateInterval` (no years or months for DS; nothing below a month for YM) |

  `Date::in()` travels to the server as a TIMESTAMP. Compared with an indexed
  DATE column, that can keep Oracle from using the index; write
  `placed_at >= cast(:since as date)` there.

  ```php
  $recentOrders = $connection->select(
      'select id from orders where placed_at >= cast(:since as date)',
      ['since' => Date::in(new DateTimeImmutable('-30 days'))],
  )->all();
  ```

## Typed binds

Each Oracle type has a class in `Rajska\Oracle\Bind\` with `in()`, `out()` and
`inOut()`:

| Class | `in()` takes |
|---|---|
| `Varchar`, `Char`, `NVarchar`, `NChar` | `?string`, `Stringable`, up to 32767 bytes |
| `Number` | `int`, `float`, a decimal string, `null` |
| `BinaryFloat`, `BinaryDouble` | `int`, `float`, `null` |
| `PlsInteger` | `?int` |
| `Boolean` | `?bool` |
| `Date`, `Timestamp`, `TimestampTz`, `TimestampLtz` | `?DateTimeInterface` |
| `IntervalDs`, `IntervalYm` | `?DateInterval` |
| `Raw` | `?string` of bytes, up to 32767 |
| `Rowid` | `?string` |
| `Clob`, `NClob`, `Blob` | `string`, an open stream, `null` |
| `Json` | anything JSON can encode |
| `Vector` | a `list` of numbers and a `VectorFormat`, or a `SparseVector` |
| `Cursor` | no `in()` yet: `out()` and `inOut()` only |
| `Struct`, `Collection`, `Indexed` | the type name and an array (below) |

`NVarchar` and `NChar` bind in the national character set, for NVARCHAR2 and
NCHAR columns.

**OUT and IN OUT need no size.** Text and RAW OUT values get the largest
buffer (32767 bytes), so they are never cut short:

```php
$execution = $connection->run(
    'BEGIN :greeting := :greeting || \', world\'; END;',
    ['greeting' => Varchar::inOut('Hello')],
);
$greeting = $execution->out('greeting')->string(); // 'Hello, world'
```

## LOBs

`Clob::in()`, `NClob::in()` and `Blob::in()` take a string or an open stream.
The value goes into a temporary LOB on the Session in 1 MiB chunks, so a
large file uploads without being loaded into memory:

```php no-run
$file = fopen('/uploads/contract.pdf', 'rb');
$connection->run(
    'insert into documents (title, attachment) values (:title, :attachment)',
    ['title' => 'Contract', 'attachment' => Blob::in($file)],
);
fclose($file);
```

A stream is read from its current position to its end, and never rewound or
closed. `Clob::in('')` is an empty LOB, not NULL. A LOB bind costs one Round
trip, plus one per MiB, before the statement's own.

## Objects, Collections and Associative arrays

They bind as plain PHP arrays, naming the type as a string, in the same shape
a fetched value has, so a fetched value binds back unchanged:

| Class | Value |
|---|---|
| `Struct::in('address', $value)` | an Object: an array keyed by attribute name (matched ignoring case; every attribute needs a key) |
| `Collection::in('address_list', $value)` | a Collection (nested table or VARRAY): a `list` |
| `Indexed::in('billing.amounts', $value)` | an `INDEX BY PLS_INTEGER` Associative array: `int` keys, sparse and negative ones kept |

```php
$connection->run('BEGIN :copy := :addresses; END;', [
    'addresses' => Collection::in('address_list', [
        ['STREET' => '12 Downing Street', 'CITY' => 'London'],
        ['street' => 'Rua Augusta', 'city' => null],
    ]),
    'copy' => Collection::out('address_list'),
]);

$execution = $connection->procedure('billing.apply_discounts', [
    'discounts' => Indexed::in('billing.amounts', [10 => 5.0, 20 => 7.5]),
    'applied' => Number::out(),
]);
```

- `in(null)` is a NULL of the type; `[]` is an empty Collection; an Object
  with every attribute NULL is an array of `null`s.
- Type names follow Oracle's identifier rules: unquoted parts are
  uppercased, double-quoted parts are kept, and `package.type` names a type
  in a package.
- Keys, kinds and value types are checked before any Round trip. The type is
  described on its first use in a Session, which costs Round trips once.
- `INDEX BY VARCHAR2` Associative arrays are not supported.

### `TABLE(:ids)`: filtering by a list

Bind a Collection and query it with `TABLE()`, to filter by thousands of ids
in one statement:

```php
$ids = [1, 2, 3];

$orders = $connection->select(
    'select o.* from orders o join table(:ids) ids on ids.column_value = o.id',
    ['ids' => Collection::in('SYS.ODCINUMBERLIST', $ids)],
)->all();

$customers = $connection->select(
    'select * from customers where email in (select column_value from table(:emails))',
    ['emails' => Collection::in('SYS.ODCIVARCHAR2LIST', ['ada@example.com', 'grace@example.com'])],
)->all();
```

- Write the owner: `SYS.ODCINUMBERLIST`. Types reached through a public
  synonym (`ODCINUMBERLIST` alone) do not resolve.
- A type of your own works the same: `create type id_list as table of number`,
  then `Collection::in('id_list', $ids)`.
