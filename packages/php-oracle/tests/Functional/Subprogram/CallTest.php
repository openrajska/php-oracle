<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Subprogram\CallTest;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Override;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Subprogram\PendingFunction;
use Rajska\Oracle\Tests\Functional\TestCase;
use RuntimeException;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table call_rows (id number(10) primary key)');
    schema()->run('insert into call_rows values (1)');
    schema()->run('create type call_point as object (x number, y number, label varchar2(30))');
    schema()->run('create type call_point_list as table of call_point');
    schema()->run('create type call_polygon as object (name varchar2(50), vertices call_point_list)');
    schema()->run('create type call_number_list as table of number');
    schema()->run('create type call_string_varray as varray(10) of varchar2(100)');
    schema()->run(<<<'SQL'
        create package call_pkg as
            -- p_label defaults, so a Call may leave it out.
            procedure echo(
                p_num number,
                p_label varchar2 default 'default',
                o_doubled out number,
                o_label out varchar2,
                io_text in out varchar2
            );
            procedure grow(io_text in out varchar2, p_length pls_integer);
            procedure repeat_out(p_char varchar2, p_length number, o_str out varchar2);
            procedure fail(p_value varchar2);
            function f_fail(p_value varchar2) return number;
            function greet(p_name varchar2) return varchar2;
            function f_number(p_value number) return number;
            function f_varchar(p_null boolean default false) return varchar2;
            function f_nvarchar(p_null boolean default false) return nvarchar2;
            function f_char(p_null boolean default false) return char;
            function f_nchar(p_null boolean default false) return nchar;
            function f_binary_float(p_null boolean default false) return binary_float;
            function f_binary_double(p_null boolean default false) return binary_double;
            function f_pls_integer(p_null boolean default false) return pls_integer;
            function f_boolean(p_null boolean default false) return boolean;
            function f_raw(p_null boolean default false) return raw;
            function f_rowid(p_null boolean default false) return rowid;
            function f_date(p_null boolean default false) return date;
            function f_timestamp(p_null boolean default false) return timestamp;
            function f_timestamp_tz(p_null boolean default false) return timestamp with time zone;
            function f_timestamp_ltz(p_null boolean default false) return timestamp with local time zone;
            function f_interval_ds(p_null boolean default false) return interval day to second;
            function f_interval_ym(p_null boolean default false) return interval year to month;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body call_pkg as
            procedure echo(
                p_num number,
                p_label varchar2 default 'default',
                o_doubled out number,
                o_label out varchar2,
                io_text in out varchar2
            ) is
            begin
                o_doubled := p_num * 2;
                o_label := p_label;
                io_text := io_text || '!';
            end;

            procedure grow(io_text in out varchar2, p_length pls_integer) is
            begin
                io_text := rpad(nvl(io_text, 'a'), p_length, 'a');
            end;

            procedure repeat_out(p_char varchar2, p_length number, o_str out varchar2) is
            begin
                o_str := rpad(p_char, p_length, p_char);
            end;

            procedure fail(p_value varchar2) is
            begin
                raise_application_error(-20046, 'call_pkg.fail raised');
            end;

            function f_fail(p_value varchar2) return number is
            begin
                raise_application_error(-20046, 'call_pkg.f_fail raised');
            end;

            function greet(p_name varchar2) return varchar2 is
            begin
                return 'Hello, ' || p_name || '!';
            end;

            function f_number(p_value number) return number is
            begin
                return p_value;
            end;

            function f_varchar(p_null boolean default false) return varchar2 is
            begin
                return case when p_null then null else 'text' end;
            end;

            function f_nvarchar(p_null boolean default false) return nvarchar2 is
            begin
                return case when p_null then null else unistr('A\00e7\00e3o \65e5\672c') end;
            end;

            function f_char(p_null boolean default false) return char is
                l_value char(5) := 'AB';
            begin
                return case when p_null then null else l_value end;
            end;

            function f_nchar(p_null boolean default false) return nchar is
                l_value nchar(5) := unistr('\00e7');
            begin
                return case when p_null then null else l_value end;
            end;

            function f_binary_float(p_null boolean default false) return binary_float is
            begin
                return case when p_null then null else 0.5f end;
            end;

            function f_binary_double(p_null boolean default false) return binary_double is
            begin
                return case when p_null then null else 0.1d end;
            end;

            function f_pls_integer(p_null boolean default false) return pls_integer is
            begin
                return case when p_null then null else -7 end;
            end;

            function f_boolean(p_null boolean default false) return boolean is
            begin
                return case when p_null then null else true end;
            end;

            function f_raw(p_null boolean default false) return raw is
            begin
                return case when p_null then null else hextoraw('00FF10') end;
            end;

            function f_rowid(p_null boolean default false) return rowid is
                l_rowid rowid;
            begin
                if p_null then
                    return null;
                end if;
                select rowid into l_rowid from call_rows where id = 1;
                return l_rowid;
            end;

            function f_date(p_null boolean default false) return date is
            begin
                return case when p_null then null else to_date('2026-03-01 10:00:00', 'YYYY-MM-DD HH24:MI:SS') end;
            end;

            function f_timestamp(p_null boolean default false) return timestamp is
            begin
                return case when p_null then null else timestamp '2026-03-01 10:00:00.123456' end;
            end;

            function f_timestamp_tz(p_null boolean default false) return timestamp with time zone is
            begin
                return case when p_null then null else timestamp '2026-03-01 10:00:00.123456 +05:45' end;
            end;

            function f_timestamp_ltz(p_null boolean default false) return timestamp with local time zone is
            begin
                return case when p_null then null else timestamp '2026-03-01 13:00:00 +00:00' end;
            end;

            function f_interval_ds(p_null boolean default false) return interval day to second is
            begin
                return case when p_null then null else interval '3 04:05:06.789' day to second end;
            end;

            function f_interval_ym(p_null boolean default false) return interval year to month is
            begin
                return case when p_null then null else interval '2-5' year to month end;
            end;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create function "CALL_Mixed_Case"("pValue" number) return number is
        begin
            return "pValue" * 2;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package call_objects as
            type t_number_map is table of number index by pls_integer;
            function make_point(p_x number, p_y number, p_label varchar2) return call_point;
            function make_polygon(p_name varchar2, p_sides number) return call_polygon;
            function split_csv(p_csv varchar2) return call_string_varray;
            function sparse_numbers return call_number_list;
            function echo_numbers(p_numbers call_number_list) return call_number_list;
            function echo_map(p_map t_number_map) return t_number_map;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body call_objects as
            function make_point(p_x number, p_y number, p_label varchar2) return call_point is
            begin
                return call_point(p_x, p_y, p_label);
            end;

            -- p_sides vertices on the unit circle, labelled 'v1' .. 'vN'.
            function make_polygon(p_name varchar2, p_sides number) return call_polygon is
                l_vertices call_point_list := call_point_list();
                l_angle number;
            begin
                for i in 1 .. p_sides loop
                    l_angle := 2 * acos(-1) * (i - 1) / p_sides;
                    l_vertices.extend;
                    l_vertices(i) := call_point(round(cos(l_angle), 6), round(sin(l_angle), 6), 'v' || i);
                end loop;
                return call_polygon(p_name, l_vertices);
            end;

            function split_csv(p_csv varchar2) return call_string_varray is
                l_parts call_string_varray := call_string_varray();
            begin
                if p_csv is null then
                    return l_parts;
                end if;
                for i in 1 .. regexp_count(p_csv, ',') + 1 loop
                    l_parts.extend;
                    l_parts(i) := regexp_substr(p_csv, '([^,]*)(,|$)', 1, i, null, 1);
                end loop;
                return l_parts;
            end;

            -- 1 .. 5 with 2 and 4 deleted.
            function sparse_numbers return call_number_list is
                l_numbers call_number_list := call_number_list(1, 2, 3, 4, 5);
            begin
                l_numbers.delete(2);
                l_numbers.delete(4);
                return l_numbers;
            end;

            function echo_numbers(p_numbers call_number_list) return call_number_list is
            begin
                return p_numbers;
            end;

            function echo_map(p_map t_number_map) return t_number_map is
            begin
                return p_map;
            end;
        end;
        SQL);
    $this->connection = $this->connect();
    if ((int) $this->connection->serverVersion() < 23) {
        return;
    }
    schema()->run(<<<'SQL'
        create package call_json as
            function f_json(p_null boolean default false) return json;
            function f_vector(p_null boolean default false) return vector;
            function f_sparse return vector;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body call_json as
            function f_json(p_null boolean default false) return json is
            begin
                return case when p_null then null else json('{"a": [1, 2.5]}') end;
            end;

            function f_vector(p_null boolean default false) return vector is
            begin
                return case when p_null then null else to_vector('[1.5, 2]', 2, float32) end;
            end;

            function f_sparse return vector is
            begin
                return to_vector('[10, [3], [-1]]', 10, int8, sparse);
            end;
        end;
        SQL);
});

