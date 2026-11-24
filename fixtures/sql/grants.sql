-- Grants the test suites need on top of the fixtures: read access to the
-- V$ views the tests assert on (Round-trip counts, Sessions, SQL, temporary
-- LOBs, DRCP connection classes), ALTER SYSTEM so a test can kill a Session
-- of its own, and a proxy client for proxy authentication: the user
-- <user>_CLIENT (schema only, no password) that <user> may connect through.
-- Run as SYSDBA in the PDB, with the test user as the only argument:
--   sqlplus -s -l sys/oracle@//localhost/FREEPDB1 as sysdba @grants.sql rajska
-- In ddev: `ddev fixtures` runs it for the app user.
whenever sqlerror exit failure
whenever oserror exit failure
set echo off feedback off heading off verify off

grant select on sys.v_$mystat to &1;
grant select on sys.v_$sesstat to &1;
grant select on sys.v_$statname to &1;
grant select on sys.v_$session to &1;
grant select on sys.v_$sql to &1;
grant select on sys.v_$cpool_cc_stats to &1;
grant select on sys.v_$temporary_lobs to &1;
grant alter system to &1;

declare
    n integer;
begin
    select count(*) into n from dba_users where username = upper('&1._client');
    if n = 0 then
        execute immediate 'create user &1._client no authentication';
    end if;
end;
/
grant create session to &1._client;
alter user &1._client grant connect through &1;

prompt Grants applied.
exit success
