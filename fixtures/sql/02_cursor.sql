-- REF CURSORs (OUT parameters, function return, strongly typed), a CURSOR()
-- column and Implicit results.
create table fx_categories (
  id   number(10)   constraint fx_categories_pk primary key,
  name varchar2(50) not null
);

create table fx_items (
  id          number(10)    constraint fx_items_pk primary key,
  category_id number(10)    not null
                            constraint fx_items_category_fk references fx_categories,
  name        varchar2(100) not null,
  price       number(10, 2) not null,
  created_at  date          not null
);

-- Multibyte names (written with UNISTR so the file stays ASCII) catch
-- character set bugs when rows are decoded.
insert into fx_categories (id, name) values (1, 'Plain');
insert into fx_categories (id, name) values (2, unistr('A\00e7\00e3o'));
insert into fx_categories (id, name) values (3, unistr('Caf\00e9'));
insert into fx_categories (id, name) values (4, unistr('Stra\00dfe'));
insert into fx_categories (id, name) values (5, unistr('\65e5\672c'));

-- 1000 rows, enough to cross the default prefetch and fetch array sizes.
-- Item n: category ((n - 1) mod 5) + 1, name 'Item 000n', price n * 1.25,
-- created_at 2026-01-01 00:00 + (n - 1) hours.
insert into fx_items (id, category_id, name, price, created_at)
select level,
       mod(level - 1, 5) + 1,
       'Item ' || lpad(level, 4, '0'),
       level * 1.25,
       date '2026-01-01' + (level - 1) / 24
  from dual
connect by level <= 1000;

create or replace package fx_cursor as
  type item_cursor is ref cursor return fx_items%rowtype;

  -- Several REF CURSOR OUT parameters, with different shapes, plus a scalar
  -- OUT in one Call.
  procedure two_cursors(
    p_limit      in  number,
    o_items      out sys_refcursor,
    o_categories out sys_refcursor,
    o_total      out number
  );

  -- A REF CURSOR as the function return value: items with id <= p_limit.
  function items(p_limit in number) return sys_refcursor;

  -- A strongly typed REF CURSOR OUT parameter.
  procedure typed_cursor(p_limit in number, o_items out item_cursor);

  -- Each category with a nested CURSOR() column of its items with id <= 20
  -- (4 per category).
  function categories_with_items return sys_refcursor;

  -- Two Implicit results through DBMS_SQL.RETURN_RESULT: items with
  -- id <= p_limit (id, name, price), then all categories (id, name).
  procedure implicit_results(p_limit in number);

  -- IN, IN OUT, two REF CURSOR OUTs, a scalar OUT and an IN OUT REF CURSOR
  -- in one Call, with no DATE column: items with id <= p_limit (id, name,
  -- price), all categories (id, name), the item count, and the names of
  -- categories 1 and 2. io_label gains a '!'.
  procedure report(
    p_limit      in     number,
    io_label     in out varchar2,
    o_items      out    sys_refcursor,
    o_categories out    sys_refcursor,
    o_total      out    number,
    io_names     in out sys_refcursor
  );
end fx_cursor;
/

create or replace package body fx_cursor as
  procedure two_cursors(
    p_limit      in  number,
    o_items      out sys_refcursor,
    o_categories out sys_refcursor,
    o_total      out number
  ) is
  begin
    open o_items for
      select id, name, price, created_at
        from fx_items
       where id <= p_limit
       order by id;

    open o_categories for
      select id, name
        from fx_categories
       order by id;

    select count(*) into o_total from fx_items;
  end two_cursors;

  function items(p_limit in number) return sys_refcursor is
    l_cursor sys_refcursor;
  begin
    open l_cursor for
      select id, category_id, name, price, created_at
        from fx_items
       where id <= p_limit
       order by id;
    return l_cursor;
  end items;

  procedure typed_cursor(p_limit in number, o_items out item_cursor) is
  begin
    open o_items for
      select *
        from fx_items
       where id <= p_limit
       order by id;
  end typed_cursor;

  function categories_with_items return sys_refcursor is
    l_cursor sys_refcursor;
  begin
    open l_cursor for
      select c.id,
             c.name,
             cursor(
               select i.id, i.name, i.price
                 from fx_items i
                where i.category_id = c.id
                  and i.id <= 20
                order by i.id
             ) as items
        from fx_categories c
       order by c.id;
    return l_cursor;
  end categories_with_items;

  procedure implicit_results(p_limit in number) is
    l_items      sys_refcursor;
    l_categories sys_refcursor;
  begin
    open l_items for
      select id, name, price
        from fx_items
       where id <= p_limit
       order by id;
    dbms_sql.return_result(l_items);

    open l_categories for
      select id, name
        from fx_categories
       order by id;
    dbms_sql.return_result(l_categories);
  end implicit_results;

  procedure report(
    p_limit      in     number,
    io_label     in out varchar2,
    o_items      out    sys_refcursor,
    o_categories out    sys_refcursor,
    o_total      out    number,
    io_names     in out sys_refcursor
  ) is
  begin
    io_label := io_label || '!';

    open o_items for
      select id, name, price
        from fx_items
       where id <= p_limit
       order by id;

    open o_categories for
      select id, name
        from fx_categories
       order by id;

    select count(*) into o_total from fx_items;

    open io_names for
      select name
        from fx_categories
       where id <= 2
       order by id;
  end report;
end fx_cursor;
/
