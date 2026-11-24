-- Starts DRCP (the default connection pool of the CDB), which the DRCP tests
-- connect to through `:pooled`. Idempotent. Run as SYSDBA in the CDB root:
--   sqlplus -s -l sys/oracle@//localhost/FREE as sysdba @drcp.sql
-- In ddev: `ddev fixtures` runs it.
whenever sqlerror exit failure
set echo off feedback off heading off verify off

exec dbms_connection_pool.start_pool()

prompt DRCP started.
exit success
