-- Transactions: rows written under Autocommit or inside an Explicit
-- transaction, then checked from another Session. Starts empty; tests delete
-- what they write. The CHECK on qty is deferred, so a negative qty passes the
-- INSERT and fails at COMMIT (ORA-02091, with ORA-02290 below it).
create table fx_tx_rows (
  id   number(10)  constraint fx_tx_rows_pk primary key,
  qty  number(10)  default 0
                   constraint fx_tx_rows_qty_ck check (qty >= 0)
                   deferrable initially deferred,
  note varchar2(100)
);

create or replace package fx_tx as
  procedure add_row(p_id in number, p_note in varchar2 default null);

  -- Inserts one row and returns how many rows the table now has.
  function add_row_count(p_id in number) return number;
end fx_tx;
/

create or replace package body fx_tx as
  procedure add_row(p_id in number, p_note in varchar2 default null) is
  begin
    insert into fx_tx_rows (id, note) values (p_id, p_note);
  end add_row;

  function add_row_count(p_id in number) return number is
    l_count number;
  begin
    insert into fx_tx_rows (id) values (p_id);
    select count(*) into l_count from fx_tx_rows;
    return l_count;
  end add_row_count;
end fx_tx;
/
