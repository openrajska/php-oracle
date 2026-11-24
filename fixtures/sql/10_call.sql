-- Calls: procedures and functions called by name, with Core generating the
-- block in named notation.
create or replace package fx_call as
  -- IN, OUT and IN OUT in one Call. p_label has a default, so a Call that
  -- omits it gets 'default': o_label := p_label, o_doubled := p_num * 2,
  -- io_text := io_text || '!'.
  procedure echo(
    p_num     in     number,
    p_label   in     varchar2 default 'default',
    o_doubled out    number,
    o_label   out    varchar2,
    io_text   in out varchar2
  );

  -- Grows io_text to p_length bytes with 'a' (from NULL too).
  procedure grow(io_text in out varchar2, p_length in pls_integer);

  -- Raises ORA-20046 'fx_call.fail raised'; p_value is never read.
  procedure fail(p_value in varchar2);

  -- One function per scalar return type: a fixed value, or NULL when p_null
  -- is true. f_number echoes p_value instead.
  function f_varchar(p_null in boolean default false) return varchar2;
  function f_nvarchar(p_null in boolean default false) return nvarchar2;
  -- 'AB' in a CHAR(5): 'AB   '.
  function f_char(p_null in boolean default false) return char;
  function f_nchar(p_null in boolean default false) return nchar;
  function f_number(p_value in number) return number;
  function f_binary_float(p_null in boolean default false) return binary_float;
  function f_binary_double(p_null in boolean default false) return binary_double;
  function f_pls_integer(p_null in boolean default false) return pls_integer;
  function f_boolean(p_null in boolean default false) return boolean;
  function f_raw(p_null in boolean default false) return raw;
  -- The ROWID of fx_items row 1.
  function f_rowid(p_null in boolean default false) return rowid;
  -- 2026-03-01 10:00:00 (DATE), with .123456 (TIMESTAMP), with
  -- +05:45 (TSTZ); 13:00 UTC (TSLTZ); 3 days 04:05:06.789; 2 years 5 months.
  function f_date(p_null in boolean default false) return date;
  function f_timestamp(p_null in boolean default false) return timestamp;
  function f_timestamp_tz(p_null in boolean default false) return timestamp with time zone;
  function f_timestamp_ltz(p_null in boolean default false) return timestamp with local time zone;
  function f_interval_ds(p_null in boolean default false) return interval day to second;
  function f_interval_ym(p_null in boolean default false) return interval year to month;

  -- Raises ORA-20046 'fx_call.f_fail raised'; p_value is never read.
  function f_fail(p_value in varchar2) return number;
end fx_call;
/

create or replace package body fx_call as
  procedure echo(
    p_num     in     number,
    p_label   in     varchar2 default 'default',
    o_doubled out    number,
    o_label   out    varchar2,
    io_text   in out varchar2
  ) is
  begin
    o_doubled := p_num * 2;
    o_label := p_label;
    io_text := io_text || '!';
  end echo;

  procedure grow(io_text in out varchar2, p_length in pls_integer) is
  begin
    io_text := rpad(nvl(io_text, 'a'), p_length, 'a');
  end grow;

  procedure fail(p_value in varchar2) is
  begin
    raise_application_error(-20046, 'fx_call.fail raised');
  end fail;

  function f_varchar(p_null in boolean default false) return varchar2 is
  begin
    return case when p_null then null else 'text' end;
  end f_varchar;

  function f_nvarchar(p_null in boolean default false) return nvarchar2 is
  begin
    return case when p_null then null else unistr('A\00e7\00e3o \65e5\672c') end;
  end f_nvarchar;

  function f_char(p_null in boolean default false) return char is
    l_value char(5) := 'AB';
  begin
    return case when p_null then null else l_value end;
  end f_char;

  function f_nchar(p_null in boolean default false) return nchar is
    l_value nchar(5) := unistr('\00e7');
  begin
    return case when p_null then null else l_value end;
  end f_nchar;

  function f_number(p_value in number) return number is
  begin
    return p_value;
  end f_number;

  function f_binary_float(p_null in boolean default false) return binary_float is
  begin
    return case when p_null then null else 0.5f end;
  end f_binary_float;

  function f_binary_double(p_null in boolean default false) return binary_double is
  begin
    return case when p_null then null else 0.1d end;
  end f_binary_double;

  function f_pls_integer(p_null in boolean default false) return pls_integer is
  begin
    return case when p_null then null else -7 end;
  end f_pls_integer;

  function f_boolean(p_null in boolean default false) return boolean is
  begin
    return case when p_null then null else true end;
  end f_boolean;

  function f_raw(p_null in boolean default false) return raw is
  begin
    return case when p_null then null else hextoraw('00FF10') end;
  end f_raw;

  function f_rowid(p_null in boolean default false) return rowid is
    l_rowid rowid;
  begin
    if p_null then
      return null;
    end if;
    select rowid into l_rowid from fx_items where id = 1;
    return l_rowid;
  end f_rowid;

  function f_date(p_null in boolean default false) return date is
  begin
    return case when p_null then null else to_date('2026-03-01 10:00:00', 'YYYY-MM-DD HH24:MI:SS') end;
  end f_date;

  function f_timestamp(p_null in boolean default false) return timestamp is
  begin
    return case when p_null then null else timestamp '2026-03-01 10:00:00.123456' end;
  end f_timestamp;

  function f_timestamp_tz(p_null in boolean default false) return timestamp with time zone is
  begin
    return case when p_null then null else timestamp '2026-03-01 10:00:00.123456 +05:45' end;
  end f_timestamp_tz;

  function f_timestamp_ltz(p_null in boolean default false) return timestamp with local time zone is
  begin
    return case when p_null then null else timestamp '2026-03-01 13:00:00 +00:00' end;
  end f_timestamp_ltz;

  function f_interval_ds(p_null in boolean default false) return interval day to second is
  begin
    return case when p_null then null else interval '3 04:05:06.789' day to second end;
  end f_interval_ds;

  function f_interval_ym(p_null in boolean default false) return interval year to month is
  begin
    return case when p_null then null else interval '2-5' year to month end;
  end f_interval_ym;

  function f_fail(p_value in varchar2) return number is
  begin
    raise_application_error(-20046, 'fx_call.f_fail raised');
  end f_fail;
end fx_call;
/

-- A quoted mixed-case name, with a quoted mixed-case parameter: returns
-- "pValue" * 2.
create or replace function "FX_Mixed_Case"("pValue" in number) return number is
begin
  return "pValue" * 2;
end;
/
