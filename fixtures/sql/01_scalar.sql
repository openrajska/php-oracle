-- Scalar binds: OUT and IN OUT parameters (NUMBER, VARCHAR2, DATE) and
-- function return values. NULL in gives NULL out, so NULL handling is
-- probed with the same calls.
create or replace package fx_scalar as
  -- Copies each IN parameter to the matching OUT parameter.
  procedure echo_out(
    p_num  in  number,
    p_str  in  varchar2,
    p_date in  date,
    o_num  out number,
    o_str  out varchar2,
    o_date out date
  );

  -- num + 1, str || '!', date + 1 day.
  procedure bump(
    io_num  in out number,
    io_str  in out varchar2,
    io_date in out date
  );

  -- An OUT VARCHAR2 of p_length characters (up to 32767 bytes), to probe how
  -- the client sizes OUT buffers.
  procedure repeat_out(
    p_char   in  varchar2,
    p_length in  number,
    o_str    out varchar2
  );

  function add_numbers(p_a in number, p_b in number) return number;

  function greet(p_name in varchar2) return varchar2;

  function add_days(p_date in date, p_days in number) return date;
end fx_scalar;
/

create or replace package body fx_scalar as
  procedure echo_out(
    p_num  in  number,
    p_str  in  varchar2,
    p_date in  date,
    o_num  out number,
    o_str  out varchar2,
    o_date out date
  ) is
  begin
    o_num := p_num;
    o_str := p_str;
    o_date := p_date;
  end echo_out;

  procedure bump(
    io_num  in out number,
    io_str  in out varchar2,
    io_date in out date
  ) is
  begin
    io_num := io_num + 1;
    io_str := io_str || '!';
    io_date := io_date + 1;
  end bump;

  procedure repeat_out(
    p_char   in  varchar2,
    p_length in  number,
    o_str    out varchar2
  ) is
  begin
    o_str := rpad(p_char, p_length, p_char);
  end repeat_out;

  function add_numbers(p_a in number, p_b in number) return number is
  begin
    return p_a + p_b;
  end add_numbers;

  function greet(p_name in varchar2) return varchar2 is
  begin
    return 'Hello, ' || p_name || '!';
  end greet;

  function add_days(p_date in date, p_days in number) return date is
  begin
    return p_date + p_days;
  end add_days;
end fx_scalar;
/
