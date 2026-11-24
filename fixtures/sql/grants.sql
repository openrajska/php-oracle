-- Grants the test suites need on top of the fixtures: read access to the
-- V$ views the tests assert on (Round-trip counts, Sessions, SQL).
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

prompt Grants applied.
exit success
