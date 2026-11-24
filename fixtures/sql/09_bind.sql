-- Target for INSERT ... RETURNING ... INTO. A global temporary table: its rows
-- are private to the Session and gone at the next commit or rollback, so a
-- test under Autocommit leaves nothing behind.
create global temporary table fx_bind_rows (
  id   number(10),
  name varchar2(4000),
  code char(5)
) on commit delete rows;
