# Contributing

Thanks for your interest in the OpenRajska Oracle ecosystem for PHP. This
document describes how to commit to this repository. Every contributor follows
it, human or AI.

## Commit messages

This repository follows [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/).
Commit messages are written in **English**.

```
<type>(<scope>)!: <description>

[optional body]

[optional footer(s)]
```

### Header

The header is the first line. It takes the form `<type>(<scope>)!: <description>`.

- **type** is required and comes from the list [below](#types).
- **scope** is optional and comes from the list [below](#scopes). Omit it when
  the change spans several areas.
- **`!`** is optional. Use it only for breaking changes (see
  [Breaking changes](#breaking-changes)).
- **description** is required:
  - imperative mood ("add", not "added" or "adds");
  - starts in lowercase;
  - no trailing period;
  - the whole header is at most 72 characters.

### Types

| Type       | Use it for                                                        | SemVer |
|------------|-------------------------------------------------------------------|--------|
| `feat`     | A new user-facing capability                                      | minor  |
| `fix`      | A bug fix                                                         | patch  |
| `perf`     | A change that improves performance without changing behavior      | patch  |
| `refactor` | A code change that neither fixes a bug nor adds a feature         | —      |
| `docs`     | Documentation only (README, docs/, doc comments)                  | —      |
| `test`     | Adding or fixing tests, conformance suites or benchmarks          | —      |
| `build`    | Build system, packaging, dependencies, Dockerfiles, PIE metadata  | —      |
| `ci`       | CI configuration and scripts                                      | —      |
| `style`    | Formatting only (whitespace, rustfmt, php-cs-fixer); no logic     | —      |
| `chore`    | Maintenance that fits none of the above                           | —      |
| `revert`   | Reverting a previous commit                                       | —      |

### Scopes

Scopes follow the monorepo layout. When a new package or top-level area appears,
add its scope here first.

| Scope      | Area                                                                 |
|------------|----------------------------------------------------------------------|
| `core`     | `packages/php-oracle`: the `openrajska/php-oracle` contract and API  |
| `ext`      | `packages/php-oracle-ext`: the Rust extension (`oracledb`)           |
| `laravel`  | `packages/laravel-oracle`: the Laravel adapter                       |
| `spike`    | `spikes/`: throwaway experiments                                     |
| `fixtures` | `fixtures/`: SQL and PL/SQL test schemas                             |
| `ddev`     | `.ddev/`: the local development environment                          |
| `docs`     | `docs/`: research, ADRs and guides                                   |
| `ci`       | `.github/`: workflows and automation                                 |

`ci` is both a type and a scope. Use the `ci` type for CI changes, and use the
`ci` scope only with another type, for example `fix(ci): …` when a workflow
breaks.

### Body

- Separate it from the header with one blank line.
- Explain **what** changed and **why**, not how (the diff shows how).
- Wrap lines at 72 characters.

### Footers

Footers follow the [git trailer](https://git-scm.com/docs/git-interpret-trailers)
format, `Token: value`. Common footers:

- `Refs: #123` or `Closes: #123`
- `Co-Authored-By: Name <email>`
- `BREAKING CHANGE: <what breaks and how to migrate>`

### Breaking changes

Mark a breaking change with `!` in the header, a `BREAKING CHANGE:` footer, or
both. Both is preferred, because the footer is where the migration instructions
go.

```
feat(core)!: return lazy cursors from Driver::query

BREAKING CHANGE: Driver::query() now returns a Cursor instead of an array.
Call ->toArray() on the result to keep the previous behavior.
```

### Reverts

```
revert: feat(core): add implicit results support

This reverts commit 1a2b3c4.
```

### Merge commits

A merge commit that integrates one branch into another (a ticket branch
into its integration branch, or the integration branch back into a ticket
branch to bring it up to date) keeps the message git writes by default,
`Merge branch '…' into …` or `Merge remote-tracking branch '…' into …`.
It is exempt from the type and the 72-character rules above. Never rewrite
history to change one.

### Examples

```
feat(core): add named-notation call builder for procedures
fix(ext): release statement handle when fetch is interrupted
perf(ext): convert NUMBER columns to int without string round-trip
test(fixtures): add pipelined table function with BOOLEAN column
build(ddev): pin Rust toolchain to 1.100.0
docs: record ADR for choosing the Rust thin backend
chore(spike): drop smoke tests superseded by the probe
```

### Rules of thumb

- Each commit holds one logical change. If the description needs "and", consider
  splitting the commit.
- Each commit should leave the repository in a working state.
- Don't mix formatting changes with logic changes. Use a separate `style` commit.

## Development environment

Everything runs inside [ddev](https://ddev.com). Nothing besides ddev and Docker
is required on the host.

```bash
ddev start                     # PHP 8.4 web container + Oracle 26ai Free
ddev cargo build               # cargo inside the web container (from any directory)
ddev exec -s oracle sqlplus rajska/rajska@localhost/FREEPDB1
```

The Oracle database is ephemeral. `ddev restart` gives you a clean database.

### Running the suites

Install the fixtures and the suites' grants first (`ddev fixtures`, again
after every restart). Then, from the repository root:

```bash
# The Extension's suite
(cd packages/php-oracle-ext && ddev cargo test)

# Core's suite, with the Extension built and loaded
ddev exec -d /var/www/html/packages/php-oracle-ext cargo build \
  && ddev exec -d /var/www/html/packages/php-oracle composer install --ignore-platform-req=ext-oracledb \
  && ddev exec -d /var/www/html/packages/php-oracle php \
       -d extension=/var/cache/cargo-target/debug/libphp_oracledb.so vendor/bin/phpunit
```

Core's suite has three parts (`--testsuite <name>` runs one): `harness` (the
test helpers), `conformance` (the conformance catalog of spec #32, through the
Driver contract: `new ExtensionDriver()->connect($config)`) and `api` (Core's
public API). The Extension's stub, `packages/php-oracle-ext/oracledb.stub.php`,
has a copy in `packages/php-oracle/stubs/` for PHPStan; change both together.

Core's suite fails on any PHP warning, notice or deprecation and runs with
`zend.exception_ignore_args=Off` (`phpunit.xml.dist`). Both suites reach Oracle
through `ORACLE_CONNECT_STRING`, `ORACLE_USER` and `ORACLE_PASSWORD`, which ddev
sets. One Core test waits 61 s for the Pool's idle ping; `--exclude-group slow`
skips it. CI (`.github/workflows/ci.yml`) runs both suites on PHP 8.4 NTS and
ZTS.

The database image allows 200 processes, about 90 of them its own. CI raises
the limit to 400 before the fixtures (`fixtures/sql/processes.sql`), because
Core's suite holds about 60 Sessions by its end (one for every Pool it
created) and a burst of logons on top made the listener refuse new ones with
ORA-12516. `ddev fixtures` does not do this: the new limit needs an instance
restart, which ends every Session on the shared database. If you hit
ORA-12516 locally (several suites at once), raise it yourself while nothing
else uses the database; it lasts until the next `ddev restart`:

```bash
ddev exec -s oracle sqlplus -s -l / as sysdba @/fixtures/sql/processes.sql 400
```

Core `require`s `ext-oracledb`, but Composer runs without the Extension
loaded, hence `--ignore-platform-req=ext-oracledb`. The constraint
(`^0.1`) declares the oldest Extension Core works with; the adapter
(`ExtensionDriver::MINIMUM`) refuses anything outside `>= minimum, <` the next
breaking version on the first `connect()`. Raise both together, and only after
releasing that Extension: its release is tagged `php-oracle-ext-v<version>`, and
CI then runs Core's suite against the Extension built from that tag too
(`.github/scripts/extension-matrix.sh`). Until the minimum has a tag, it is the
checkout's own Extension and the regular legs cover it; running the workflow by
hand with `minimum-ref` builds that leg from any ref.

### The `oracledb` fork

The Rust thin Backend builds on [openrajska/rust-oracledb](https://github.com/openrajska/rust-oracledb),
our fork of Oracle's `oracledb` crate, pinned to the 26.0.0-beta.6 release
([ADR 0002](docs/adr/0002-rust-thin-backend-is-the-primary-backend.md),
condition C17). Its [RAJSKA.md](https://github.com/openrajska/rust-oracledb/blob/rajska/integration/RAJSKA.md)
is the workflow every fork patch follows and the procedure for upgrading the
pin.

The Extension depends on an exact commit of the fork's `rajska/integration`
branch, never on a branch name. Moving `rev` is a commit of its own:

```toml
oracledb = { git = "https://github.com/openrajska/rust-oracledb", rev = "<full sha>", version = "=26.0.0-beta.6" }
```

To work on a patch, clone the fork next to this repository. ddev mounts it,
and `ddev fork` runs `cargo` in it against the Oracle service:

```bash
git clone git@github.com:openrajska/rust-oracledb.git ../rust-oracledb
ddev restart
ddev fork run --locked --bin create_test_schema   # recreates the rsotest user
ddev fork test --locked --all-features            # the crate's own suite
```

## License

The whole repository is dual-licensed under either of

- the MIT license ([LICENSE-MIT](LICENSE-MIT)), or
- the Apache License, Version 2.0 ([LICENSE-APACHE](LICENSE-APACHE)),

at your option.

Unless you explicitly state otherwise, any contribution intentionally submitted
for inclusion in the work by you, as defined in the Apache-2.0 license, shall be
dual licensed as above, without any additional terms or conditions.

Every package manifest declares both licenses:

- `composer.json`: `"license": ["MIT", "Apache-2.0"]` (a list means "or")
- `Cargo.toml`: `license = "MIT OR Apache-2.0"`
