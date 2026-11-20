-- Rows for the fetch benchmark: 10,000 rows with mixed column types, so a
-- fetch pays for every common conversion (integer and decimal NUMBER,
-- BINARY_DOUBLE, ASCII and multibyte VARCHAR2, DATE, TIMESTAMP, NULLs).
--
-- Row n:
--   id         n
--   qty        mod(n, 1000)
--   amount     n * 1.25 (two decimals)
--   ratio      n / 7 as BINARY_DOUBLE
--   code       'BENCH-' || 6-digit zero-padded n
--   label      'Ação café 日本 ' || n when mod(n, 5) = 0, else 'Item ' || n
--   note       NULL when mod(n, 3) = 0, else 'note ' || n
--   created_at 2026-01-01 00:00 + (n - 1) minutes (DATE)
--   updated_at 2026-01-01 00:00:00.000000 + n milliseconds (TIMESTAMP(6))
create table fx_bench_rows (
  id         number(10)    constraint fx_bench_rows_pk primary key,
  qty        number(10)    not null,
  amount     number(12, 2) not null,
  ratio      binary_double not null,
  code       varchar2(20)  not null,
  label      varchar2(60)  not null,
  note       varchar2(60),
  created_at date          not null,
  updated_at timestamp(6)  not null
);

insert into fx_bench_rows
select level,
       mod(level, 1000),
       level * 1.25,
       to_binary_double(level) / 7,
       'BENCH-' || lpad(level, 6, '0'),
       case when mod(level, 5) = 0
            then to_char(unistr('A\00e7\00e3o caf\00e9 \65e5\672c ')) || level
            else 'Item ' || level
       end,
       case when mod(level, 3) <> 0 then 'note ' || level end,
       date '2026-01-01' + (level - 1) / 1440,
       timestamp '2026-01-01 00:00:00' + numtodsinterval(level / 1000, 'SECOND')
  from dual
connect by level <= 10000;
