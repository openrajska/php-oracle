<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Support;

use Rajska\Oracle\ConnectionConfig;
use RuntimeException;
use SensitiveParameter;

/**
 * Where the suites find Oracle: ORACLE_CONNECT_STRING, and the admin user the
 * image ships (ORACLE_ADMIN_USER, ORACLE_ADMIN_PASSWORD; ddev and CI set
 * them). Each test process works in a schema of its own, `test` or
 * `test_<TEST_TOKEN>` under `--parallel`, that TestSchema prepares on the
 * process's first call.
 */
final class TestDatabase
{
    private static self | null $current = null;

    private function __construct(
        public readonly string $connectString,
        public readonly string $user,
        #[SensitiveParameter]
        public readonly string $password,
    ) {
    }

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
        if (self::$current instanceof self) {
            return self::$current;
        }
        $connectString = self::variable('ORACLE_CONNECT_STRING');
        $token = getenv('TEST_TOKEN');
        $schema = \is_string($token) && $token !== '' ? "test_$token" : 'test';
        TestSchema::prepare($connectString, self::variable('ORACLE_ADMIN_USER'), self::variable('ORACLE_ADMIN_PASSWORD'), $schema);

        return self::$current = new self($connectString, $schema, $schema);
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