/**
 * A function, its binds, the terminal that reads it, what it returns, and
 * the options of the Call.
 *
 * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: Closure(PendingFunction): mixed, 3: mixed, 4?: Options}>
 */
dataset('terminals', function () {
    $float = new Options(values: new ValueMapping(numbers: NumberMode::Float));

    yield 'varchar' => ['call_pkg.f_varchar', [], static fn (PendingFunction $f): string | null => $f->varchar(), 'text'];
    yield 'nVarchar' => ['call_pkg.f_nvarchar', [], static fn (PendingFunction $f): string | null => $f->nVarchar(), 'Ação 日本'];
    yield 'char, blank padded' => ['call_pkg.f_char', [], static fn (PendingFunction $f): string | null => $f->char(), 'AB   '];
    yield 'nChar, blank padded' => ['call_pkg.f_nchar', [], static fn (PendingFunction $f): string | null => $f->nChar(), 'ç    '];
    yield 'binaryFloat' => ['call_pkg.f_binary_float', [], static fn (PendingFunction $f): float | null => $f->binaryFloat(), 0.5];
    yield 'binaryDouble' => ['call_pkg.f_binary_double', [], static fn (PendingFunction $f): float | null => $f->binaryDouble(), 0.1];
    yield 'plsInteger' => ['call_pkg.f_pls_integer', [], static fn (PendingFunction $f): int | null => $f->plsInteger(), -7];
    yield 'boolean' => ['call_pkg.f_boolean', [], static fn (PendingFunction $f): bool | null => $f->boolean(), true];
    yield 'raw' => ['call_pkg.f_raw', [], static fn (PendingFunction $f): string | null => $f->raw(), "\x00\xff\x10"];
    yield 'number, integral' => ['call_pkg.f_number', ['p_value' => 42], static fn (PendingFunction $f): float | int | string | null => $f->number(), 42];
    yield 'number, decimal' => ['call_pkg.f_number', ['p_value' => new In\Number('12.5')], static fn (PendingFunction $f): float | int | string | null => $f->number(), '12.5'];
    yield 'number, under NumberMode::Float' => ['call_pkg.f_number', ['p_value' => new In\Number('12.5')], static fn (PendingFunction $f): float | int | string | null => $f->number(), 12.5, $float];
    yield 'struct' => [
        'call_objects.make_point',
        ['p_x' => new In\Number('1.5'), 'p_y' => -2, 'p_label' => 'p'],
        static fn (PendingFunction $f): array | null => $f->struct('call_point'),
        ['X' => '1.5', 'Y' => -2, 'LABEL' => 'p'],
    ];
    yield 'struct with a collection attribute, lower keys' => [
        'call_objects.make_polygon',
        ['p_name' => 'tri', 'p_sides' => 1],
        static fn (PendingFunction $f): array | null => $f->struct('CALL_POLYGON'),
        ['name' => 'tri', 'vertices' => [['x' => 1, 'y' => 0, 'label' => 'v1']]],
        new Options(keyCase: KeyCase::Lower),
    ];
    yield 'collection, a VARRAY with nulls' => ['call_objects.split_csv', ['p_csv' => 'a,,b'], static fn (PendingFunction $f): array | null => $f->collection('call_string_varray'), ['a', null, 'b']];
    yield 'collection, empty' => ['call_objects.split_csv', ['p_csv' => null], static fn (PendingFunction $f): array | null => $f->collection('call_string_varray'), []];
    yield 'collection, a sparse nested table' => ['call_objects.sparse_numbers', [], static fn (PendingFunction $f): array | null => $f->collection('call_number_list'), [1, 3, 5]];
    yield 'indexed, keys in order' => [
        'call_objects.echo_map',
        ['p_map' => new In\Indexed('call_objects.t_number_map', [3 => 6, -1 => 5])],
        static fn (PendingFunction $f): array | null => $f->indexed('call_objects.t_number_map'),
        [-1 => 5, 3 => 6],
    ];
    yield 'indexed, a NULL comes in empty' => [
        'call_objects.echo_map',
        ['p_map' => new In\Indexed('call_objects.t_number_map', null)],
        static fn (PendingFunction $f): array | null => $f->indexed('call_objects.t_number_map'),
        [],
    ];
    yield 'json' => ['call_json.f_json', [], static fn (PendingFunction $f): mixed => $f->json(), ['a' => [1, '2.5']]];
    yield 'json, under NumberMode::Float' => ['call_json.f_json', [], static fn (PendingFunction $f): mixed => $f->json(), ['a' => [1, 2.5]], $float];
    yield 'vector' => ['call_json.f_vector', [], static fn (PendingFunction $f): SparseVector | array | null => $f->vector(), [1.5, 2.0]];
});

