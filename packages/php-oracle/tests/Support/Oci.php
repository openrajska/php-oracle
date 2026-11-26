<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * Plain oci8 calls for preparing the test schema, apart from the code under
 * test. Every statement commits.
 */
final class Oci
{
    /**
     * @return resource
     */
    public static function connect(string $user, #[SensitiveParameter] string $password, string $connectString) // @phpstan-ignore typeCoverage.returnTypeCoverage (@pest-ignore-type: PHP has no native resource type)
    {
        $connection = @oci_new_connect($user, $password, $connectString, 'AL32UTF8');
        if ($connection === false) {
            throw self::failure(oci_error(), "connect as $user");
        }

        return $connection;
    }

    /**
     * @param resource $connection
     * @param array<string, scalar|null> $binds
     */
    public static function run($connection, string $sql, array $binds = []): bool
    {
        self::execute($connection, $sql, $binds);

        return true;
    }

    /**
     * @param resource $connection
     * @param array<string, scalar|null> $binds
     */
    public static function value($connection, string $sql, array $binds = []): string | null
    {
        $row = self::rows($connection, $sql, $binds)[0] ?? [];

        return array_values($row)[0] ?? null;
    }

    /**
     * @param resource $connection
     * @param array<string, scalar|null> $binds
     * @return list<array<string, string|null>>
     */
    public static function rows($connection, string $sql, array $binds = []): array
    {
        $statement = self::execute($connection, $sql, $binds);
        oci_fetch_all($statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC);

        /** @var list<array<string, string|null>> $rows */
        return $rows;
    }

    /**
     * @param resource $connection
     * @param array<string, scalar|null> $binds
     * @return resource
     */
    private static function execute($connection, string $sql, array $binds) // @phpstan-ignore typeCoverage.returnTypeCoverage (@pest-ignore-type: PHP has no native resource type)
    {
        $statement = oci_parse($connection, $sql);
        if ($statement === false) {
            throw self::failure(oci_error($connection), $sql);
        }
        foreach (array_keys($binds) as $name) {
            oci_bind_by_name($statement, ":$name", $binds[$name]);
        }
        if (!@oci_execute($statement, OCI_COMMIT_ON_SUCCESS)) {
            throw self::failure(oci_error($statement), $sql);
        }

        return $statement;
    }

    /**
     * @param array<mixed>|false $error what oci_error() returned
     */
    private static function failure(array | false $error, string $what): RuntimeException
    {
        $message = $error['message'] ?? null;

        return new RuntimeException((\is_string($message) ? $message : 'oci8 failed without an Oracle error.') . " ($what)");
    }
}
