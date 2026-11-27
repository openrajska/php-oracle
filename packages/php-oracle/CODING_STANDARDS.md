# Coding standards

## Small files

Keep every file at 100 lines or fewer. A file that grows past that is doing
more than one thing: split it.

## Object Calisthenics

1. **One level of indentation per method.** Deeper nesting is a method waiting
   to be extracted.
2. **Return early.** Handle the edge case first and leave; the main path reads
   without `else`.
3. **Wrap primitives.** A value with meaning (an id, a duration, a SQL text)
   becomes a small type, not a bare `string` or `int`.
4. **First-class collections.** A class that holds a list holds only that list,
   and owns the behaviour around it.
5. **One arrow per line.** `$a->b()->c()` reaches through objects; ask the
   nearest one for what you need.
6. **Full names.** `$connection`, never `$conn`. A name too long to write in
   full points at a class doing too much.
7. **Small entities.** Short methods, short classes, few methods per class.
8. **Two instance variables at most.** More is a sign of a missing object that
   groups them.
9. **Tell, don't ask.** Ask an object to do the work instead of pulling its
   state out with getters and deciding outside it.

## SOLID

Look at the code through each letter:

- **S, Single responsibility.** Does this class have one reason to change?
- **O, Open/closed.** Can a new case be added with new code, without editing
  what already works?
- **L, Liskov substitution.** Can every implementation stand in for its
  contract without the caller noticing?
- **I, Interface segregation.** Does each caller depend only on the methods it
  uses?
- **D, Dependency inversion.** Does the code depend on contracts (the Driver
  contract) rather than on concrete backends?

## Naming

Read the declaration aloud, and if it is not a sentence, rename:
`final readonly class Varchar implements SendsValue { use BindsText; }` reads
"a varchar sends a value and binds text".

### Classes

1. **A thing is a noun.** `Connection`, `Cursor`, `Lob`.
2. **A role is a suffix and a variant a prefix.** `ExtensionSession`,
   `ExtensionManager`.
3. **A builder that has not run yet is `Pending*`.** Its last call runs it and
   returns another type: `PendingFunction`.
4. **The namespace carries the context.** `Binds\In\Varchar`, not
   `Binds\InVarcharBind`.
5. **Acronyms are words.** `Json`, `Clob`, `Rowid`.

### Contracts

1. **A role is a noun with no suffix.** `Session`, `Cursor`, `Locator`; never
   `SessionInterface`.
2. **A contract that grants a capability is an `-able` adjective.**
3. **A contract that grants a behaviour is a verb phrase in the third
   person.**

### Traits

1. **A verb in the third person and its object.** `BindsText`, `BindsLob`,
   `BindsTemporal`.
2. **They live in the `Concerns/` folder of the component they serve.** Never
   add a `Trait` suffix.

### Methods

| Kind | Shape | Example |
|---|---|---|
| Action | domain verb | `select()`, `run()`, `procedure()`, `transaction()` |
| Getter | noun, no `get` | `sql()`, `columns()`, `affected()` |
| Yes/no question | `is*`, `has*`, `can*` | `isOpen()` |
| Opposite | its own method | `exists()` / `missing()` |
| Named constructor | `from*` for a source format, otherwise a domain word or a preposition | `fromDriver()`, `in()`, `of()` |
| Conversion | `to*` | `toArray()` |
| Option | `with*` / `without*`, returns a copy | `withAutocommit()` |
| Live state | `enable*` / `disable*`, acts now | `enableOutput()` |
| Variant | suffix on the base verb | `runMany()` |

Names PHP imposes (`getIterator()`, `stream_*`) are exempt.

### Exceptions

1. **A base is named after its area; a final exception after its cause.**
   `QueryException` groups `UniqueViolationException`;
   `ConnectionException` groups `ConnectionLostException` and
   `PoolExhaustedException`.
2. **The message is a sentence that ends with a period.**

### Events