/**
 * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: Closure(PendingFunction): mixed}>
 */
dataset('nullTerminals', function () {
    yield 'varchar' => ['call_pkg.f_varchar', ['p_null' => true], static fn (PendingFunction $f): string | null => $f->varchar()];
    yield 'nVarchar' => ['call_pkg.f_nvarchar', ['p_null' => true], static fn (PendingFunction $f): string | null => $f->nVarchar()];
    yield 'char' => ['call_pkg.f_char', ['p_null' => true], static fn (PendingFunction $f): string | null => $f->char()];
    yield 'nChar' => ['call_pkg.f_nchar', ['p_null' => true], static fn (PendingFunction $f): string | null => $f->nChar()];
    yield 'binaryFloat' => ['call_pkg.f_binary_float', ['p_null' => true], static fn (PendingFunction $f): float | null => $f->binaryFloat()];
    yield 'binaryDouble' => ['call_pkg.f_binary_double', ['p_null' => true], static fn (PendingFunction $f): float | null => $f->binaryDouble()];
    yield 'plsInteger' => ['call_pkg.f_pls_integer', ['p_null' => true], static fn (PendingFunction $f): int | null => $f->plsInteger()];
    yield 'boolean' => ['call_pkg.f_boolean', ['p_null' => true], static fn (PendingFunction $f): bool | null => $f->boolean()];
    yield 'raw' => ['call_pkg.f_raw', ['p_null' => true], static fn (PendingFunction $f): string | null => $f->raw()];
    yield 'number' => ['call_pkg.f_number', ['p_value' => null], static fn (PendingFunction $f): float | int | string | null => $f->number()];
    yield 'rowid' => ['call_pkg.f_rowid', ['p_null' => true], static fn (PendingFunction $f): string | null => $f->rowid()];
    yield 'date' => ['call_pkg.f_date', ['p_null' => true], static fn (PendingFunction $f): DateTimeImmutable | string | null => $f->date()];
    yield 'timestamp' => ['call_pkg.f_timestamp', ['p_null' => true], static fn (PendingFunction $f): DateTimeImmutable | string | null => $f->timestamp()];
    yield 'timestampTz' => ['call_pkg.f_timestamp_tz', ['p_null' => true], static fn (PendingFunction $f): DateTimeImmutable | string | null => $f->timestampTz()];
    yield 'timestampLtz' => ['call_pkg.f_timestamp_ltz', ['p_null' => true], static fn (PendingFunction $f): DateTimeImmutable | string | null => $f->timestampLtz()];
    yield 'intervalDs' => ['call_pkg.f_interval_ds', ['p_null' => true], static fn (PendingFunction $f): DateInterval | null => $f->intervalDs()];
    yield 'intervalYm' => ['call_pkg.f_interval_ym', ['p_null' => true], static fn (PendingFunction $f): DateInterval | null => $f->intervalYm()];
    yield 'collection' => [
        'call_objects.echo_numbers',
        ['p_numbers' => new In\Collection('call_number_list', null)],
        static fn (PendingFunction $f): array | null => $f->collection('call_number_list'),
    ];
    yield 'json' => ['call_json.f_json', ['p_null' => true], static fn (PendingFunction $f): mixed => $f->json()];
    yield 'vector' => ['call_json.f_vector', ['p_null' => true], static fn (PendingFunction $f): SparseVector | array | null => $f->vector()];
});

