<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests;

use Override;

/**
 * Empties the process's schema before each test file, as Laravel's
 * `migrate:fresh` does: a file finds only the objects it creates.
 */
trait RefreshDatabase
{
    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Some objects go with their table (a nested table's storage, an
        // identity column's sequence, a JSON collection table's synonym):
        // dropping them again fails with the codes left alone.
        schema()->run(<<<'SQL'
            begin
                for o in (
                    select object_type, object_name
                      from user_objects
                     where object_type in ('TABLE', 'VIEW', 'SEQUENCE', 'SYNONYM', 'PACKAGE', 'PROCEDURE', 'FUNCTION', 'TYPE')
                       and generated = 'N'
                       and object_name not like 'BIN$%'
                     order by decode(object_type, 'TABLE', 1, 'TYPE', 3, 2)
                ) loop
                    begin
                        execute immediate 'drop ' || o.object_type || ' "' || o.object_name || '"'
                            || case o.object_type when 'TABLE' then ' cascade constraints purge' when 'TYPE' then ' force' end;
                    exception
                        when others then
                            if sqlcode not in (-942, -1434, -2289, -4043, -22914) then
                                raise;
                            end if;
                    end;
                end loop;
            end;
            SQL);
    }
}
