-- Cheap calls for running one Pool from several threads: which Session served
-- the Call, a controllable delay, and package state that lives in the Session.
create or replace package fx_pool as
  -- SID of the Session running the Call.
  function session_id return number;

  -- Holds the Session for p_seconds (fractions allowed).
  procedure sleep(p_seconds in number);

  -- Increments and returns a package variable. It starts at 0 in each new
  -- Session, so a Connection that sees a jump or a reset changed Session.
  function bump_counter return number;
end fx_pool;
/

create or replace package body fx_pool as
  g_counter number := 0;

  function session_id return number is
  begin
    return to_number(sys_context('USERENV', 'SID'));
  end session_id;

  procedure sleep(p_seconds in number) is
  begin
    dbms_session.sleep(p_seconds);
  end sleep;

  function bump_counter return number is
  begin
    g_counter := g_counter + 1;
    return g_counter;
  end bump_counter;
end fx_pool;
/
