# php-oracle

A modern Oracle Database ecosystem for PHP: a typed API with full PL/SQL fidelity, a native Rust thin extension, and a Laravel adapter.

> [!WARNING]
> **Pre-alpha. Nothing here is installable yet.** The project is still choosing its foundations: there is no stable API, no released package, and no compatibility promise. Do not use it in production.

## Why

Talking to Oracle from PHP today means `oci8` or `PDO_OCI`. Both need Oracle Instant Client installed and the extension compiled against it. On top of that, PDO cannot represent the ways PL/SQL returns data: several REF CURSORs per Call, typed OUT and IN OUT parameters, Implicit results, Collections and object types.

This project aims for:

- **A typed API instead of a PDO driver**, so that PL/SQL works without losing data or types ([ADR 0001](docs/adr/0001-typed-api-instead-of-pdo.md)).
- **No Instant Client and nothing to compile.** The native extension is meant to ship as pre-built binaries installed with [PIE](https://github.com/php/pie), on top of a thin (pure network protocol) driver.
- **Interchangeable Backends** behind one Driver contract, so the same code runs on the native extension or on a pure-PHP fallback.
- **A Laravel adapter** that keeps `DB::listen`, Telescope and Pulse working.

The first target is PHP 8.4.

## Packages

All planned; none is published yet.

| Package | What it is |
|---|---|
| `openrajska/php-oracle` | Core: the typed API users call and the Driver contract Backends implement |
| `openrajska/php-oracle-ext` | The `rajska_oracle` PHP extension, hosting the Rust thin Backend |
| `openrajska/laravel-oracle` | Integrates Core into Laravel's database layer |

The shared vocabulary (Backend, Call, Implicit result, Collection…) is defined in the [glossaries](GLOSSARY-MAP.md).

## Current work

The open question is whether a Rust thin Backend built on Oracle's [`oracledb`](https://github.com/oracle/rust-oracledb) crate can be the **Primary Backend**. A spike in [`spikes/rust-oracledb/`](spikes/rust-oracledb/) probes it item by item: binds, REF CURSORs, object types, LOB streaming, connection pooling, DRCP, PIE builds and performance, with `oci8` as the baseline for comparison. The plan and the decisions made so far live in the [wayfinder map](https://github.com/openrajska/php-oracle/issues/1). The answer will be recorded as ADR 0002.

## Repository layout

```
packages/        the Composer/PIE packages (glossaries only, for now)
spikes/          throwaway experiments; never shipped
fixtures/sql/    PL/SQL fixture schema, the seed of the conformance suite
docs/adr/        architecture decision records
docs/research/   background research (in Portuguese)
```

## Development

Everything runs in [ddev](https://ddev.com): PHP 8.4, Rust and an ephemeral Oracle Database 26ai Free. Nothing besides ddev and Docker is needed on the host.

```bash
ddev start
ddev fixtures        # install the PL/SQL fixture schema (again after every restart)
ddev cargo build     # cargo inside the web container
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the environment and the commit conventions.

## License

Licensed under either of

- MIT license ([LICENSE-MIT](LICENSE-MIT))
- Apache License, Version 2.0 ([LICENSE-APACHE](LICENSE-APACHE))

at your option.

Unless you explicitly state otherwise, any contribution intentionally submitted for inclusion in the work by you, as defined in the Apache-2.0 license, shall be dual licensed as above, without any additional terms or conditions.

Oracle and Java are registered trademarks of Oracle and/or its affiliates. This project is independent and is not affiliated with, endorsed by or sponsored by Oracle.
