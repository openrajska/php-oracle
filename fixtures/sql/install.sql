-- Installs (or reinstalls) the fixture schema in the current user.
-- Idempotent: drops every FX_% object first. Run from this directory:
--   sqlplus -s -l rajska/rajska@//localhost/FREEPDB1 @install.sql
-- In ddev: `ddev fixtures`.
whenever sqlerror exit failure rollback
whenever oserror exit failure
set echo off feedback off heading off verify off define off sqlblanklines on

prompt drop
@@drop.sql
prompt 01_scalar
@@01_scalar.sql
prompt 02_cursor
@@02_cursor.sql
prompt 03_object
@@03_object.sql
prompt 04_pipe
@@04_pipe.sql
prompt 05_lob
@@05_lob.sql
prompt 06_batch
@@06_batch.sql
prompt 07_pool
@@07_pool.sql
prompt 08_bench
@@08_bench.sql

commit;

-- CREATE PACKAGE with compilation errors is only a warning in SQL*Plus, so
-- fail here if any fixture object is invalid.
declare
  l_invalid varchar2(4000);
  l_errors  varchar2(4000);
begin
  select listagg(object_type || ' ' || object_name, ', ')
         within group (order by object_name)
    into l_invalid
    from user_objects
   where object_name like 'FX\_%' escape '\'
     and status <> 'VALID';

  if l_invalid is not null then
    select substr(listagg(name || ' line ' || line || ': ' || text, '; ')
                  within group (order by name, sequence), 1, 3000)
      into l_errors
      from user_errors
     where name like 'FX\_%' escape '\';
    raise_application_error(-20000,
      'Invalid fixture objects: ' || l_invalid || '. Errors: ' || l_errors);
  end if;
end;
/

prompt Fixtures installed.
exit success
