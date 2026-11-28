<?php

declare(strict_types = 1);

// Creates the benchmark's schema and the objects its cases read, so it runs
// against any Oracle with nothing installed beforehand. The admin user
// (ORACLE_ADMIN_USER / ORACLE_ADMIN_PASSWORD, default system / oracle)
// creates the user once, with read access to V$SESSTAT for layers.php; the
// objects are created afresh on every run.
//   php -d extension=<oracledb> schema.php
require __DIR__ . '/bootstrap.php';

use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Oracle;

$context = BenchContext::fromEnvironment();
$admin = Oracle::connect(new ConnectionConfig(
    dsn: $context->dsn,
    user: getenv('ORACLE_ADMIN_USER') ?: 'system',
    password: getenv('ORACLE_ADMIN_PASSWORD') ?: 'oracle',
    standalone: true,
));
if ($admin->select('select count(*) from dba_users where username = upper(:name)', ['name' => $context->user])->value() === 0) {
    $admin->run("create user {$context->user} identified by \"{$context->password}\" default tablespace users quota unlimited on users");
    $admin->run("grant db_developer_role, select any dictionary to {$context->user}");
}
$admin->close();

$bench = Oracle::connect($context->config(standalone: true));
foreach (['bench_rows', 'bench_lobs', 'bench_large_lob', 'bench_batch_rows', 'bench_items'] as $table) {
    $bench->run("begin execute immediate 'drop table $table purge'; exception when others then if sqlcode != -942 then raise; end if; end;");
}

// 10,000 rows, one column of each common type. Row n: see README.md.
$bench->run(<<<'SQL'
    create table bench_rows (
      id         number(10)    primary key,
      qty        number(10)    not null,
      amount     number(12, 2) not null,
      ratio      binary_double not null,
      code       varchar2(20)  not null,
      label      varchar2(60)  not null,
      note       varchar2(60),
      created_at date          not null,
      updated_at timestamp(6)  not null
    )
    SQL);
$bench->run(<<<'SQL'
    insert into bench_rows
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
    connect by level <= 10000
    SQL);

// 1,000 rows, each LOB under the 32767 a query brings with its row.
$bench->run('create table bench_lobs (id number(10) primary key, body clob not null, data blob not null)');
$bench->run(<<<'SQL'
    insert into bench_lobs
    select level,
           to_clob(rpad(to_char(unistr('A\00e7\00e3o caf\00e9 \65e5\672c ')) || lpad(level, 8, '0'), 1000, 'x')),
           to_blob(utl_raw.copies(hextoraw(to_char(mod(level, 256), 'FM0X')), 1000))
      from dual
    connect by level <= 1000
    SQL);

// One 16 MiB BLOB: the byte at 0-based offset k is k mod 256.
$bench->run('create table bench_large_lob (id number(10) primary key, binary_value blob)');
$bench->run(<<<'SQL'
    declare
      l_blob  blob;
      l_hex   varchar2(16384);
    begin
      insert into bench_large_lob values (1, empty_blob()) returning binary_value into l_blob;
      for k in 0 .. 8191 loop
        l_hex := l_hex || to_char(mod(k, 256), 'FM0X');
      end loop;
      for i in 1 .. 16 * 128 loop
        dbms_lob.writeappend(l_blob, 8192, hextoraw(l_hex));
      end loop;
    end;
    SQL);

$bench->run(<<<'SQL'
    create table bench_batch_rows (
      id   number(10)   primary key,
      code varchar2(10) not null unique,
      qty  number(10)   not null check (qty >= 0)
    )
    SQL);

// 1,000 items for the REF CURSOR case: id, category_id, name, price, created_at.
$bench->run(<<<'SQL'
    create table bench_items (
      id          number(10)    primary key,
      category_id number(10)    not null,
      name        varchar2(100) not null,
      price       number(10, 2) not null,
      created_at  date          not null
    )
    SQL);
$bench->run(<<<'SQL'
    insert into bench_items
    select level, mod(level - 1, 5) + 1, 'Item ' || lpad(level, 4, '0'), level * 1.25, date '2026-01-01' + (level - 1) / 24
      from dual
    connect by level <= 1000
    SQL);
$bench->run(<<<'SQL'
    create or replace function bench_items_cursor(p_limit in number) return sys_refcursor is
      l_cursor sys_refcursor;
    begin
      open l_cursor for
        select id, category_id, name, price, created_at from bench_items where id <= p_limit order by id;
      return l_cursor;
    end;
    SQL);

$bench->run(<<<'SQL'
    create or replace procedure bench_echo_out(
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
    end;
    SQL);
$bench->close();
echo "schema ok\n";
