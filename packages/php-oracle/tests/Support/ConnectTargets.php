<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The other ways of reaching the test database, for the connect-parameter
 * tests. Each comes from the environment; a test whose target is not set is
 * skipped, naming the variable.
 *
 * - ORACLE_TLS_CONNECT_STRING / ORACLE_MTLS_CONNECT_STRING: the TCPS
 *   listeners of fixtures/tls (one-way TLS and mTLS, private CA);
 * - ORACLE_ADMIN_CONNECT_STRING, ORACLE_ADMIN_USER, ORACLE_ADMIN_PASSWORD:
 *   a user of the CDB root that reads V$CPOOL_CC_STATS (DRCP statistics are
 *   kept there);
 * - the client wallets are fixtures/tls/client in this repository.
 */
final class ConnectTargets
{
    /** The pass phrase of the encrypted wallet key (fixtures/tls/make.sh). */
    public const string WALLET_PASSWORD = 'rajska_wallet1';

    public static function variable(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            TestCase::markTestSkipped("Set $name to run this test.");
        }

        return $value;
    }

    /**
     * A client wallet directory of fixtures/tls/client: ca, mtls,
     * mtls-encrypted, p12 or sso.
     */
    public static function wallet(string $name): string
    {
        $path = \dirname(__DIR__, 4) . '/fixtures/tls/client/' . $name;
        if (!is_dir($path)) {
            throw new RuntimeException("No client wallet at $path.");
        }

        return $path;
    }

    /**
     * The host, port and service of an Easy Connect string such as
     * `oracle:1521/FREEPDB1` or `tcps://oracle:2484/FREEPDB1`.
     *
     * @return array{protocol: string, host: string, port: int, service: string}
     */
    public static function parts(string $easyConnect): array
    {
        if (preg_match('~^(?:(\w+)://)?([^:/]+)(?::(\d+))?/([\w.]+)$~', $easyConnect, $m) !== 1) {
            throw new RuntimeException("Not a plain Easy Connect string: $easyConnect");
        }

        return [
            'protocol' => $m[1] !== '' ? $m[1] : 'tcp',
            'host' => $m[2],
            'port' => $m[3] !== '' ? (int) $m[3] : 1521,
            'service' => $m[4],
        ];
    }

    /**
     * The same target as a full descriptor, with extra text inside
     * DESCRIPTION.
     */
    public static function descriptor(string $easyConnect, string $extra = ''): string
    {
        $parts = self::parts($easyConnect);

        return sprintf(
            '(DESCRIPTION=%s(ADDRESS=(PROTOCOL=%s)(HOST=%s)(PORT=%d))(CONNECT_DATA=(SERVICE_NAME=%s)))',
            $extra,
            strtoupper($parts['protocol']),
            $parts['host'],
            $parts['port'],
            $parts['service'],
        );
    }

    /**
     * Reads one value from the CDB root as the admin user (through oci8,
     * never through a Backend).
     *
     * @param array<string, scalar|null> $binds
     * @return list<array<string, string|null>>
     */
    public static function admin(string $sql, array $binds = []): array
    {
        $connection = @oci_new_connect(
            self::variable('ORACLE_ADMIN_USER'),
            self::variable('ORACLE_ADMIN_PASSWORD'),
            self::variable('ORACLE_ADMIN_CONNECT_STRING'),
            'AL32UTF8',
        );
        if ($connection === false) {
            throw new RuntimeException('Cannot connect as the admin user: ' . self::error(oci_error()));
        }
        try {
            $statement = oci_parse($connection, $sql);
            if ($statement === false) {
                throw new RuntimeException("Cannot parse: $sql");
            }
            foreach ($binds as $name => $value) {
                oci_bind_by_name($statement, $name, $binds[$name]);
            }
            if (!@oci_execute($statement)) {
                throw new RuntimeException(self::error(oci_error($statement)) . " in $sql");
            }
            oci_fetch_all($statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC);

            /** @var list<array<string, string|null>> $rows */
            return $rows;
        } finally {
            oci_close($connection);
        }
    }

    /**
     * @param array<mixed>|false $error
     */
    private static function error(array|false $error): string
    {
        return \is_array($error) && \is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
    }

    /**
     * The path of the loaded rajska_oracle library, to load it in a child
     * process (Linux: /proc/self/maps).
     */
    public static function extensionPath(): string
    {
        $maps = @file_get_contents('/proc/self/maps');
        if ($maps === false || preg_match('~(/\S*rajska_oracle\S*\.so)\s*$~m', $maps, $m) !== 1) {
            TestCase::markTestSkipped('Cannot find the loaded rajska_oracle library (needs Linux /proc).');
        }

        return $m[1];
    }
}
