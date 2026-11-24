# Values

Columns, OUT values, Object attributes, Collection elements and JSON all map
the same way, and none of it depends on NLS settings: an `ALTER SESSION` never
changes what reaches PHP.

| Oracle | PHP |
|---|---|
| NUMBER, FLOAT | `int` when integral and within 64 bits, else an exact decimal string: `'0.5'`, `'12.3'`, no exponent |
| BINARY_FLOAT, BINARY_DOUBLE | `float`, NaN and ±INF included |
| DATE, TIMESTAMP | `DateTimeImmutable`: the stored Wall clock, in PHP's default time zone, to the microsecond |
| TIMESTAMP WITH TIME ZONE | `DateTimeImmutable` with its own offset |
| TIMESTAMP WITH LOCAL TIME ZONE | `DateTimeImmutable`: the Instant, in PHP's default time zone |
| INTERVAL DAY TO SECOND, YEAR TO MONTH | `DateInterval` (`invert` for negatives) |
| VARCHAR2, NVARCHAR2, LONG | `string`, UTF-8 |
| CHAR, NCHAR | `string`, blank-padded as stored |
| RAW, LONG RAW | `string` of bytes |
| ROWID, UROWID | `string` |
| BOOLEAN | `bool` |
| PLS_INTEGER | `int` |
| JSON | like `json_decode($text, true)`, with numbers mapped as NUMBER |
| VECTOR | `list<float>` (FLOAT32, FLOAT64), `list<int>` (INT8, and BINARY as bytes), or a `SparseVector` |
| CLOB, NCLOB, BLOB | `Lob` |
| Object, Collection, Associative array | array ([shapes](binds.md#objects-collections-and-associative-arrays)) |
| REF CURSOR, `CURSOR()` | `Cursor` |
| NULL | `null` |

```php
$row = $connection->select(
    "select 5 as whole, 12.30 as exact, count(*) over () as counted, date '2026-03-01' as day from dual",
)->first();
// ['WHOLE' => 5, 'EXACT' => '12.3', 'COUNTED' => 1, 'DAY' => DateTimeImmutable 2026-03-01 00:00:00]
```

Exact decimals come back as strings, never as floats that round. Note they
differ from oci8's: `'0.5'`, not `'.5'`.

## Faster opt-ins

`ValueMapping` trades exactness or objects for speed, per Connection (in
`ConnectionConfig`) or per Execution (in `ExecuteOptions`, which also covers
every Cursor and OUT value of that Execution):

| Option | Effect |
|---|---|
| `numbers: NumberMode::Float` | a non-integral NUMBER becomes a `float`; integral ones stay `int` |
| `temporals: TemporalMode::String` | DATE, TIMESTAMP, TSTZ and TSLTZ become ISO text at full precision: `'2026-03-01 10:00:00.123456789'`, plus `' -03:00'` with a zone; INTERVALs stay objects |
| `lobs: LobMode::Inline` | CLOB and NCLOB become a `string`, BLOB bytes; an empty LOB is `''` |

```php
$totals = $connection->select(
    'select id, total from orders',
    options: new ExecuteOptions(values: new ValueMapping(numbers: NumberMode::Float)),
)->all();
// [['ID' => 1, 'TOTAL' => 120.5], ...]
```

`LobMode::Inline` costs no extra Round trip for a LOB of up to 32767
characters or bytes: it arrives with its row (see [LOBs](#lobs)). A larger one
costs one extra Round trip. A LOB too large for `memory_limit` throws
`UnsupportedException`; read it as a `Lob` instead.

## Dates and time zones

When the Pool creates a Session, it sets the Session time zone to PHP's
default zone by name (`date_default_timezone_get()`), so `CURRENT_TIMESTAMP`
follows daylight saving.

- DATE and TIMESTAMP hold a Wall clock and come back as that Wall clock in
  PHP's default zone. A Wall clock that does not exist in that zone (inside a
  daylight-saving gap) is shifted by PHP; `TemporalMode::String` gives the
  stored text.
- TIMESTAMP WITH TIME ZONE keeps its numeric offset. A value stored with a
  region name (`America/Sao_Paulo`) comes back as the right Instant in UTC.
  `CURRENT_TIMESTAMP` carries the Session's region name, so it comes back in
  UTC too; `SYSTIMESTAMP` carries an offset.
- TIMESTAMP WITH LOCAL TIME ZONE is converted to PHP's default zone at fetch
  time. A database whose `DBTIMEZONE` is a region name, not an offset, makes
  it fail with `UnsupportedException`.
- Fractions finer than a microsecond are dropped; BC years work.
- After `date_default_timezone_set()`, the next `connect()` uses a separate
  Pool whose Sessions have the new zone. Connections already open keep their
  Session's zone.

## LOBs

A CLOB, NCLOB or BLOB arrives as a read-only `Lob`. A row of a query, a REF
CURSOR, a nested `CURSOR()` or an Implicit result brings up to 32767
characters (CLOB, NCLOB) or bytes (BLOB) of each LOB with it, so reading a LOB
that small costs no Round trip; a larger one costs Round trips only when you
read it:

```php
$connection->run(
    'insert into documents (title, body) values (:title, :body)',
    ['title' => 'Terms', 'body' => str_repeat('Lorem ipsum. ', 10_000)],
);

$body = $connection->select("select body from documents where title = 'Terms'")->value();

$body->type();     // LobType::Clob
$body->size();     // 130000: UCS-2 code units for CLOB and NCLOB, bytes for BLOB
$text = $body->contents();
```

- `stream()` returns a read-only stream resource, for large values in
  constant memory: `stream_copy_to_stream($lob->stream(), $output)`.
- A large LOB is read 1 MiB per Round trip, and each Round trip's data
  arrives in packets of the Session Data Unit, 8 KiB by default.
  `(SDU=65535)` in the `dsn` reads a 16 MiB BLOB about 22% faster, at more
  memory per Session ([Packet size](connecting.md#packet-size-sdu)).
- A `Lob` reads in one pass: after `contents()` or `stream()` consumed it,
  the next read gives `''`.
- It is valid only while its Connection keeps its Session. After the Release,
  every call throws `UsageException`.
- `EMPTY_CLOB()` and `EMPTY_BLOB()` give a `Lob` of size 0; NULL gives `null`.

## JSON

A native JSON column decodes to arrays, with exact numbers:

```php
$connection->run(
    'insert into events (happened_at, payload) values (systimestamp, :payload)',
    ['payload' => Json::in(['type' => 'order.paid', 'amount' => 120.5, 'tags' => ['web']])],
);

$payload = $connection->select('select payload from events order by id desc')->value();
// ['type' => 'order.paid', 'amount' => '120.5', 'tags' => ['web']]
```

- JSON numbers follow `numbers`, like NUMBER columns. Extended scalars
  (dates, binary, intervals) map like their columns; a DATE inside JSON comes
  back as a TIMESTAMP.
- Key order is not kept, and `{}` and `[]` both decode to `[]`.
- `Json::in()` takes anything `json_encode()` would: an associative array
  becomes an object, a `list` an array, a `JsonSerializable` is expanded, a
  `float` becomes an exact NUMBER and a `DateTimeInterface` a TIMESTAMP.

## VECTOR

```php
$vector = $connection->select("select to_vector('[1.5, 2, 3]', 3, float32) from dual")->value();
// [1.5, 2.0, 3.0]

$dimensions = $connection->select(
    'select vector_dimension_count(:embedding) from dual',
    ['embedding' => Vector::in([0.12, -0.5, 0.33], VectorFormat::Float32)],
)->value();
// 3
```

A sparse VECTOR comes back as a `SparseVector` (`dimensions`, and `values`
keyed by position), and binds with `Vector::in(new SparseVector($dimensions, $values))`.
