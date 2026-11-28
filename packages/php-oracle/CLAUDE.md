# PHP Oracle Database

`openrajska/php-oracle` (Core) is the typed PHP API for Oracle Database and the
Driver contract that backends implement. The `oracledb` Extension is its first
backend, reached through `ExtensionManager`.

## Philosophy

KISS (Keep it simple, stupid). Choose the simplest code that solves today's
problem: plain names, few layers, no abstraction until a second real use asks
for it.

## Coding standards

Before writing or reviewing code in this package, read
[CODING_STANDARDS.md](CODING_STANDARDS.md).
