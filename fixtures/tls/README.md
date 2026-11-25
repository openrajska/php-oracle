# TLS fixtures

What the TLS tests (spec #32 §11, "Connect parameters") connect through. The
database image has no TCPS listener, so `listeners.sh` starts two next to the
default one, inside the database container:

| Listener | Port | TLS | Suites' variable |
|---|---|---|---|
| `RAJSKA_TLS` | 2484 | one-way: the server's certificate only | `ORACLE_TLS_CONNECT_STRING` |
| `RAJSKA_MTLS` | 2485 | mutual: the client presents a certificate too | `ORACLE_MTLS_CONNECT_STRING` |

Both serve `FREEPDB1` through static registration and leave the default
listener and the database's parameters alone.

## Files

Everything is signed by a throwaway test CA. Test fixtures only: never use any
of it for anything else.

| Path | What |
|---|---|
| `server/` | The server's auto-login wallet (`cwallet.sso`, `ewallet.p12`): a certificate for `oracle`, `localhost` and `127.0.0.1` |
| `client/ca/ewallet.pem` | The CA only: one-way TLS through a private CA |
| `client/mtls/ewallet.pem` | The client's certificate, its unencrypted key and the CA: mTLS |
| `client/mtls-encrypted/ewallet.pem` | The same with the key encrypted (PKCS#8, pass phrase `rajska_wallet1`, the `walletPassword`) |
| `client/p12/ewallet.p12`, `client/sso/cwallet.sso` | The formats oracledb cannot read: they fail with conversion instructions |

`make.sh` regenerates them all. It needs Docker and orapki, which the slim
database image lacks, so it uses `gvenzl/oracle-free`'s "full" image (about
2 GB) once; the results are checked in.

## Starting the listeners

- ddev: `ddev fixtures` runs `listeners.sh` (the folder is mounted at
  `/fixtures/tls` in the `oracle` container) and sets the variables above.
- CI: `.github/workflows/ci.yml` copies the folder into the service container
  and runs it.
- Elsewhere:

```bash
docker cp fixtures/tls <container>:/tmp/rajska-tls-src
docker exec <container> bash /tmp/rajska-tls-src/listeners.sh
```

The tests that need a listener are skipped, naming the variable, when it is
not set.
