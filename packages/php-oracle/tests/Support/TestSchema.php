<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * The schema a test process owns, as Laravel's parallel testing gives each
 * process its own database: the admin user creates it with the suites'
 * grants and installs fixtures/sql in it. It is kept between runs and
 * rebuilt only when the fixtures change.
 */
final class TestSchema
{
    private const string FIXTURES = __DIR__ . '/../../../../fixtures/sql/install.sql';

    /**
     * @param resource $admin
     */
    private function __construct(private $admin, private readonly string $name)
    {
    }

    public static function prepare(string $connectString, string $admin, #[SensitiveParameter] string $password, string $name): void
    {
        $statements = SqlScript::statements(self::FIXTURES);
        $hash = sha1(implode("\n", $statements));
        $schema = new self(Oci::connect($admin, $password, $connectString), strtoupper($name));
        if ($schema->hash() !== $hash) {
            $schema->drop();
            $schema->create();
            $owner = Oci::connect($name, $name, $connectString);
            array_map(static fn (string $statement): bool => Oci::run($owner, $statement), $statements);
            Oci::run($owner, 'create table test_fixtures (hash varchar2(40))');
            Oci::run($owner, "insert into test_fixtures values ('$hash')");
            oci_close($owner);
        }
        oci_close($schema->admin);
    }

    private function hash(): string | null
    {
        try {
            return Oci::value($this->admin, "select hash from {$this->name}.test_fixtures");
        } catch (RuntimeException) {
            return null;
        }
    }

    private function drop(): void
    {
        $sessions = Oci::rows($this->admin, 'select sid, serial# as serial from v$session where username = :name', ['name' => $this->name]);
        foreach ($sessions as $session) {
            Oci::run($this->admin, "alter system kill session '{$session['SID']},{$session['SERIAL']}' immediate");
        }
        foreach ([$this->name, "{$this->name}_CLIENT"] as $user) {
            if (Oci::value($this->admin, 'select count(*) from dba_users where username = :name', ['name' => $user]) !== '0') {
                Oci::run($this->admin, "drop user $user cascade");
            }
        }
    }

    /**
     * The user with the grants of fixtures/sql/grants.sql, and its proxy
     * client `<name>_CLIENT`.
     */
    private function create(): void
    {
        foreach ([
            "create user {$this->name} identified by \"" . strtolower($this->name) . '" default tablespace users quota unlimited on users',
            "grant db_developer_role, select any dictionary, alter system to {$this->name}",
            "create user {$this->name}_CLIENT no authentication",
            "grant create session to {$this->name}_CLIENT",
            "alter user {$this->name}_CLIENT grant connect through {$this->name}",
        ] as $statement) {
            Oci::run($this->admin, $statement);
        }
    }
}