/**
 * @return iterable<string, array{string, string, string}>
 */
dataset('temporalTerminals', function () {
    yield 'date' => ['date', 'call_pkg.f_date', '+2026-03-01 10:00:00.000000 America/Sao_Paulo'];
    yield 'timestamp' => ['timestamp', 'call_pkg.f_timestamp', '+2026-03-01 10:00:00.123456 America/Sao_Paulo'];
    yield 'timestampTz, its own offset' => ['timestampTz', 'call_pkg.f_timestamp_tz', '+2026-03-01 10:00:00.123456 +05:45'];
    yield "timestampLtz, in PHP's zone" => ['timestampLtz', 'call_pkg.f_timestamp_ltz', '+2026-03-01 10:00:00.000000 America/Sao_Paulo'];
    yield 'intervalDs' => ['intervalDs', 'call_pkg.f_interval_ds', '+0Y0M3DT4H5M6S.789000'];
    yield 'intervalYm' => ['intervalYm', 'call_pkg.f_interval_ym', '+2Y5M0DT0H0M0S.000000'];
});

/**
 * @return iterable<string, array{string, string, string}>
 */
dataset('temporalTexts', function () {
    yield 'date' => ['date', 'call_pkg.f_date', '2026-03-01 10:00:00'];
    yield 'timestamp' => ['timestamp', 'call_pkg.f_timestamp', '2026-03-01 10:00:00.123456000'];
    yield 'timestampTz' => ['timestampTz', 'call_pkg.f_timestamp_tz', '2026-03-01 10:00:00.123456000 +05:45'];
    yield 'timestampLtz' => ['timestampLtz', 'call_pkg.f_timestamp_ltz', '2026-03-01 10:00:00.000000000 -03:00'];
});

