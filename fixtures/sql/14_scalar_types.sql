-- Column-only scalar types.
--   fx_long_rows      a LONG column (a table holds one LONG or LONG RAW at
--                     most); row 1 holds text, row 2 NULL.
--   fx_long_raw_rows  a LONG RAW column; row 1 holds bytes, row 2 NULL.
--   fx_iot            an index-organized table: its ROWID is a UROWID.
-- Writes into the LONG tables use ids from 100 and are rolled back.
create table fx_long_rows (
  id   number(10) constraint fx_long_rows_pk primary key,
  text long
);

insert into fx_long_rows (id, text) values (1, 'long text');
insert into fx_long_rows (id, text) values (2, null);

create table fx_long_raw_rows (
  id    number(10) constraint fx_long_raw_rows_pk primary key,
  bytes long raw
);

insert into fx_long_raw_rows (id, bytes) values (1, hextoraw('00FF10'));
insert into fx_long_raw_rows (id, bytes) values (2, null);

create table fx_iot (
  id    number(10) constraint fx_iot_pk primary key,
  label varchar2(10)
) organization index;

insert into fx_iot (id, label) values (1, 'one');
