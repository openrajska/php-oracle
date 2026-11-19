# rust-oracledb: maturity, supported databases and network features

Research for [#4](https://github.com/openrajska/php-oracle/issues/4), a child
of the wayfinder map [#1](https://github.com/openrajska/php-oracle/issues/1).
It asks whether the `oracledb` crate
([oracle/rust-oracledb](https://github.com/oracle/rust-oracledb), latest
release 26.0.0-beta.6) can serve as the basis of the **Primary Backend**, a
**Thin** Backend that speaks Oracle Net without Oracle Client libraries.

Checked on 2026-11-19 against `main` at commit `b1adfff` (2026-11-14), the
crates.io API, docs.rs and the GitHub issues and discussions. Where the Rust
crate says nothing, the python-oracledb and node-oracledb Thin-mode docs are
quoted, and the text says **(inferred from python/node)** when a fact comes
from them and not from rust-oracledb.

## Summary

- **License**: `UPL-1.0 OR Apache-2.0`. Both are permissive, so an
  open-source PHP extension can link and redistribute it.
- **Maturity**: it is an early beta. The repository's first commit is from
  2026-08-04. Six betas have shipped in 14 weeks. The docs call it a
  "pre-release" whose "APIs and functionalities are subject to change", and the
  API did break between betas. There is no published date for a non-beta
  release.
- **Maintenance**: one Oracle engineer, Anthony Tuininga, wrote every commit.
  He also maintains python-oracledb. Issues get a fast reply: the median first
  maintainer reply takes about 9 hours.
- **MSRV**: Rust 1.89, edition 2024. No MSRV policy is stated. It builds for
  64-bit targets only.
- **Minimum server**: the code accepts Oracle Database 12.1 and later. The
  guide says 19 or later is required, and only 19c, 21c and 26ai are tested.
- **Network**: one-way TLS and mTLS work, with PEM wallets only. Not
  supported: Native Network Encryption (Oracle refuses to implement it),
  `cwallet.sso` or SEPS wallets, Kerberos, RADIUS, LDAP, external auth and
  token auth (token auth is planned). Proxy auth works from beta.6. DRCP works.
  Application Continuity, Transaction Guard, TAF and FAN do not.
- **Gaps that matter to this project**: Implicit results are not supported.
  Object and Collection values can be fetched but not bound. There is no async
  API and no way to cancel a running call.

## 1. License and compatibility with an open-source PHP extension

| Fact | Source |
|---|---|
| `license = "UPL-1.0 OR Apache-2.0"` | [`Cargo.toml`](https://github.com/oracle/rust-oracledb/blob/main/Cargo.toml) |
| "dual-licensed … under the Universal Permissive License (UPL) 1.0 … and Apache License 2.0 … You may choose either license." | [`LICENSE.txt`](https://github.com/oracle/rust-oracledb/blob/main/LICENSE.txt) |
| `NOTICE.txt` contains one line: "Copyright (c) 2026, Oracle and/or its affiliates." | [`NOTICE.txt`](https://github.com/oracle/rust-oracledb/blob/main/NOTICE.txt) |
| Contributions require the Oracle Contributor Agreement (OCA) and a `Signed-off-by` line. Oracle develops in an internal repository, so PRs are re-applied by the maintainer rather than merged. | [`CONTRIBUTING.md`](https://github.com/oracle/rust-oracledb/blob/main/CONTRIBUTING.md), [issue #39 comment, 2026-10-08](https://github.com/oracle/rust-oracledb/issues/39) |

Assessment (our reading, not legal advice):

- Both licenses are permissive, and PHP extensions are not bound by the PHP
  License. An extension under MIT, Apache-2.0, BSD or PHP-3.01 can therefore
  statically link the crate. If Apache-2.0 is chosen, binary distributions
  must ship the license text and the `NOTICE` content. UPL-1.0 only needs its
  copyright and license notice.
- The repository has no `LICENSE` file yet. Choosing one is a separate
  decision.
- Transitive dependencies carry their own licenses. Examples are `rustls`, the
  `aws-lc-rs` crypto provider that rustls uses by default, and
  `webpki-roots`. A tool such as `cargo-about` or `cargo-deny` should produce
  the third-party notice file for each PIE or prebuilt binary. This is
  inferred from the [`Cargo.toml`](https://github.com/oracle/rust-oracledb/blob/main/Cargo.toml)
  dependency list. These licenses were not audited here.
- Getting a fix upstream is slow. Every contributor needs an OCA, and the
  maintainer re-applies patches by hand. Plan for a fork or a patch queue if
  we need fixes faster.

## 2. Maintainers and activity

| Metric | Value | Source |
|---|---|---|
| GitHub repo created | 2026-07-20 | `gh api repos/oracle/rust-oracledb` |
| First commit | 2026-08-04, "Initial commit" | `git log` |
| Commit authors on `main` | **Anthony Tuininga only** (153 commits) | `git log --format=%an` |
| Commits per month | Aug 32, Sep 56, Oct 37, Nov 28 (to Nov 14) | `git log` |
| crates.io owners | `anthony-tuininga`, `sharadraju` | crates.io API `/crates/oracledb/owners` |
| Other Oracle staff in threads | `cjbj` (Christopher Jones), `sharadraju` | issues [#12](https://github.com/oracle/rust-oracledb/issues/12), [#86](https://github.com/oracle/rust-oracledb/issues/86), discussion [#26](https://github.com/oracle/rust-oracledb/discussions/26) |
| Stars, forks | 45, 15 | GitHub API |
| crates.io downloads | 40,447 total. Most are betas 4 and 5: 15,156 and 12,047 | crates.io API |

Releases, all marked pre-release on GitHub:

| Version | Date |
|---|---|
| 26.0.0-beta.1 | 2026-08-06 |
| 26.0.0-beta.2 | 2026-08-20 |
| 26.0.0-beta.3 | 2026-09-08 |
| 26.0.0-beta.4 | 2026-09-23 |
| 26.0.0-beta.5 | 2026-10-28 |
| 26.0.0-beta.6 | 2026-11-12 |
| 26.0.0-beta.7 | in progress on `main` (`Cargo.toml`) |

Source: [GitHub releases](https://github.com/oracle/rust-oracledb/releases) and
[`doc/release_notes.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/release_notes.md).

Issue responsiveness was computed from `gh issue list` on 2026-11-19. It covers
77 issues, 51 of them closed, and ignores issues opened by maintainers. The
maintainers counted are anthony-tuininga, cjbj and sharadraju.

- First maintainer reply: **median 0.37 days**, 90th percentile 6.1 days,
  slowest 31 days.
- Time to close: median 4.3 days, slowest 92 days (issue #6, about 10g and 11g
  password verifiers).
- Six issues had no maintainer reply: #7, #36, #54, #58, #62 and #72. #7 and
  #36 are closed anyway.
- The maintainer's time is shared with python-oracledb. On #55
  (2026-10-23) he wrote: "At the moment I am focused on the upcoming
  python-oracledb release so it will be a bit yet before I get to this." He
  also says he cannot test some versions himself (#55, 2026-10-28): "I don't
  have a 12c database myself".

Name history. The crates.io name `oracledb` belonged to a different,
unaffiliated driver from 2026-06-14 to 2026-07-23 (versions 0.1.0 to 0.9.1,
MIT OR Apache-2.0, all yanked now). That driver was renamed
[`oraclemcp-driver-cx`](https://crates.io/crates/oraclemcp-driver-cx) (README,
"History"). Older docs.rs pages and blog posts about `oracledb 0.x` describe
that other crate.

## 3. Roadmap to non-beta and API stability

- No public roadmap, milestone or target date for a stable release exists. The
  repo has no milestones. The README and docs.rs say nothing about stability.
- The guide's introduction
  ([`doc/introduction.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/introduction.md))
  says: "This is a pre-release of rust-oracledb, intended to provide early
  access to the driver and gather user feedback. The APIs and functionalities
  are subject to change as development continues."
- The only mention of GA found is on issue
  [#89](https://github.com/oracle/rust-oracledb/issues/89) (2026-11-12): "This
  will be on the list of things to address prior to the first production
  release."
- The API broke between betas (release notes):
  - beta.3 removed `Row::get_cursor()`, added `Row::take()`, renamed
    `ErrorKind::PoolMaxLessThanMin` to `PoolMaxInvalid` and stopped `Cursor`
    and `Lob` from being cloneable;
  - beta.4 turned every `DB_TYPE_*` constant into a reference and changed
    `ErrorKind::DbError` from `String` to `DbError`;
  - beta.6 changed `Error`'s `Display` output and limited builds to 64-bit;
  - beta.7 changes `rows_affected()` for PL/SQL and the parameter types of
    `Statement` methods.
- The version scheme follows Oracle's other drivers: 26 is the Oracle AI
  Database 26ai year. The first stable is presumably `26.0.0` **(inferred)**.
- Features the maintainers have said are planned:
  - an async API (discussion
    [#26](https://github.com/oracle/rust-oracledb/discussions/26), 2026-09-21:
    "we have plans to add async support in a future release");
  - token authentication
    ([#3](https://github.com/oracle/rust-oracledb/issues/3));
  - a break/cancel API, which is to come with async
    ([#24](https://github.com/oracle/rust-oracledb/issues/24));
  - CQN (discussion
    [#75](https://github.com/oracle/rust-oracledb/discussions/75));
  - choosing the database type of IN binds (discussion
    [#64](https://github.com/oracle/rust-oracledb/discussions/64));
  - NNE through ezTLS
    ([#86](https://github.com/oracle/rust-oracledb/issues/86)).

## 4. MSRV

- `rust-version = "1.89"`, `edition = "2024"`
  ([`Cargo.toml`](https://github.com/oracle/rust-oracledb/blob/main/Cargo.toml)).
  crates.io records 1.89 for every 26.0.0 beta.
- README: "Support for Rust 1.89 and higher". The guide: "currently tested with
  Rust 1.89".
- **No MSRV policy is stated**, for example how often it will be raised. 1.89
  has not changed across six betas.
- From beta.6, the crate refuses to build on non-64-bit targets:
  `#[cfg(not(target_pointer_width = "64"))]` in `src/lib.rs`. This does not
  matter for the PHP 8.4 targets we plan (x86_64 and aarch64).

## 5. Minimum Oracle Database server version

The sources disagree:

| Source | Claim |
|---|---|
| README "Features" | "Connects to Oracle Database 12, 18, 19, 21 and 26 on-premises or in the Cloud" |
| [`doc/introduction.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/introduction.md) §1.2.1 | requires "An Oracle Database version 19 or later". Tested "against Oracle Database versions 26ai, 21c, and 19c" |
| [`doc/appendix_a.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/appendix_a.md) | "Rust-oracledb supports Oracle Database versions 26ai, 21c, and 19c" |
| Code, `src/messages/connect.rs` | rejects with `ServerVersionNotSupported` if the negotiated protocol version is `< PROTOCOL_VERSION_12` (315), so **12.1 is the floor in code** |
| Release notes, beta.5 | "Fixed bug connecting to Oracle Database 12c" ([#55](https://github.com/oracle/rust-oracledb/issues/55), reported against 12.1.0.1, fixed without a maintainer 12c test database) |
| Release notes, beta.6 | added the 11g password verifier ([#63](https://github.com/oracle/rust-oracledb/issues/63), [#6](https://github.com/oracle/rust-oracledb/issues/6)). The 10g verifier "will never be supported" ([#87](https://github.com/oracle/rust-oracledb/issues/87)) |
| python-oracledb Thin ([appendix A](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_a.html)) | "Release 12.1 and later". Thick mode: 11.2 and later, depending on the client |
| node-oracledb Thin ([appendix A](https://node-oracledb.readthedocs.io/en/latest/user_guide/appendix_a.html)) | "Connects directly to database version 12.1 and later" |

Conclusion: in practice, **12.1+ connects and 19c+ is supported and tested**.
That is the same floor as python and node Thin, but Oracle only tests 19c and
later for Rust. Accounts that still have only a 10g verifier cannot log in and
must be reset by a DBA. Oracle's other Thin drivers have the same limit.

Related performance issue: before 26ai, fetch time grows quadratically with
large `fetch_array_size` ([#89](https://github.com/oracle/rust-oracledb/issues/89),
open, planned "prior to the first production release").

## 6. Network, security and high-availability features

The main source is the feature table in
[`doc/appendix_a.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/appendix_a.md).
That file has not changed since the initial commit on 2026-08-04, and it is
already partly stale. For example, it lists proxy connections as "Yes", but
proxy auth only worked from beta.6. Each row below was cross-checked against
the release notes, the source or the issues.

| Feature | rust-oracledb | Evidence | python-oracledb Thin, for comparison |
|---|---|---|---|
| One-way TLS (TCPS) | **Yes** | appendix A; [`doc/connection_handling.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/connection_handling.md) §2.8, §2.10.1. TLS uses `rustls` with the bundled Mozilla `webpki-roots`, not the OS trust store ([#62](https://github.com/oracle/rust-oracledb/issues/62), open) | Yes (uses the system OpenSSL store) |
| Mutual TLS | **Yes, PEM only** | §2.10.2: needs `ewallet.pem` through `Config::set_wallet_location()`. Convert `ewallet.p12` with `openssl pkcs12` (§2.10.2.5) | Yes (PEM) |
| `cwallet.sso` and auto-login wallets | **No, and never** | [#25](https://github.com/oracle/rust-oracledb/issues/25), 2026-09-20: "management has declared that `cwallet.sso` may not be used by open source drivers, so the use of `ewallet.pem` is mandatory" | No (inferred from python/node: node docs say Thin needs `ewallet.pem`) |
| Private CA, server DN check | Partial | A private CA is trusted by putting its certificates in a key-less `ewallet.pem` ([#25](https://github.com/oracle/rust-oracledb/issues/25), [#39](https://github.com/oracle/rust-oracledb/issues/39), [#54](https://github.com/oracle/rust-oracledb/issues/54) open). `SSL_SERVER_DN_MATCH` and `SSL_SERVER_CERT_DN` are parsed in `src/config/connect_options.rs` but on `main` they are only written back into descriptor text, not checked in `src/transport.rs`. rustls still checks the host name | — |
| SEPS wallet | **No** | appendix A | No |
| Native Network Encryption and checksumming | **No, and Oracle will not add it** | appendix A: "No - use TLS instead". On `main`, a listener that requires NNE now gets an error instead of a panic (`src/messages/connect.rs`, [#40](https://github.com/oracle/rust-oracledb/issues/40)). [#86](https://github.com/oracle/rust-oracledb/issues/86), 2026-11-11: "management … do not want this implemented, even as an option. Support for NNE via ezTLS (available in Oracle Database 23.26.4) will be forthcoming" | No: "Use ezTLS or TLS instead" |
| Kerberos and RADIUS | **No** | appendix A | No |
| LDAP connections | **No** | appendix A | Yes, through a user hook (`register_protocol`) |
| External auth (OS) | **No** | appendix A | No |
| Token auth (OCI IAM, OAuth 2.0) | **No, planned** | appendix A; [#3](https://github.com/oracle/rust-oracledb/issues/3) (2026-08-11: "on the list of items to implement but it will take some time") | Yes |
| Proxy auth | **Yes** (from beta.6) | release notes beta.6 ([#56](https://github.com/oracle/rust-oracledb/issues/56)). `PoolConfig::set_proxy_user()` arrives in beta.7 ([#92](https://github.com/oracle/rust-oracledb/issues/92)) | Yes |
| SOCKS proxy | No | appendix A | — |
| Privileged connections (SYSDBA etc.) | Yes | appendix A; `AUTH_MODE_*` constants on docs.rs | Yes |
| Password change | Yes | §2.9 | Yes |
| Driver Pool (homogeneous) | Yes | appendix A: "Homogeneous only". Idle timeout and max lifetime are missing ([#72](https://github.com/oracle/rust-oracledb/issues/72), open) | Yes |
| DRCP | **Yes** | appendix A. Code: `SERVER=pooled` is accepted (`src/config/connect_string_parser.rs`), there are `cclass` and `PURITY_NEW`/`PURITY_SELF`, and the generated connection class is `RSO:<uuid>` (`src/config/pool_config.rs`) | Yes |
| Implicit connection pooling (DRCP/PRCP, 26ai) | No | appendix A | Yes |
| Shared servers, multitenant, CMAN/CMAN-TDM | Yes | appendix A. `ALTER SESSION SET CONTAINER` is handled from beta.6 ([#82](https://github.com/oracle/rust-oracledb/issues/82)) | Yes |
| RAC | Yes | appendix A. Open bug: a SCAN redirect starting with `(DESCRIPTION=` fails ([#88](https://github.com/oracle/rust-oracledb/issues/88)) | Yes |
| Application Continuity and TAC | **No** | appendix A. beta.5 only stopped the driver from failing when the server sends AC replay context or LTXID data | No |
| Transaction Guard | No | appendix A | Yes |
| TAF, FAN, Runtime Load Balancing | No | appendix A | No |
| Data Guard and Active Data Guard | No | appendix A | — |
| Call timeout | Yes | from beta.6 it covers both send and receive ([#77](https://github.com/oracle/rust-oracledb/issues/77)). A call that times out closes the Connection ([#23](https://github.com/oracle/rust-oracledb/issues/23)) | Yes |
| Cancelling a running call | **No** | [#24](https://github.com/oracle/rust-oracledb/issues/24), open: "a known limitation … I am hoping to add support for an async API where this will be relatively straightforward" | Yes (`Connection.cancel()`) |
| Bequeath connections | No | §2.2: "Rust-oracledb cannot use 'bequeath' connections" | No |
| Database startup and shutdown, preliminary auth | No | appendix A; [#31](https://github.com/oracle/rust-oracledb/issues/31), open | No |
| `tnsnames.ora`, Easy Connect | Yes | appendix A. `sqlnet.ora` is "Not applicable" | Yes |

## 7. Known Thin-mode limitations that matter to Core

These are not network features, but they matter just as much for using the
crate as the Primary Backend. The Core glossary and
[ADR 0001](../adr/0001-typed-api-instead-of-pdo.md) make Implicit results,
Collections and object types part of the public API.

| Area | rust-oracledb status | Evidence |
|---|---|---|
| **Implicit results** (`DBMS_SQL.RETURN_RESULT`) | **Not supported** | appendix A: "Implicit Result Sets: No". In `src/constants.rs` the constant `_TTC_MSG_TYPE_IMPLICIT_RESULTSET` starts with an underscore, which marks it unused. python-oracledb and node-oracledb Thin both support it |
| **REF CURSOR** | Supported as OUT bind and as `Row::take()` (beta.3) | release notes beta.3; [`doc/plsql_execution.md`](https://github.com/oracle/rust-oracledb/blob/main/doc/plsql_execution.md) §4.4 |
| **Collections and object types** | **Fetch only** (beta.5: "Added support for fetching database objects and collections"). Binding them is not possible: no `ToDbValue` impl exists for `DbObject` or `DbCollection` (`src/db_value.rs`), and `ToDbValue` cannot be implemented outside the crate (discussion [#64](https://github.com/oracle/rust-oracledb/discussions/64)). Whether object OUT binds work was not checked | release notes; source |
| Choosing the IN bind type (NCHAR, NUMBER for `f64`, binding a temporary LOB) | Not yet, planned ([#65](https://github.com/oracle/rust-oracledb/issues/65), discussion #64) | — |
| Scrollable cursors, two-phase commit, sessionless transactions, pipelining, Direct Path Load, SODA, AQ, CQN | No | appendix A ([#91](https://github.com/oracle/rust-oracledb/issues/91) asks for Direct Path Load) |
| Client Result Cache | No | appendix A |
| Client character set | UTF-8 only | appendix A |
| Async API | No. Sync only on blocking `std::net::TcpStream`; planned | discussion [#26](https://github.com/oracle/rust-oracledb/discussions/26) |
| Robustness | beta.1 to beta.4 had several `todo!()` and `panic` paths, for example SQL comments, q-strings, region time zones and odd packets ([#1](https://github.com/oracle/rust-oracledb/issues/1), [#22](https://github.com/oracle/rust-oracledb/issues/22), [#48](https://github.com/oracle/rust-oracledb/issues/48), [#55](https://github.com/oracle/rust-oracledb/issues/55), [#63](https://github.com/oracle/rust-oracledb/issues/63)). Some remain open: [#41](https://github.com/oracle/rust-oracledb/issues/41), [#43](https://github.com/oracle/rust-oracledb/issues/43), [#68](https://github.com/oracle/rust-oracledb/issues/68). A panic in a PHP extension aborts the worker unless every FFI boundary catches it | issues |
| Docs coverage | docs.rs reports "45.36% of the crate is documented" | [docs.rs](https://docs.rs/oracledb/26.0.0-beta.6/oracledb/) |

## 8. What this means for choosing the Primary Backend

Possible hard gates, which decide whether the Rust Thin Backend can be the
Primary Backend for v1:

1. **No Implicit results, no Object or Collection binds.** Core's typed API
   needs both (ADR 0001). We would have to implement them ourselves in a fork
   or patch queue, or wait for upstream. The protocol work already exists in
   python-oracledb Thin, the reference this crate is ported from. Upstreaming
   goes through the OCA and is re-applied by hand.
2. **Beta with a breaking API and no GA date.** Pin an exact version
   (`=26.0.0-beta.N`) and keep the crate behind the Driver contract, so that
   upstream churn never leaks into Core.
3. **No NNE, and Oracle has said no.** Some enterprise 19c installations
   require NNE on plain TCP listeners. A Thin Backend can never connect to
   them: not Rust, not python, not node. ezTLS needs Database 23.26.4 or
   later. Kerberos, LDAP, SEPS and `cwallet.sso` are missing too. **A Thick
   Backend (oci8 or ODPI-C) must remain available** as the fallback for these
   users. This matches the plug-and-play backend strategy.

Risks to watch that are not gates:

- Bus factor of one committer, whose time is shared with python-oracledb.
- Sync only, with no cancel. That is fine for a synchronous v1, but async v2
  depends on upstream async.
- Open bugs: RAC SCAN redirect (#88) and slow large fetches before 26ai (#89).
- TLS trust comes from bundled roots only, and the server DN is not checked
  (#25, #39, #62).
- Only 19c and later is tested, although 12.1 connects.
