-- Native JSON and VECTOR (23ai and later; skipped on older databases, so the
-- install still runs on 19c).
--   fx_vectors      one VECTOR column per storage format, dense and sparse,
--                   plus a flexible one; row 1 holds values, row 2 NULLs.
--   fx_vector_rows  a global temporary table to bind into: rows are private
--                   to the Session and gone at the next commit or rollback.
--   fx_json_docs    a JSON collection table: its documents carry an OSON id
--                   (_id), which no Backend reads yet.
declare
  procedure run(p_sql in varchar2) is
  begin
    execute immediate p_sql;
  end run;
begin
  if dbms_db_version.version < 23 then
    return;
  end if;

  run(q'~
    create table fx_vectors (
      id   number(10) primary key,
      v32  vector(3, float32),
      v64  vector(3, float64),
      v8   vector(3, int8),
      vbin vector(16, binary),
      s32  vector(10, float32, sparse),
      s64  vector(10, float64, sparse),
      s8   vector(10, int8, sparse),
      flex vector(*, *)
    )~');
  run(q'~
    insert into fx_vectors values (
      1,
      vector('[1.5, -2.25, 0.1]', 3, float32),
      vector('[1.5, -2.25, 0.1]', 3, float64),
      vector('[1, -2, 127]', 3, int8),
      vector('[1, 255]', 16, binary),
      vector('[10, [1, 5], [1.5, 0.1]]', 10, float32, sparse),
      vector('[10, [1, 5], [1.5, 0.1]]', 10, float64, sparse),
      vector('[10, [0, 9], [-128, 127]]', 10, int8, sparse),
      vector('[1, 2, 3, 4]', 4, float64)
    )~');
  run('insert into fx_vectors (id) values (2)');

  run(q'~
    create global temporary table fx_vector_rows (
      id  number(10),
      v32 vector(3, float32),
      s32 vector(10, float32, sparse),
      doc json
    ) on commit delete rows~');

  run(q'~
    create package fx_json_vector as
      function f_json(p_null in boolean default false) return json;
      function f_vector(p_null in boolean default false) return vector;
      function f_sparse return vector;
    end fx_json_vector;~');
  run(q'~
    create package body fx_json_vector as
      function f_json(p_null in boolean default false) return json is
      begin
        if p_null then
          return null;
        end if;
        return json('{"a": [1, 2.5]}');
      end f_json;

      function f_vector(p_null in boolean default false) return vector is
      begin
        if p_null then
          return null;
        end if;
        return to_vector('[1.5, 2]', 2, float32);
      end f_vector;

      function f_sparse return vector is
      begin
        return to_vector('[10, [3], [-1]]', 10, int8, sparse);
      end f_sparse;
    end fx_json_vector;~');

  run('create json collection table fx_json_docs');
  run(q'~insert into fx_json_docs values ('{"name": "first"}')~');
end;
/
