-- Objects, Collections and Associative arrays beyond the everyday types of
-- 03_object.sql: every attribute type v1 maps, the attribute types it does
-- not map yet, inheritance, type names and nesting.

-- Every attribute type v1 reads and binds, in one Object (a PL/SQL-only
-- BINARY_INTEGER attribute is in fx_kinds.t_record).
create or replace type fx_all_scalars as object (
  c_char     char(5),
  c_nchar    nchar(5),
  c_varchar  varchar2(20),
  c_nvarchar nvarchar2(20),
  c_raw      raw(10),
  c_number   number,
  c_float    binary_float,
  c_double   binary_double,
  c_boolean  boolean,
  c_date     date,
  c_ts       timestamp(9),
  c_tstz     timestamp(9) with time zone,
  c_tsltz    timestamp(9) with local time zone,
  c_ids      interval day(9) to second(9),
  c_iym      interval year(9) to month
);
/

-- Row 1: a value in every attribute. Row 2: every attribute NULL.
create table fx_all_scalars_tab (
  id    number(10) constraint fx_all_scalars_tab_pk primary key,
  value fx_all_scalars
);

insert into fx_all_scalars_tab (id, value)
values (
  1,
  fx_all_scalars(
    'ab',
    unistr('\00e7'),
    unistr('h\00e9llo'),
    unistr('\65e5\672c'),
    hextoraw('00FF80'),
    12.5,
    0.1f,
    0.1d,
    true,
    to_date('2026-03-01 10:00:00', 'YYYY-MM-DD HH24:MI:SS'),
    timestamp '2026-03-01 10:00:00.123456789',
    timestamp '2026-03-01 10:00:00.123456789 +05:45',
    timestamp '2026-03-01 10:00:00.123456789 +00:00',
    interval '3 04:05:06.789' day to second,
    interval '2-5' year to month
  )
);
insert into fx_all_scalars_tab (id, value)
values (
  2,
  fx_all_scalars(null, null, null, null, null, null, null, null, null, null,
                 null, null, null, null, null)
);

-- A TSTZ attribute, to hold a value with a region name.
create or replace type fx_tstz_holder as object (moment timestamp with time zone);
/

-- Attribute types v1 does not map yet. JSON, VECTOR and LONG cannot be
-- attributes of an Object type.
create or replace type fx_lob_holder as object (
  id   number,
  doc  clob,
  ndoc nclob,
  bin  blob
);
/

create or replace type fx_xml_holder as object (id number, doc sys.xmltype);
/

create or replace type fx_ref_holder as object (id number, target ref fx_point);
/

create or replace type fx_bfile_holder as object (id number, f bfile);
/

-- Inheritance: a subtype instance in a column of its supertype.
create or replace type fx_animal as object (name varchar2(20)) not final;
/

create or replace type fx_dog under fx_animal (breed varchar2(20));
/

-- Row 1: a fx_animal. Row 2: a fx_dog.
create table fx_animals (
  id     number(10) constraint fx_animals_pk primary key,
  animal fx_animal
);

insert into fx_animals (id, animal) values (1, fx_animal('cat'));
insert into fx_animals (id, animal) values (2, fx_dog('rex', 'labrador'));

-- A quoted type name with a quoted, lowercase attribute.
create or replace type "FX_Mixed_Point" as object ("x" number, y number);
/

-- A synonym of fx_point.
create or replace synonym fx_point_alias for fx_point;

-- A Collection of Collections.
create or replace type fx_number_matrix as table of fx_number_list;
/

create or replace package fx_kinds as
  -- A PL/SQL record: an Object type in a package.
  type t_record is record (id pls_integer, name varchar2(20), flag boolean);

  -- A nested table declared in a package.
  type t_numbers is table of number;

  -- An Associative array of Objects.
  type t_point_map is table of fx_point index by pls_integer;

  -- An Associative array indexed by strings: not supported yet.
  type t_string_map is table of varchar2(20) index by varchar2(10);

  -- 1 .. 5 with elements 2 and 4 deleted: (1, 3, 5).
  function sparse_numbers return fx_number_list;

  -- 'a' => 'x', 'b' => 'y'.
  function string_map return t_string_map;

  -- Return what they are given.
  function echo_numbers(p_numbers in fx_number_list) return fx_number_list;
  function echo_map(p_map in fx_object.t_number_map) return fx_object.t_number_map;
end fx_kinds;
/

create or replace package body fx_kinds as
  function sparse_numbers return fx_number_list is
    l_numbers fx_number_list := fx_number_list(1, 2, 3, 4, 5);
  begin
    l_numbers.delete(2);
    l_numbers.delete(4);
    return l_numbers;
  end sparse_numbers;

  function string_map return t_string_map is
    l_map t_string_map;
  begin
    l_map('a') := 'x';
    l_map('b') := 'y';
    return l_map;
  end string_map;

  function echo_numbers(p_numbers in fx_number_list) return fx_number_list is
  begin
    return p_numbers;
  end echo_numbers;

  function echo_map(p_map in fx_object.t_number_map) return fx_object.t_number_map is
  begin
    return p_map;
  end echo_map;
end fx_kinds;
/
