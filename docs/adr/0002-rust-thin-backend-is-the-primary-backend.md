---
status: accepted
---

# The Rust thin Backend is the Primary Backend, with conditions

The Primary Backend is the Rust thin Backend: `rajska_oracle`, a PHP extension
built on Oracle's `oracledb` crate (pure Rust, no Instant Client). The Driver
contract is designed against it and the conformance suite runs on it first.
The answer is **yes with conditions**: every hard gate from the acceptance
criteria passed, and each item that passed only through a workaround, a planned
upstream fix or a patch of our own is a condition with a named exit. The
evidence is in [`spikes/rust-oracledb/FINDINGS.md`](../../spikes/rust-oracledb/FINDINGS.md).

## Decision drivers

In the order fixed before any probe ran:

1. **Practicality**: `rajska_oracle` is an ordinary extension installed through
   PIE as a pre-built binary. No Instant Client, and the user never compiles
   anything. *Met*: all twelve targets (Linux glibc and musl × x86_64 and arm64,
   macOS arm64, Windows x86_64; NTS and ZTS) build, load and run a real query,
   with only libc at runtime.
2. **Memory safety**: Rust instead of a C bridge. *Met*: the protocol stack and
   the TLS stack (rustls) are Rust; the C left is PHP itself and aws-lc, the
   crypto library under rustls.
3. **Native Pool + DRCP**. *Met*: one Pool served 22,400 Calls from 16–64
   threads with no wrong result and no Session shared, and DRCP connects and
   runs Calls. The DRCP server is not yet released while a Connection is idle
   (condition C15).
4. **Performance**: a real goal, aiming at ≥ 2× faster than oci8. *Gate
   passed, target not reached*: a Call is 1.28× faster, a 10k-row fetch
   1.69–1.78×, a REF CURSOR Call 1.22–1.26×, with a third of oci8's memory.
   About 60% of the fetch is the extension's zval conversion, which is the
   front toward 2×.

## Conditions

There are eighteen from the spike, plus one (C19) added while designing the
bind model, all listed with their exits in FINDINGS.md. By exit:

- **Fork + upstream PR** (build): Implicit results (C1), binds of objects,
  Collections and Associative arrays (C2), and three bind patches decided in
  [Bind model and Type catalog for IN, OUT and IN OUT](https://github.com/openrajska/php-oracle/issues/22):
  a declared bind size, which lifts the VARCHAR2 OUT and IN OUT limits (C5,
  C6); a bindable `Lob`, for LOB IN over 32767 bytes (C11); and CHAR / NCHAR /
  NVARCHAR2 binds (C19). These patches are contained and only add API. We
  carry them in a fork of `oracle/rust-oracledb` pinned to an exact version (C17) and offer each one upstream; the fork goes away as upstream
  absorbs them.
- **Upstream**: per-row batch errors (C3, planned on rust-oracledb#41),
  cancelling a Call (C16, rust-oracledb#24), and DRCP server release (C15,
  rust-oracledb#98, not yet planned; the fork builds it if upstream doesn't).
- **Workarounds Core or the extension applies**: NUMBER from a PHP float (C4),
  the TSTZ offset (C7), LOB edge cases (C8–C10), Pool growth, acquire timeout
  and release with an open transaction (C12–C14), and the Windows toolchain (C18).

## Considered options

- **ODPI-C (a Thick Backend)**: the next candidate had any hard gate failed. Not
  evaluated. It needs Instant Client on the user's machine, which is exactly
  what driver 1 rules out.
- **oci8**: never a candidate. It is only the yardstick for results and speed.
- **A pure-PHP thin Backend**: a future Backend for hosts without the extension,
  not a Primary Backend candidate.

## Consequences

- We maintain a fork of a single-maintainer beta crate, and every crate upgrade
  means rebasing the patches and rerunning the probes.
- Core's Driver contract inherits the workarounds as its own rules: look OUT
  binds up by name, read LOBs through locators, never emit a typeless object
  bind, roll back to a savepoint around a failed batch, end the transaction
  before returning a Connection to the Pool.
- Only Oracle Database 26ai was tested. 19c and 21c are recorded as `unknown`
  until the conformance suite runs on them.
- Users who need NNE, Kerberos, LDAP or external auth are not served in v1, and
  the glibc binaries don't cover RHEL 8 or Debian 11. Serving them is a separate
  decision.
