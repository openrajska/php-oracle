# Core exposes its own typed API instead of being a PDO driver

PDO cannot represent the ways PL/SQL returns data: several REF CURSORs per Call,
typed OUT and IN OUT parameters, Implicit results, Collections and object types.
PDO_OCI rejects `PDO::PARAM_STMT` and ignores parameter types. yajra/laravel-oci8
fakes a PDO on top of oci8, and most of its known problems come from forcing
these features through `PDO::PARAM_*` (every bind becomes VARCHAR2, one REF
CURSOR per call, OUT values lost). So Core defines its own typed API, and the
Laravel adapter integrates through a native `Connection` that routes everything
through `run()`. That route keeps `DB::listen`, Telescope and Pulse working.

## Considered options

- **A PDO subclass over oci8 (the yajra approach)**: rejected for the fidelity
  losses above.
- **PDO_OCI**: rejected because it has no REF CURSOR support and no statement
  cache.

## Consequences

- Code written for `PDO` does not work against Core unchanged.
- Libraries that require a `PDO` instance (some Doctrine DBAL paths, raw
  `DB::getPdo()` calls in Laravel apps) need an adapter or will not be
  supported.
