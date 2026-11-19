-- Object types and Collections (nested tables and a VARRAY) as IN, OUT,
-- IN OUT, return values and table columns.
create or replace type fx_point as object (
  x     number,
  y     number,
  label varchar2(30)
);
/

-- Nested table of objects.
create or replace type fx_point_list as table of fx_point;
/

-- Nested table of scalars. Also the type to bind into TABLE(:ids).
create or replace type fx_number_list as table of number;
/

-- VARRAY with a limit: more than 10 elements raises ORA-06532.
create or replace type fx_string_varray as varray(10) of varchar2(100);
/

-- An object with a Collection attribute.
create or replace type fx_polygon as object (
  name     varchar2(50),
  vertices fx_point_list
);
/

create table fx_shapes (
  id      number(10) constraint fx_shapes_pk primary key,
  center  fx_point,
  tags    fx_string_varray,
  outline fx_point_list
)
nested table outline store as fx_shapes_outline_nt;

-- Row 1: every column set. Row 2: every column NULL. Row 3: empty Collections.
insert into fx_shapes (id, center, tags, outline)
values (
  1,
  fx_point(1.5, -2, 'center'),
  fx_string_varray('red', unistr('caf\00e9')),
  fx_point_list(fx_point(0, 0, 'a'), fx_point(1, 0, 'b'), fx_point(0, 1, null))
);
insert into fx_shapes (id, center, tags, outline)
values (2, null, null, null);
insert into fx_shapes (id, center, tags, outline)
values (3, fx_point(null, null, null), fx_string_varray(), fx_point_list());

create or replace package fx_object as
  function make_point(p_x in number, p_y in number, p_label in varchar2)
    return fx_point;

  -- x + p_dx, y + p_dy; label unchanged.
  procedure move_point(io_point in out fx_point, p_dx in number, p_dy in number);

  -- NULL for a NULL Collection, 0 for an empty one.
  function sum_numbers(p_numbers in fx_number_list) return number;

  -- Same elements in reverse order; NULL in gives NULL out.
  procedure reverse_numbers(
    p_numbers  in  fx_number_list,
    o_reversed out fx_number_list
  );

  -- Splits on ','. More than 10 parts raises ORA-06532.
  function split_csv(p_csv in varchar2) return fx_string_varray;

  procedure append_tag(io_tags in out fx_string_varray, p_tag in varchar2);

  -- A regular polygon with p_sides vertices on the unit circle (rounded to 6
  -- decimals), labelled 'v1' .. 'vN'.
  function make_polygon(p_name in varchar2, p_sides in number) return fx_polygon;

  function count_vertices(p_polygon in fx_polygon) return number;

  -- Associative array (INDEX BY): a PL/SQL-only type, never a SQL column.
  type t_number_map is table of number index by pls_integer;

  -- Sum of the values; 0 for an empty map.
  function sum_map(p_map in t_number_map) return number;

  -- Sparse keys 10, 20, .. p_count * 10 holding the values 1 .. p_count.
  procedure fill_map(p_count in number, o_map out t_number_map);
end fx_object;
/

create or replace package body fx_object as
  function make_point(p_x in number, p_y in number, p_label in varchar2)
    return fx_point is
  begin
    return fx_point(p_x, p_y, p_label);
  end make_point;

  procedure move_point(io_point in out fx_point, p_dx in number, p_dy in number) is
  begin
    io_point.x := io_point.x + p_dx;
    io_point.y := io_point.y + p_dy;
  end move_point;

  function sum_numbers(p_numbers in fx_number_list) return number is
    l_sum number := 0;
  begin
    if p_numbers is null then
      return null;
    end if;
    for i in 1 .. p_numbers.count loop
      l_sum := l_sum + p_numbers(i);
    end loop;
    return l_sum;
  end sum_numbers;

  procedure reverse_numbers(
    p_numbers  in  fx_number_list,
    o_reversed out fx_number_list
  ) is
  begin
    if p_numbers is null then
      o_reversed := null;
      return;
    end if;
    o_reversed := fx_number_list();
    o_reversed.extend(p_numbers.count);
    for i in 1 .. p_numbers.count loop
      o_reversed(i) := p_numbers(p_numbers.count - i + 1);
    end loop;
  end reverse_numbers;

  function split_csv(p_csv in varchar2) return fx_string_varray is
    l_parts fx_string_varray := fx_string_varray();
  begin
    if p_csv is null then
      return l_parts;
    end if;
    for i in 1 .. regexp_count(p_csv, ',') + 1 loop
      l_parts.extend;
      l_parts(i) := regexp_substr(p_csv, '([^,]*)(,|$)', 1, i, null, 1);
    end loop;
    return l_parts;
  end split_csv;

  procedure append_tag(io_tags in out fx_string_varray, p_tag in varchar2) is
  begin
    if io_tags is null then
      io_tags := fx_string_varray();
    end if;
    io_tags.extend;
    io_tags(io_tags.count) := p_tag;
  end append_tag;

  function make_polygon(p_name in varchar2, p_sides in number) return fx_polygon is
    l_vertices fx_point_list := fx_point_list();
    l_angle    number;
  begin
    for i in 1 .. p_sides loop
      l_angle := 2 * acos(-1) * (i - 1) / p_sides;
      l_vertices.extend;
      l_vertices(i) := fx_point(round(cos(l_angle), 6), round(sin(l_angle), 6), 'v' || i);
    end loop;
    return fx_polygon(p_name, l_vertices);
  end make_polygon;

  function count_vertices(p_polygon in fx_polygon) return number is
  begin
    if p_polygon is null or p_polygon.vertices is null then
      return null;
    end if;
    return p_polygon.vertices.count;
  end count_vertices;

  function sum_map(p_map in t_number_map) return number is
    l_sum number := 0;
    l_key pls_integer := p_map.first;
  begin
    while l_key is not null loop
      l_sum := l_sum + p_map(l_key);
      l_key := p_map.next(l_key);
    end loop;
    return l_sum;
  end sum_map;

  procedure fill_map(p_count in number, o_map out t_number_map) is
  begin
    for i in 1 .. p_count loop
      o_map(i * 10) := i;
    end loop;
  end fill_map;
end fx_object;
/