1. **A subject and a verb: `-ing` before the action, `-ed` after, `Failed` on
   error.** `TransactionBeginning`, `TransactionCommitted`.

### Enums

1. **A singular noun, with cases in PascalCase.** `Type::Varchar`,
   `RowShape::Assoc`.

### Folders

1. **A folder of many classes of one kind is plural.** `Binds/`, `Contracts/`,
   `Exceptions/`, `Concerns/`.
2. **A component folder is singular.** `Statement/`, `Lob/`, `Driver/`.

## Tests

Before committing, check every test against these rules. They are not
suggestions: a test that breaks one is rewritten.

### Shape of a test

    describe('<the subject, in plain words>', function (): void {
        it('<what it does, as a sentence>', function (): void {
            <arrange: the state the behavior needs>

            <act: one call>

            <assert: what changed>
        });
    });

- One behavior per test. Two acts are two tests.
- The three blocks are separated by a blank line, with no comments naming
  them.

### Names

- A file groups its tests in `describe()` blocks, one per subject. The subject
  is how a person would say it, in lower case and without code: `orders`,
  `refunds`, `closing the connection`; never `Order::place()` or
  `place()`.
- A test is an `it()`, and its name completes the sentence "it …": what
  happens, not which method runs. `it('refuses an order without stock')`,
  never `it('test place order')`.
- Pest prints both together: `orders → it refuses an order without stock`.
  Read that line aloud; if it is not a sentence, rename.
- A hook only one subject needs goes inside that subject's `describe()`.

### What to test

- Only the library's behavior: what a user of its public API, or of the
  contract it implements, can observe.
- Never test the database server, a dependency, the test framework, a test
  helper, the packaging, or how a class is declared (`final`, enum values, a
  parent class).
- An error is tested with the behavior that raises it, never in a file of its
  own and never through the exception's getters alone.
- Never generate tests (parsing docs, scanning source files). Write each case.

### Cases

- When only the input varies, use one test with a dataset, not copies of the
  test.
- A dataset yields closures that build objects, so they are built inside the
  test:

      yield 'a valid email' => [fn () => new Email('a@b.c')];

- A dataset lives in the one file that uses it. Never copy it.

### Exceptions

- Never `try`/`catch` in a test. Use `toThrow()`:

      expect(fn () => $order->place())->toThrow(OutOfStock::class, 'No stock');

- To check more than the class and the message, pass a typed closure:

      expect($call)->toThrow(function (OutOfStock $e): void { expect($e->sku())->toBe('A1'); });

### Data and connections

- A test file creates every table and object it reads, in `beforeEach`. There
  are no fixtures and no shared state between files.
- The database is emptied before every test (`RefreshDatabase`).
- A test opens its own connections and they close after it. Anything that
  outlives a test (a pool, a cache) is used only by the test of that thing,
  and asked for explicitly.

### Structure

- `tests/TestCase.php` is the root. Each module has `tests/<Module>/TestCase.php`
  extending it.
- Every file declares its TestCase with `uses(TestCase::class)`.
- Helpers are public methods of traits the TestCase uses. Their procedural
  shortcuts live in `tests/Pest.php` and call `test()->method()`.
- No `Support/` folder and no helper classes.

### Coverage

- Coverage is 100%, reached only through behavior tests.
- An uncovered line is one of three things:
  1. a missing behavior test: write it;
  2. dead code: delete it;
  3. a dependency the test cannot control: pass it in (constructor or
     parameter) and give the test its value.
- Replace only a contract with a stub (`createStub(Contract::class)`). Never
  mock a global function or a static call, and never mark a line ignored.

### Checklist

- [ ] Every test is an `it()` inside a `describe()`, and reads as a sentence.
- [ ] Every test checks one behavior of the library.
- [ ] Arrange, act and assert are separated by blank lines.
- [ ] No `try`/`catch`, no generated tests, no copied datasets.
- [ ] Every object the file reads is created in the file.
- [ ] No connection or pool outlives its test.
- [ ] Coverage is 100% without ignored lines.
