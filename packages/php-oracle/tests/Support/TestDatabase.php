<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

use Rajska\Oracle\ConnectionConfig;
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
        #[\SensitiveParameter]
        public string $password,
    ) {}

    /**
     * A ConnectionConfig for the test database; named arguments override
     * the other fields.
     */
    public function config(mixed ...$fields): ConnectionConfig
    {
        // @phpstan-ignore argument.type (the named fields go through as given)
        return new ConnectionConfig(...[
            'dsn' => $this->connectString,
            'user' => $this->user,
            'password' => $this->password,
            ...$fields,
        ]);
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
