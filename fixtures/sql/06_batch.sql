-- Target for execute_batch with per-row errors. Starts empty; each constraint
-- produces a different error code:
--   duplicate id or code  -> ORA-00001
--   NULL code or qty      -> ORA-01400
--   qty < 0               -> ORA-02290
create table fx_batch_rows (
  id   number(10)   constraint fx_batch_rows_pk primary key,
  code varchar2(10) not null constraint fx_batch_rows_code_uk unique,
  qty  number(10)   not null constraint fx_batch_rows_qty_ck check (qty >= 0)
);
