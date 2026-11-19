-- LOBs: small, empty, NULL and large (for streaming reads), plus functions
-- that measure a LOB the client streams in.
create table fx_lobs (
  id           number(10)   constraint fx_lobs_pk primary key,
  label        varchar2(50) not null,
  text_value   clob,
  binary_value blob
);

insert into fx_lobs (id, label, text_value, binary_value)
values (1, 'small', unistr('Hello, LOB! A\00e7\00e3o \65e5\672c'), hextoraw('DEADBEEF'));
insert into fx_lobs (id, label, text_value, binary_value)
values (2, 'empty', empty_clob(), empty_blob());
insert into fx_lobs (id, label, text_value, binary_value)
values (3, 'null', null, null);

create or replace package fx_lob as
  -- Replaces row p_id with a deterministic large CLOB and BLOB, so a client
  -- can check a streamed read without a stored checksum. Does not commit.
  --
  -- CLOB: p_megabytes * 16384 lines of 64 characters each, i.e.
  -- p_megabytes MiB of characters. Line n (from 1) is text_line(n):
  --   8-digit zero-padded n, ' ', U+0061 U+00E7 U+00E3 U+006F ' caf' U+00E9
  --   ' ' U+65E5 U+672C, ' ', 41 'x', LF
  -- (71 bytes in UTF-8).
  --
  -- BLOB: p_megabytes MiB where the byte at 0-based offset k is k mod 256.
  procedure generate(p_id in number, p_megabytes in number);

  function text_line(p_line in number) return varchar2;

  function text_length(p_text in clob) return number;

  function binary_length(p_binary in blob) return number;
end fx_lob;
/

create or replace package body fx_lob as
  c_lines_per_mib    constant pls_integer := 16384;
  -- 400 lines * 71 bytes stays under the 32767-byte VARCHAR2 limit.
  c_lines_per_append constant pls_integer := 400;
  -- 8192 bytes = 16384 hex digits, under the 32767-character limit.
  c_chunk_bytes      constant pls_integer := 8192;

  function text_line(p_line in number) return varchar2 is
  begin
    return lpad(p_line, 8, '0')
      || ' ' || unistr('a\00e7\00e3o caf\00e9 \65e5\672c') || ' '
      || rpad('x', 41, 'x')
      || chr(10);
  end text_line;

  procedure generate(p_id in number, p_megabytes in number) is
    l_text   clob;
    l_binary blob;
    l_buffer varchar2(32767);
    l_hex    varchar2(32767);
    l_chunk  raw(32767);
  begin
    delete from fx_lobs where id = p_id;
    insert into fx_lobs (id, label, text_value, binary_value)
    values (p_id, 'large ' || p_megabytes || ' MiB', empty_clob(), empty_blob())
    returning text_value, binary_value into l_text, l_binary;

    for i in 1 .. p_megabytes * c_lines_per_mib loop
      l_buffer := l_buffer || text_line(i);
      if mod(i, c_lines_per_append) = 0 then
        dbms_lob.writeappend(l_text, length(l_buffer), l_buffer);
        l_buffer := null;
      end if;
    end loop;
    if l_buffer is not null then
      dbms_lob.writeappend(l_text, length(l_buffer), l_buffer);
    end if;

    for k in 0 .. c_chunk_bytes - 1 loop
      l_hex := l_hex || to_char(mod(k, 256), 'FM0X');
    end loop;
    l_chunk := hextoraw(l_hex);
    for i in 1 .. p_megabytes * (1048576 / c_chunk_bytes) loop
      dbms_lob.writeappend(l_binary, c_chunk_bytes, l_chunk);
    end loop;
  end generate;

  function text_length(p_text in clob) return number is
  begin
    return dbms_lob.getlength(p_text);
  end text_length;

  function binary_length(p_binary in blob) return number is
  begin
    return dbms_lob.getlength(p_binary);
  end binary_length;
end fx_lob;
/

begin
  fx_lob.generate(4, 16);
end;
/