/**
 * @return iterable<string, array{array<string, mixed>, string}>
 */
dataset('largestVarchars', function () {
    yield 'an OUT of 32767 ASCII bytes' => [['p_char' => 'x', 'p_length' => 32767], str_repeat('x', 32767)];
    yield 'an OUT of 16383 two-byte characters' => [['p_char' => 'ç', 'p_length' => 16383], str_repeat('ç', 16383)];
});

/**
 * @return iterable<string, array{Closure(Connection): mixed, string, string}>
 */
dataset('failingCalls', function () {
    yield 'procedure()' => [
        static fn (Connection $c): Execution => $c->procedure('call_pkg.fail', ['p_value' => 'x']),
        'call_pkg.fail',
        'BEGIN call_pkg.fail(p_value => :b1); END;',
    ];
    yield 'function()' => [
        static fn (Connection $c): float | int | string | null => $c->function('call_pkg.f_fail', ['p_value' => 'x'])->number(),
        'call_pkg.f_fail',
        'BEGIN :b0 := call_pkg.f_fail(p_value => :b1); END;',
    ];
});

/**
 * @return iterable<string, array{Closure(Connection): mixed, string}>
 */
dataset('badBinds', function () {
    yield 'procedure()' => [static fn (Connection $c): Execution => $c->procedure('call_pkg.fail', ['P_Value' => new DateTimeImmutable()]), 'call_pkg.fail'];
    yield 'function()' => [static fn (Connection $c): float | int | string | null => $c->function('call_pkg.f_fail', ['P_Value' => new DateTimeImmutable()])->number(), 'call_pkg.f_fail'];
});

/**
 * A name under Oracle's naming rules; `{schema}` stands for the test's
 * schema.
 *
 * @return iterable<string, array{string, array<string, mixed>, int}>
 */
dataset('names', function () {
    yield 'quoted mixed case, with a quoted parameter' => ['"CALL_Mixed_Case"', ['"pValue"' => 21], 42];
    yield 'schema qualified, quoted' => ['{schema}."CALL_Mixed_Case"', ['"pValue"' => 4], 8];
    yield 'schema qualified, in a package' => ['{schema}.call_pkg.f_number', ['P_VALUE' => 3], 3];
    yield 'unquoted mixed case' => ['CALL_PKG.F_Number', ['p_value' => 7], 7];
});

/**
 * @return iterable<string, array{string, array<mixed>, string}>
 */
