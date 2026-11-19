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
| `ext`      | `packages/php-oracle-ext`: the Rust extension (`rajska_oracle`)      |
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
