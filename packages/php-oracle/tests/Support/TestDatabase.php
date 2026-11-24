<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

use RuntimeException;

/**
 * Where the suites find Oracle: the ORACLE_CONNECT_STRING, ORACLE_USER and
 * ORACLE_PASSWORD environment variables (ddev's web container sets them).
 * The user owns the fixtures (fixtures/sql/install.sql) and has the grants
 * of fixtures/sql/grants.sql.
 */
final readonly class TestDatabase
{
    private function __construct(
        public string $connectString,
        public string $user,
        #[\SensitiveParameter] public string $password,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::variable('ORACLE_CONNECT_STRING'),
            self::variable('ORACLE_USER'),
            self::variable('ORACLE_PASSWORD'),
        );
    }

    private static function variable(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException("Set $name to reach the test database.");
        }

        return $value;
    }
}
