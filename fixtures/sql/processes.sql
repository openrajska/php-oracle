-- Raises the instance's PROCESSES limit and restarts the instance, which the
-- new limit needs. SESSIONS follows it (1.5 x PROCESSES + 22).
--
-- The image ships PROCESSES = 200, about 90 of them background processes.
-- The Core suite holds about 60 more at its end (a Session per Pool it
-- created, and Pools live as long as the process), so a burst of logons late
-- in the suite (oci8 observers, child processes) took the listener's count
-- to the limit and it refused logons with ORA-12516, though the instance
-- itself never went past 160.
--
-- It ends every Session of the instance: run it before anything else uses
-- the database. Run as SYSDBA through OS authentication inside the oracle
-- container, with the new limit as the only argument:
--   sqlplus -s -l / as sysdba @processes.sql 400
-- FREEPDB1 opens again on startup (its open state is saved) and registers
-- with the listener at once.
whenever sqlerror exit failure
set echo off feedback off heading off verify off

alter system set processes = &1 scope = spfile;
shutdown immediate
startup
alter system register;

prompt Processes raised to &1..
exit success