dataset('invalidNames', function () {
    $rule = 'an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes.';

    yield 'four parts' => ['a.b.c.d', [], "Call name 'a.b.c.d' has 4 parts; a name has at most 3: schema.package.name."];
    yield 'unterminated quote' => ['call_pkg."f_number', [], "Call name 'call_pkg.\"f_number' is not valid: it has an unterminated quote."];
    yield 'semicolon' => ['call_pkg.echo;call_pkg.fail', [], "Call name 'call_pkg.echo;call_pkg.fail' is not valid: each dot-separated part must be $rule"];
    yield 'whitespace' => ['call_pkg. echo', [], "Call name 'call_pkg. echo' is not valid"];
    yield 'comment' => ['call_pkg.echo--', [], "Call name 'call_pkg.echo--' is not valid"];
    yield 'empty' => ['', [], "Call name '' is not valid"];
    yield 'empty part' => ['call_pkg..echo', [], "Call name 'call_pkg..echo' is not valid"];
    yield 'trailing dot' => ['call_pkg.', [], "Call name 'call_pkg.' is not valid"];
    yield 'empty quotes' => ['""', [], "Call name '\"\"' is not valid"];
    yield 'leading digit' => ['1fx', [], "Call name '1fx' is not valid"];
    yield 'too long' => [str_repeat('a', 129), [], "is not valid: each dot-separated part must be $rule"];
    yield 'database link' => ['call_pkg.echo@remote', [], "Call name 'call_pkg.echo@remote' is not valid: each dot-separated part must be $rule Database links (@) are not supported."];
    yield 'parameter with a space' => ['call_pkg.echo', ['p num' => 1], "Parameter name 'p num' is not valid: a parameter name must be $rule"];
    yield 'parameter with a colon' => ['call_pkg.echo', [':p_num' => 1], "Parameter name ':p_num' is not valid"];
    yield 'parameter with a dot' => ['call_pkg.echo', ['fx.p_num' => 1], "Parameter name 'fx.p_num' is not valid: a parameter name must be $rule"];
    yield 'parameter breaking out' => ['call_pkg.echo', ['p_num => 1); call_pkg.fail(p_value => 1' => 1], "Parameter name 'p_num => 1); call_pkg.fail(p_value => 1' is not valid"];
    yield 'parameters by position' => ['call_pkg.echo', [1, 2], 'Call parameters are passed by name, but key 0 is an integer (is the array a list?); key every value by its parameter name.'];
    yield 'one parameter twice' => ['call_pkg.echo', ['p_num' => 1, 'P_NUM' => 2], "Parameters 'p_num' and 'P_NUM' name the same parameter; keep one of them."];
    yield 'one parameter quoted and not' => ['call_pkg.echo', ['p_num' => 1, '"P_NUM"' => 2], "Parameters 'p_num' and '\"P_NUM\"' name the same parameter"];
});

