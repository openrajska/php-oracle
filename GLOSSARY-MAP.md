# Glossary Map

The OpenRajska ecosystem for talking to Oracle Database from PHP. Each package
is its own context with its own language. Core owns the shared domain language
(Oracle concepts and the API around them), and the other contexts build on it.

## Contexts

- [Core](./packages/php-oracle/GLOSSARY.md) (`openrajska/php-oracle`): the
  typed API that users call and the Driver contract that Backends implement.
- **Extension** (`openrajska/php-oracle-ext`): the native `rajska_oracle` PHP
  extension that hosts the Rust thin Backend. No glossary yet.
- **Laravel adapter** (`openrajska/laravel-oracle`): integrates Core into
  Laravel's database layer. No glossary yet.

## Relationships

- **Extension → Core**: the Extension provides a Backend that implements Core's
  Driver contract. Core never depends on the Extension's code: it declares the
  Extension as a platform requirement and checks, when it first connects, that
  a compatible version is loaded.
- **Laravel adapter → Core**: the Laravel adapter wraps a Core Connection behind
  Laravel's database abstractions. It uses Core's terms unchanged and adds
  Laravel-specific ones (e.g. "Laravel Collection" is never just "Collection").
