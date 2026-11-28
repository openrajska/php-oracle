<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Oracle;
use RuntimeException;

/**
 * Every test runs in a schema of its own process, `test` or
 * `test_<TEST_TOKEN>` under `--parallel`, as Laravel's parallel testing
 * gives each process its own database. The admin user creates it, empty, on
 * the first use; each test file creates the objects it reads.
 */
abstract class TestCase extends BaseTestCase
{
    use InteractsWithDatabase;
    use RefreshDatabase;

    /**
     * A config for the process's schema; named arguments override fields.
     */
    public static function config(mixed ...$fields): ConnectionConfig
    {
        $token = getenv('TEST_TOKEN');
        $schema = \is_string($token) && $token !== '' ? "test_$token" : 'test';
        // A child process a test starts (another FPM worker) inherits the
        // marker, and finds the schema in place.
        if (getenv('TEST_SCHEMA_READY') !== $schema) {
            self::createSchema($schema);
            putenv("TEST_SCHEMA_READY=$schema");
        }

        // @phpstan-ignore argument.type (the named fields go through as given)
        return new ConnectionConfig(...['dsn' => self::env('ORACLE_CONNECT_STRING'), 'user' => $schema, 'password' => $schema, ...$fields]);
    }

    /**
     * The user, whose password is its name, with read access to the V$ views
     * the tests assert on, ALTER SYSTEM to kill a Session of its own, and the
     * proxy client `<name>_CLIENT` it may connect through.
     */
    private static function createSchema(string $schema): void
    {
        $admin = Oracle::connect(new ConnectionConfig(
            dsn: self::env('ORACLE_CONNECT_STRING'),
            user: self::env('ORACLE_ADMIN_USER'),
            password: self::env('ORACLE_ADMIN_PASSWORD'),
            standalone: true,
        ));

        try {
            if ($admin->select('select count(*) from dba_users where username = upper(:name)', ['name' => $schema])->value() === 1) {
                return;
            }
            $admin->run("create user $schema identified by \"$schema\" default tablespace users quota unlimited on users");
            $admin->run("grant db_developer_role, select any dictionary, alter system to $schema");
            $admin->run("create user {$schema}_client no authentication");
            $admin->run("grant create session to {$schema}_client");
            $admin->run("alter user {$schema}_client grant connect through $schema");
        } finally {
            $admin->close();
        }
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            throw new RuntimeException("Set $name to reach the test database.");
        }

        return $value;
    }
}
