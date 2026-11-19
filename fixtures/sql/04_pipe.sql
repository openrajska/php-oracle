-- Pipelined table functions, read with SELECT ... FROM TABLE(...).
-- A Collection bound into TABLE(:ids) needs no object of its own: it uses
-- fx_number_list from 03_object.sql.
create or replace package fx_pipe as
  -- 1 .. p_count. SELECT column_value FROM TABLE(fx_pipe.numbers(:n)).
  function numbers(p_count in number) return fx_number_list pipelined;

  -- fx_point(i, i * i, 'P' || i) for i in 1 .. p_count.
  -- SELECT x, y, label FROM TABLE(fx_pipe.points(:n)).
  function points(p_count in number) return fx_point_list pipelined;
end fx_pipe;
/

create or replace package body fx_pipe as
  function numbers(p_count in number) return fx_number_list pipelined is
  begin
    for i in 1 .. p_count loop
      pipe row (i);
    end loop;
    return;
  end numbers;

  function points(p_count in number) return fx_point_list pipelined is
  begin
    for i in 1 .. p_count loop
      pipe row (fx_point(i, i * i, 'P' || i));
    end loop;
    return;
  end points;
end fx_pipe;
/
