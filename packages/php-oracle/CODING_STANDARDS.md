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
