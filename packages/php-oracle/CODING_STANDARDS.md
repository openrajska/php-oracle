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