describe('procedures', function (): void {
    it('reads its out and in out parameters by name', function (): void {
        $execution = $this->connection->procedure('call_pkg.echo', [
            'p_num' => 21,
            'o_doubled' => new Out\Number(),
            'o_label' => new Out\Varchar(),
            'io_text' => new InOut\Varchar('hi'),
        ]);

        expect($execution->out('o_doubled')->int())->toBe(42)
            ->and($execution->out('o_label')->string())->toBe('default')
            ->and($execution->out('IO_TEXT')->string())->toBe('hi!');
    });

    it('gives a complete execution', function (): void {
        $execution = $this->connection->procedure('call_pkg.grow', ['io_text' => new InOut\Varchar('a'), 'p_length' => 3]);

        expect($execution->affected())->toBe(0)
            ->and($execution->warning())->toBeNull()
            ->and($execution->out('io_text')->string())->toBe('aaa')
            ->and(fn () => $execution->rows())->toThrow(function (UsageException $usageException): void {
                expect($usageException->getMessage())->toStartWith('The statement is not a query')
                    ->and($usageException->sql())->toBe('BEGIN call_pkg.grow(io_text => :b1, p_length => :b2); END;')
                    ->and($usageException->call())->toBe('call_pkg.grow');
            });
    });

    it('reads a varchar2 out value of the largest size', function (array $binds, string $expected): void {
        /** @var array<string, mixed> $binds */
        $execution = $this->connection->procedure('call_pkg.repeat_out', [...$binds, 'o_str' => new Out\Varchar()]);

        expect($execution->out('o_str')->string())->toBe($expected);
    })->with('largestVarchars');

    it('grows an in out varchar2 to the largest size', function (string | null $start): void {
        $execution = $this->connection->procedure('call_pkg.grow', ['io_text' => new InOut\Varchar($start), 'p_length' => 32767]);

        expect($execution->out('io_text')->string())->toBe(str_repeat('a', 32767));
    })->with(['from NULL' => [null], 'from a value' => ['a']]);

    it('lists the out binds of the call for an unknown name', function (): void {
        $execution = $this->connection->procedure('call_pkg.echo', ['p_num' => 1, 'P_Label' => 'x', 'o_doubled' => new Out\Number(), 'o_label' => new Out\Varchar(), 'io_text' => new InOut\Varchar(null)]);

        $read = fn () => $execution->out('nothing');

        expect($read)->toThrow(function (UsageException $usageException): void {
            expect($usageException->getMessage())->toBe("No OUT bind is named 'nothing'; they are 'o_doubled', 'o_label', 'io_text'.")
                ->and($usageException->sql())->toBe('BEGIN call_pkg.echo(p_num => :b1, P_Label => :b2, o_doubled => :b3, o_label => :b4, io_text => :b5); END;')
                ->and($usageException->call())->toBe('call_pkg.echo');
        });
    });
});

describe('function results', function (): void {
    it('returns the value mapped to the return type', function (string $function, array $binds, Closure $terminal, mixed $expected, Options | null $options = null): void {
        skipWithoutJson($this->connection->serverVersion(), $function);
        /** @var array<string, mixed> $binds */
        $pending = $this->connection->function($function, $binds, $options);

        $value = $terminal($pending);

        expect($value)->toBe($expected);
    })->with('terminals');

    it('returns null for null', function (string $function, array $binds, Closure $terminal): void {
        skipWithoutJson($this->connection->serverVersion(), $function);
        /** @var array<string, mixed> $binds */
        $pending = $this->connection->function($function, $binds);

        $value = $terminal($pending);

        expect($value)->toBeNull();
    })->with('nullTerminals');

    it('returns a sparse vector as a sparse vector', function (): void {
        skipWithoutJson($this->connection->serverVersion(), 'call_json');

        $vector = $this->connection->function('call_json.f_sparse')->vector();

        assert($vector instanceof SparseVector);
        expect([$vector->dimensions, $vector->values])->toBe([10, [3 => -1]]);
    });

    it('returns the rowid as text', function (): void {
        $rowid = $this->connection->select('select rowidtochar(rowid) from call_rows where id = 1')->value();

        $value = $this->connection->function('call_pkg.f_rowid')->rowid();

        expect($value)->toBe($rowid);
    });

    it('returns dates as php objects in the php zone', function (string $terminal, string $function, string $expected): void {
        $value = inTimeZone('America/Sao_Paulo', fn (): mixed => $this->connect()->function($function)->{$terminal}());

        expect(temporalText($value))->toBe($expected);
    })->with('temporalTerminals');

    it('returns dates as iso text under temporal mode string', function (string $terminal, string $function, string $expected): void {
        $options = new Options(values: new ValueMapping(temporals: TemporalMode::String));

        $value = inTimeZone('America/Sao_Paulo', fn (): mixed => $this->connect()->function($function, [], $options)->{$terminal}());

        expect($value)->toBe($expected);
    })->with('temporalTexts');

    it('fails naming the fix when read as the wrong kind of object', function (): void {
        $pending = $this->connection->function('call_objects.split_csv', ['p_csv' => 'a']);

        $read = fn () => $pending->struct('call_string_varray');

        expect($read)->toThrow(function (UsageException $usageException): void {
            expect($usageException->getMessage())->toStartWith('The return value of call_objects.split_csv: ')
                ->toEndWith('.CALL_STRING_VARRAY is a Collection type (nested table or VARRAY), not an Object type; use Collection, not Struct.')
                ->and($usageException->call())->toBe('call_objects.split_csv');
        });
    });
});

