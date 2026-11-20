# Can an ext-php-rs class implement a userland interface, and what does a PHP layer over the Extension cost?

Research for [#18](https://github.com/openrajska/php-oracle/issues/18), feeding
the seam-shape ticket [#20](https://github.com/openrajska/php-oracle/issues/20)
of the map [#16](https://github.com/openrajska/php-oracle/issues/16).
Terms follow the [Core glossary](../../packages/php-oracle/GLOSSARY.md).

Every claim is tagged:

- **[source]**: verified in the source code or the official documentation linked next to it.
- **[measured]**: measured in this repo's ddev environment (method in [§4](#4-what-a-php-layer-costs)).
- **[inference]**: my reasoning from the facts, not verified directly.

Pinned versions: ext-php-rs [`ext-php-rs-v0.16.5`](https://github.com/extphprs/ext-php-rs/tree/ext-php-rs-v0.16.5)
(the spike's version), php-src [`PHP-8.4` at `8748e22`](https://github.com/php/php-src/tree/8748e2265d12d1bbed8e153846adfbe823580831).

## Short answer

1. **No.** A class that ext-php-rs registers cannot implement an interface that
   Core declares in userland PHP. ext-php-rs resolves the interfaces in MINIT, before
   any userland code exists, and the Zend engine has no safe way to add a
   per-request user interface to a process-wide internal class later.
2. **Four shapes work**: (B) Core adapter classes that wrap the native objects;
   (C) Core classes that `extend` the native classes and add `implements`;
   (D) the Extension declares the Driver contract's interfaces natively, under
   Core's names, and Core keeps a PHP copy for when the Extension is absent;
   (E) structural typing, with no interface check.
3. **The PHP layer is cheap if it never touches a value.** Forwarding one call
   through a PHP wrapper costs about **4–8 ns** [measured]. For each Call that is
   noise (a Call takes about 13 µs). For each row it is 7% of the remaining
   distance to 2× on the 10k-row fetch. A PHP loop that touches each value of a
   row costs about **180–220 ns per 9-column row**, more than the whole gap.
   In the spike, one native call per row and one call for all 10k rows were
   equally fast (8.60 vs 8.66 ms) [source](../../spikes/rust-oracledb/bench/output/summary-nts.md).
4. **Yes, ext-php-rs can return an already-built PHP array of rows in one call**
   (`ZBox<ZendHashTable>` of row arrays; the spike's `fetchAll()` does exactly that).
   The cost is in building each row, not in crossing the boundary.
5. **Recommendation for #20**: shape (B). Core owns the Driver contract as PHP
   interfaces, and a thin Core adapter forwards to the Extension's native classes.
   Rows cross the seam already built by the Extension, ideally a batch per call,
   and Core's PHP never touches individual values on the fetch path. Keep (D) as
   a fallback if a measurement ever shows the forwarding cost matters. It does not today.

## 1. Why a native class cannot implement a userland interface

### ext-php-rs resolves interfaces once, in MINIT

- `#[php(implements(ce = ce_fn, stub = "…"))]` takes a function
  `fn() -> &'static ClassEntry` for the interface ("For built-in PHP interfaces"),
  or a Rust type that implements `RegisteredClass` for an interface declared with
  `#[php_interface]` [source: [guide, `#[php_class]` options](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/guide/src/macros/classes.md#L25-L30)].
- `ClassBuilder::register()` asserts that it runs inside MINIT: *"Classes can
  only be registered from a module startup (MINIT) function"*. Inside it, the
  interface getter is called and the result is passed to `zend_do_implement_interface`
  [source: [`src/builders/class.rs` L438–L443](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/builders/class.rs#L438-L443),
  [L505–L513](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/builders/class.rs#L505-L513)].
- In MINIT no request is running and no Composer autoloader is registered, so a
  getter such as `ClassEntry::try_find("OpenRajska\\Oracle\\Driver\\Connection")`
  finds nothing [source: `try_find` is a plain `zend_lookup_class_ex`,
  [`src/zend/class.rs` L32–L38](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/zend/class.rs#L32-L38)].
  The assertion in `implements` would then panic and the module would fail to load [inference].

### Zend: internal classes outlive the userland classes of a request

- An internal class entry is `malloc`ed (persistent memory), marked
  `ZEND_INTERNAL_CLASS` and linked at registration
  [source: [`Zend/zend_API.c` L3516–L3544](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_API.c#L3516-L3544)].
- At request start the engine records how many classes are persistent. At request end it
  destroys every class past that count, which means every userland class and interface
  [source: [`Zend/zend_execute_API.c` L191–L193](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_execute_API.c#L191-L193),
  [L470–L471 and L489–L495](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_execute_API.c#L470-L495)].
- `zend_do_implement_interface` has no guard against an internal class taking a
  user interface. For an internal class it just `realloc`s the persistent
  `interfaces` array [source: [`Zend/zend_inheritance.c` L2180–L2210](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_inheritance.c#L2180-L2210)].
  So a native class patched at request time to implement a Core interface would hold a
  dangling pointer after that request ends. On ZTS (FrankenPHP) the internal class is
  also shared by every thread [inference]. A "late `implements` in RINIT" hack is
  therefore unsound, and ext-php-rs forbids registration outside MINIT anyway.

**Conclusion [source + inference]:** the only interfaces a native class can
implement are the ones that exist in MINIT: built-in ones, or ones that an
extension declares natively.

## 2. What does work

### (B) Core adapter classes wrap the native objects (composition)

Core declares `final class ExtConnection implements Driver\Connection` holding a
`\Rajska\Oracle\Connection` and forwarding each method. Nothing special is needed
from ext-php-rs: the native object is a plain object to Core. The cost is one
extra PHP frame per forwarded call ([§4](#4-what-a-php-layer-costs)).

### (C) Core classes extend the native classes and add `implements`

PHP allows `final class ExtConnection extends \Rajska\Oracle\Connection implements Driver\Connection {}`:

- A userland subclass inherits `create_object` from its parent ("You cannot
  change create_object") [source: [`Zend/zend_inheritance.c` L139–L140](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_inheritance.c#L139-L140)],
  so its instances get ext-php-rs's object layout and handlers.
- ext-php-rs supports PHP subclasses of Rust classes. `resolve()` accepts an
  object whose handlers are the Rust class's and that is `instance_of` it, and it
  rejects a subclass object whose constructor never called `parent::__construct()`
  (the Rust state is `None`) [source: [`src/types/class_object.rs` L240–L268](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/types/class_object.rs#L240-L268);
  changelog "Handle PHP mocks and subclasses of Rust-backed classes"
  [#653](https://github.com/extphprs/ext-php-rs/pull/653)]. Its integration tests
  extend a Rust class in PHP and call an inherited Rust method
  [source: [`tests/src/integration/class/class.php` L326–L345](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/tests/src/integration/class/class.php#L326-L345)].
- An inherited internal method satisfies a userland interface method, and a call
  through the interface costs the same as a direct call (20.1 vs 20.0 ns) [measured].
  The measurement used `SplFixedArray`, an internal class, not an ext-php-rs class.

Costs and catches:

- **Factories.** `Connection::query()` creates the Statement / Cursor object in Rust.
  To return Core's subclass instead of the native base class, the Extension must
  look the subclass up at runtime and build it. ext-php-rs exposes what that needs:
  `ZendClassObject::<T>::new_uninit(Some(ce))` allocates an object of any class
  entry `ce` (including the declared properties of a subclass) and `initialize(val)` sets the Rust state
  [source: [`src/types/class_object.rs` L74–L151](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/types/class_object.rs#L74-L151)].
  No test or doc covers it for a userland `ce` [inference: it should work, but it is unverified].
  The Extension then has to know Core's class names, or receive them on each call,
  and must never cache a userland class entry across requests (see §1).
- **Signature lock-step.** PHP checks each inherited native method against
  Core's interface (LSP) when the subclass is linked. A drift between an
  Extension release and a Core release is a fatal error at class load [inference
  from standard PHP inheritance rules].
- The native classes cannot be `final`, and the native method names become the
  Driver contract's method names.

### (D) The Extension declares the Driver contract's interfaces natively

ext-php-rs can declare an interface from a Rust trait (`#[php_interface]`) and have
a Rust class implement it (`#[php_impl_interface]`). Userland classes can
implement such an interface too [source: [guide, `#[php_interface]`](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/guide/src/macros/interface.md);
[`tests/src/integration/interface/interface.php` L11, L170–L198](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/tests/src/integration/interface/interface.php#L170-L198)].

If the Extension declares them under **Core's** names, Core's own PHP copies
never load while the Extension is present: Composer autoloading only runs for a
class or interface that is "currently not defined" [source: [PHP manual, Autoloading Classes](https://www.php.net/manual/en/language.oop5.autoload.php)].
Without the Extension, Core's PHP copies load as usual, so a pure-PHP Backend still works.
Per-call cost is zero. The price:

- the Driver contract is written twice (Rust trait + PHP interface) and must
  match exactly;
- the interface that Core's code sees at runtime comes from the binary, so Core
  and Extension versions are tightly coupled: an old Extension with a newer Core
  gives missing methods or signature fatals [inference];
- the Extension owns part of Core's namespace, which blurs "Core owns the Driver contract".

### (E) Structural typing (no interface check)

Core accepts `object` and calls methods by name. There is no added cost, but also no
`instanceof` check, no native type checks, and static analysis needs stubs. The
conformance suite would be the only guard. This only makes sense as an internal
shortcut on top of B, C or D [inference].

### Not viable: (A) native implements the userland interface, and (F) eval'd PHP in the Extension

- (A) is §1.
- (F) is Swoole's model: Swoole embeds its `swoole/library` PHP sources in the
  binary and `zend_eval_stringl`s them in RINIT, CLI only
  [source: [`ext-src/php_swoole.cc` L1459–L1492](https://github.com/swoole/swoole-src/blob/4ba434a7bf9cb3c632cc1f865779fc6fdace8545/ext-src/php_swoole.cc#L1459-L1492),
  [`ext-src/php_swoole_library.h` L55–L65, L14808](https://github.com/swoole/swoole-src/blob/4ba434a7bf9cb3c632cc1f865779fc6fdace8545/ext-src/php_swoole_library.h#L55-L65)].
  That code is compiled again on every request, and it would still not let the native class
  implement Core's interface. It does not fit FPM or the rule that Core is a Composer package [inference].

## 3. How comparable projects split extension and library

| Project | Who declares the interfaces | How the library reaches native objects | Source |
|---|---|---|---|
| **mongodb** (`ext-mongodb` + `mongodb/mongodb`) | The **extension** declares `MongoDB\Driver\CursorInterface` and its `final class Cursor implements CursorInterface`. | The library's PHP classes (`Collection`, operations) call the extension. On the hot path `find()` returns the **native Cursor unwrapped**. It wraps it in the userland `CodecCursor implements CursorInterface` only when a codec is set. The library hard-requires `ext-mongodb`. | [`CursorInterface.stub.php`](https://github.com/mongodb/mongo-php-driver/blob/5329f433482b501278c9f1e816d1920b2b3c8ab9/src/MongoDB/CursorInterface.stub.php), [`Cursor.stub.php`](https://github.com/mongodb/mongo-php-driver/blob/5329f433482b501278c9f1e816d1920b2b3c8ab9/src/MongoDB/Cursor.stub.php), [`Operation/Find.php` L261–L278](https://github.com/mongodb/mongo-php-library/blob/c6e9be76aa38bca4fff400569d9244b74173502f/src/Operation/Find.php#L261-L278), [`Model/CodecCursor.php` L37](https://github.com/mongodb/mongo-php-library/blob/c6e9be76aa38bca4fff400569d9244b74173502f/src/Model/CodecCursor.php#L37), [`composer.json`](https://github.com/mongodb/mongo-php-library/blob/c6e9be76aa38bca4fff400569d9244b74173502f/composer.json#L14) |
| **php-ds** (`ext-ds` + `php-ds/php-ds` polyfill) | **Both**, under the same names: the extension registers e.g. `Ds\Key` with `zend_register_internal_interface`, and the polyfill declares the same interface in PHP. | No layer: the polyfill is a full PHP implementation that `provide`s `ext-ds`. Its classes autoload only when the extension is absent, and its functions are guarded by `function_exists`. | [`php_key_ce.c`](https://github.com/php-ds/ext-ds/blob/6e9d66812b0acb9445c446b8dfb79904e0a9bc13/src/php/classes/php_key_ce.c#L16-L21), [`polyfill src/Key.php`](https://github.com/php-ds/polyfill/blob/890702aa2b2e74b027a51800b3c1f58148124d14/src/Key.php), [`composer.json`](https://github.com/php-ds/polyfill/blob/890702aa2b2e74b027a51800b3c1f58148124d14/composer.json#L19-L24), [`functions.php`](https://github.com/php-ds/polyfill/blob/890702aa2b2e74b027a51800b3c1f58148124d14/src/functions.php#L4) |
| **graphql-php-accelerator** (ext-php-rs, accelerates `webonyx/graphql-php`) | The **extension** re-declares in MINIT, under the library's own FQCNs, the library's AST marker interfaces, node classes and `Parser`, so the PHP originals are never autoloaded. | No layer: the native classes replace the userland ones. | [README "How it works"](https://github.com/er1z/graphql-php-accelerator/blob/6dc1a7899463b0915766a0a7aec73af569fb2ea9/README.md), [`src/classes/mod.rs` L54–L64](https://github.com/er1z/graphql-php-accelerator/blob/6dc1a7899463b0915766a0a7aec73af569fb2ea9/src/classes/mod.rs#L54-L64), [`src/classes/interfaces.rs`](https://github.com/er1z/graphql-php-accelerator/blob/6dc1a7899463b0915766a0a7aec73af569fb2ea9/src/classes/interfaces.rs) |
| **Swoole** (ext + `swoole/library`) | The extension, plus PHP library code compiled into the binary. | The library is `eval`ed in RINIT (CLI only). | see (F) above |

The pattern [inference from the table]: none of them has a native class implement a
userland interface. When the native object must satisfy an interface, the
**extension declares the interface** (mongodb, php-ds, graphql accelerator), and a
userland library either passes native objects through unwrapped on the hot path
(mongodb) or shadows / polyfills names (php-ds, accelerator). mongodb pays for a
userland wrapper only on the optional codec path.

## 4. What a PHP layer costs

### Method

[measured] PHP 8.4.28 NTS CLI, aarch64 (Apple M5, ddev container), 10k
pre-built 9-column row arrays held by an internal class (`SplFixedArray`, a
stand-in for a native Cursor), 2M rows per case, median of 5 rounds. The script
is reproduced in the [appendix](#appendix-benchmark-script). It measures only the
PHP-side dispatch cost. It does not convert rows: the arrays already exist.

| Case | no opcache | opcache, JIT off | opcache + tracing JIT |
|---|---:|---:|---:|
| A: native method called directly, one call per row | 20.0 ns/row | 17.5 | 12.0 |
| C: PHP subclass of the native class, called through a userland interface | 20.1 | 17.5 | 11.9 |
| B: PHP wrapper, one forward per row | 28.4 | 26.3 | 15.7 |
| B: PHP wrapper + a `Generator` yielding each row (a lazy Core Cursor) | 40.3 | 36.9 | 29.1 |
| A: native batch (all rows in one call), then `foreach` | 10.7 | 7.7 | 4.2 |
| B: PHP wrapper forwarding the batch call, then `foreach` | 10.4 | 7.5 | 4.2 |
| B: batch, then PHP touches every value of the row (re-key 9 columns) | 216.5 | 199.7 | 180.0 |

Reading the table [measured → inference]:

- A forward through a PHP wrapper adds **≈ 4–9 ns per call**. Subclassing (C) adds
  nothing.
- A `Generator` per row adds ≈ 17–20 ns on top of a direct call.
- Batching makes the wrapper free: one forward per batch.
- **Touching values in PHP is the real cost**: ≈ 20 ns per value.

### Against the spike's numbers

Spike (b), 10k rows × 9 columns, NTS: oci8 14.87 ms, `rajska_oracle` 8.60 ms
(1.73×) [source: [`summary-nts.md`](../../spikes/rust-oracledb/bench/output/summary-nts.md)].
2× needs 7.44 ms, a gap of ≈ **116 ns per row**. About 60% of the 8.60 ms is the
row → zval conversion in the extension [source: [FINDINGS.md, Performance](../../spikes/rust-oracledb/FINDINGS.md#performance)].

| PHP layer on the fetch path | Added per 10k rows | Share of the 1.16 ms gap to 2× |
|---|---:|---:|
| wrapper forward per row (≈ 8 ns) | ≈ 0.08 ms | ≈ 7% |
| wrapper + Generator per row (≈ 20 ns over direct) | ≈ 0.2 ms | ≈ 17% |
| wrapper forward per batch of 100+ rows | < 0.001 ms | ≈ 0% |
| PHP touching each value (≈ 200 ns/row) | ≈ 2 ms | ≈ 170%: falls back to about 1.4× |

For a Call (workload (a), 13.4 µs) a forward is ≈ 0.06% [inference from the numbers above].

The spike confirms that the boundary itself is not the bottleneck: one native
`fetch()` per row (8.60 ms) and one `fetchAll()` for all rows (8.66 ms) were equal
within noise [source: [`summary-nts.md` L9–L10](../../spikes/rust-oracledb/bench/output/summary-nts.md)].
The spike's own harness already called the native Statement from a PHP class
([`bench/drivers.php` L212–L229](../../spikes/rust-oracledb/bench/drivers.php)).

## 5. Returning an already-built array of rows in one call

Yes [source]:

- A `#[php_impl]` method may return `ZBox<ZendHashTable>`. Building a list of row
  arrays and returning it is one native call. The spike's `fetch_all()` does
  exactly this, and `fetch()` returns one row the same way
  ([`spikes/rust-oracledb/php-ext/src/bench.rs`](../../spikes/rust-oracledb/php-ext/src/bench.rs)).
- `Vec<T>` and `HashMap<K, V>` also convert with `IntoZval`, but they copy from
  Rust collections [source: [guide, types](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/guide/src/types/index.md)].
  Building the `ZendHashTable` directly avoids the intermediate copy [inference].

Where the row-building time goes, and a lever the spike did not use:

- `ZendHashTable::insert(&str, v)` calls `zend_hash_str_update`
  [source: [`src/types/array/mod.rs` L470–L510](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/types/array/mod.rs#L470-L510)],
  which hashes the key and, for each new key, allocates a fresh `zend_string`
  [source: [`Zend/zend_hash.c` L1126, L1216](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_hash.c#L1126)].
  With 9 columns × 10k rows, that is 90k key allocations and hashes per fetch.
- ext-php-rs 0.16.5 has `ArrayKey::ZendString(&ZendStr)`, which "allows
  bypassing repeated `zend_string_init` allocations and re-hashing"
  [source: [`src/types/array/array_key.rs` L8–L21](https://github.com/extphprs/ext-php-rs/blob/ext-php-rs-v0.16.5/src/types/array/array_key.rs#L8-L21)].
  It goes through `zend_hash_update`, which only addrefs a non-interned key and
  reuses its cached hash [source: [`Zend/zend_hash.c` `_zend_hash_add_or_update_i`](https://github.com/php/php-src/blob/8748e2265d12d1bbed8e153846adfbe823580831/Zend/zend_hash.c#L979-L1050)].
  Creating each column name once per Cursor and reusing it for every row removes
  that per-key cost [inference, not measured].
- A list-shaped row (numeric keys, `push`) avoids keys altogether [inference].
  The Driver contract could carry the column names once per Cursor, and Core
  could offer associative rows on top. But if Core re-keys in PHP, that costs
  the ~200 ns/row measured above, so the associative shape must be built natively.

## 6. The options side by side

| | Per call | Per row | Core never depends on the Extension | Pure-PHP Backend still possible | Coupling between Core and Extension releases |
|---|---|---|---|---|---|
| (A) native implements Core's userland interface | n/a | n/a | n/a | n/a | **impossible** (§1) |
| (B) Core adapter wraps native objects | +4–9 ns | +4–9 ns if row-at-a-time; ≈0 if batched | yes: the adapter loads only when `extension_loaded()` | yes | loose: the adapter absorbs differences and can check the Extension version |
| (C) Core subclasses native classes | 0 | 0 | yes, but Core names native classes, and the Extension must know Core's subclass names (factories) | yes | tight: signatures must match, and a mismatch is a fatal at class link |
| (D) Extension declares Core's interfaces | 0 | 0 | yes, but the interfaces that run are the Extension's copy | yes (Core's PHP copy) | tightest: the contract is duplicated in Rust and PHP, and drift breaks at runtime |
| (E) structural, no interface | 0 | 0 | yes | yes | none checked by PHP; only the conformance suite |

## 7. Recommendation for #20 (to weigh, not a decision)

1. **Use (B): Core owns the Driver contract as PHP interfaces, and a thin Core
   adapter forwards to the Extension's native classes.** The 4–9 ns forward is
   invisible on Calls and negligible on batched fetches, and it keeps release
   coupling loose. It also gives Core one place to apply its side of the C4–C14
   workarounds.
2. **Make the row path batch-shaped in the Driver contract**: a Backend Cursor
   hands over a list of already-built rows per call (sized by fetch array size),
   and Core's user-facing Cursor iterates that list. Core's PHP must never touch
   individual values on the fetch path: all value conversion (types, keys,
   dates) belongs to the Backend. This is what keeps the PHP layer's cost under
   1% of the 2× budget, and it costs a pure-PHP Backend nothing.
3. **Spend the optimisation effort below the seam**: reuse column-name
   `zend_string`s (`ArrayKey::ZendString`), avoid `String` temporaries, and avoid
   per-value `date_create_immutable`. That is where the 60% is.
4. **Keep (D) in reserve** (the mongodb / php-ds pattern) in case a later
   benchmark of the real adapter shows the forward matters, for example a
   row-at-a-time API. If (D) is ever adopted, the Extension must expose its
   contract version and Core must refuse a mismatch at detection time.
5. **Avoid (C)**: it saves only the forward but couples both packages' class
   names and factories. Its factory path (Rust instantiating a userland
   subclass) is unverified.

Conformance cases this implies for the spec (for the final ticket to compile):

- A Backend's Cursor delivers each batch as a list of rows whose values are already
  in their final PHP types (no Core post-processing per value).
- Core works with the Extension absent (pure-PHP Backend or none), without ever
  loading an adapter class that names a native class.
- Core refuses to start with an Extension whose reported contract version it does not
  support, with a clear error and not a fatal at class load.

## Appendix: benchmark script

Run with `ddev exec bash -s < run.sh`. The PHP part:

```php
<?php
declare(strict_types=1);

interface RowSource { public function offsetGet($index): mixed; }
interface Fetcher { public function fetch(int $i): mixed; public function fetchAll(): array; }

// (C) userland subclass of an internal class, implementing a userland interface
final class Sub extends SplFixedArray implements RowSource {}

// (B) composition wrapper implementing a userland interface
final class Wrap implements Fetcher {
    public function __construct(private readonly SplFixedArray $n) {}
    public function fetch(int $i): mixed { return $this->n->offsetGet($i); }
    public function fetchAll(): array { return $this->n->toArray(); }
}

$rows = 10000;
$row = ['ID' => 1, 'NAME' => 'x', 'AMOUNT' => '1.5', 'CREATED' => '2026-01-01 00:00:00', 'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
$native = new SplFixedArray($rows);
$sub = new Sub($rows);
for ($i = 0; $i < $rows; $i++) { $native[$i] = $row; $sub[$i] = $row; }
$wrap = new Wrap($native);

function viaInterface(RowSource $s, int $n): int { $c = 0; for ($i = 0; $i < $n; $i++) { $r = $s->offsetGet($i); $c += $r['ID']; } return $c; }
function viaFetcher(Fetcher $s, int $n): int { $c = 0; for ($i = 0; $i < $n; $i++) { $r = $s->fetch($i); $c += $r['ID']; } return $c; }
function direct(SplFixedArray $s, int $n): int { $c = 0; for ($i = 0; $i < $n; $i++) { $r = $s->offsetGet($i); $c += $r['ID']; } return $c; }
function batch(Fetcher $s): int { $c = 0; foreach ($s->fetchAll() as $r) { $c += $r['ID']; } return $c; }
function gen(Fetcher $s, int $n): \Generator { for ($i = 0; $i < $n; $i++) { yield $s->fetch($i); } }
function viaGenerator(Fetcher $s, int $n): int { $c = 0; foreach (gen($s, $n) as $r) { $c += $r['ID']; } return $c; }
function reshape(Fetcher $s): int { $c = 0; foreach ($s->fetchAll() as $r) { $o = []; foreach ($r as $k => $v) { $o[strtolower($k)] = $v; } $c += $o['id']; } return $c; }
function batchDirect(SplFixedArray $s): int { $c = 0; foreach ($s->toArray() as $r) { $c += $r['ID']; } return $c; }

$reps = 200; // 2M rows per case per round
$cases = [
    'A  native method, called directly'            => fn() => direct($native, $rows),
    'C  PHP subclass of native, via interface'     => fn() => viaInterface($sub, $rows),
    'B  PHP wrapper, one forward per row'          => fn() => viaFetcher($wrap, $rows),
    'B  PHP wrapper, one forward per 10k rows'     => fn() => batch($wrap),
    'A  native batch, called directly'             => fn() => batchDirect($native),
    'B  wrapper + Generator per row (lazy Cursor)' => fn() => viaGenerator($wrap, $rows),
    'B  batch + PHP touches each value (re-key)'   => fn() => reshape($wrap),
];
$res = [];
for ($round = 0; $round < 5; $round++) {
    foreach ($cases as $name => $f) {
        $t = hrtime(true);
        for ($r = 0; $r < $reps; $r++) { $f(); }
        $res[$name][] = (hrtime(true) - $t) / ($reps * $rows);
    }
}
foreach ($res as $name => $v) { sort($v); printf("%-45s %6.1f ns/row (median of 5 rounds)\n", $name, $v[2]); }
```

Run three ways: `php -n`; `php -n -d zend_extension=opcache -d opcache.enable_cli=1 -d opcache.jit=disable`;
and the same with `-d opcache.jit=tracing -d opcache.jit_buffer_size=64M`.