describe('call names', function (): void {
    it('resolves names under oracle naming rules', function (string $name, array $binds, int $expected): void {
        $name = str_replace('{schema}', $this->config()->user, $name);

        /** @var array<string, mixed> $binds */
        $value = $this->connection->function($name, $binds)->number();

        expect($value)->toBe($expected);
    })->with('names');

    it('fails before any round trip on an invalid name', function (string $name, array $binds, string $message, string $verb): void {
        $sid = $this->sid($this->connection);

        $trips = roundTrips($sid, function () use ($verb, $name, $binds, $message): void {
            expect(fn () => $this->connection->{$verb}($name, $binds))->toThrow(function (UsageException $usageException) use ($name, $message): void {
                expect($usageException->getMessage())->toContain($message)
                    ->and($usageException->call())->toBe($name)
                    ->and($usageException->sql())->toBeNull();
            });
        });

        expect($trips)->toBe(0);
    })->with('invalidNames')->with(['procedure', 'function']);
});

describe('the call cache', function (): void {
    it('binds the values of each repeated call', function (): void {
        $binds = ['o_doubled' => new Out\Number(), 'o_label' => new Out\Varchar(), 'io_text' => new InOut\Varchar('hi')];
        $this->connection->procedure('call_pkg.echo', ['p_num' => 21, ...$binds]);

        $execution = $this->connection->procedure('call_pkg.echo', ['p_num' => 5, ...$binds]);

        expect($execution->out('o_doubled')->int())->toBe(10);
    });

    it('refuses a name that collides with a cached call', function (): void {
        $binds = ['o_doubled' => new Out\Number(), 'o_label' => new Out\Varchar(), 'io_text' => new InOut\Varchar('hi')];
        $this->connection->procedure('call_pkg.echo', ['p_num' => 21, ...$binds]);

        $call = fn () => $this->connection->procedure("call_pkg.echo\0p_num", $binds);

        expect($call)->toThrow(UsageException::class, "Call name 'call_pkg.echo\0p_num' is not valid");
    });

    it('still runs a call after more call shapes than it keeps', function (): void {
        $this->connection->function('call_pkg.f_number', ['p_value' => 1])->number();
        foreach (range(1, 256) as $i) {
            $this->connection->function("never_called_$i");
        }

        $value = $this->connection->function('call_pkg.f_number', ['p_value' => 2])->number();

        expect($value)->toBe(2);
    });
});

describe('failing calls', function (): void {
    it('carries the call name and the generated block', function (Closure $call, string $name, string $sql): void {
        $failing = fn () => $call($this->connection);

        expect($failing)->toThrow(function (QueryException $queryException) use ($name, $sql): void {
            expect($queryException->getCode())->toBe(20046)
                ->and($queryException->getMessage())->toStartWith("ORA-20046: $name raised")
                ->and($queryException->sql())->toBe($sql)
                ->and($queryException->call())->toBe($name);
        });
    })->with('failingCalls');

    it('names the parameter and the call on a bind error', function (Closure $call, string $name): void {
        $failing = fn () => $call($this->connection);

        expect($failing)->toThrow(function (UsageException $usageException) use ($name): void {
            expect($usageException->getMessage())->toBe("Bind 'P_Value': a DateTimeInterface does not bind by itself; use In\\Date or In\\Timestamp.")
                ->and($usageException->call())->toBe($name);
        });
    })->with('badBinds');

    it('passes the exception of another backend to the caller as it was thrown', function (): void {
        $failure = new class ('the backend failed') extends RuntimeException implements OracleException {
            #[Override]
            public function sql(): string | null
            {
                return null;
            }

            #[Override]
            public function call(): string | null
            {
                return null;
            }
        };
        $driver = $this->createStub(Session::class);
        $driver->method('execute')->willThrowException($failure);
        $connection = new Connection($driver, $this->config());

        $call = fn (): Execution => $connection->procedure('any_procedure');

        expect($call)->toThrow(function (RuntimeException $exception) use ($failure): void {
            expect($exception)->toBe($failure);
        });
    });
});

/**
 * JSON and VECTOR need Oracle Database 23ai or later.
 */
function skipWithoutJson(string $serverVersion, string $function): void
{
    if (str_starts_with($function, 'call_json') && (int) $serverVersion < 23) {
        test()->markTestSkipped('JSON and VECTOR need Oracle Database 23ai or later.');
    }
}
