<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Values\ObjectTest;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Rajska\Oracle\Binds\Bindable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Cursor as CursorContract;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\DataType\Type;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

const ECHO_BLOCK = 'begin :o := :i; end;';

const INSERT_SHAPE = 'insert into obj_shapes (id, center, tags, outline) values (:id, :center, :tags, :outline)';

beforeEach(function (): void {
    schema()->run('create type obj_point as object (x number, y number, label varchar2(30))');
    schema()->run('create type obj_point_list as table of obj_point');
    schema()->run('create type obj_number_list as table of number');
    schema()->run('create type obj_number_matrix as table of obj_number_list');
    schema()->run('create type obj_string_varray as varray(10) of varchar2(100)');
    schema()->run('create type obj_polygon as object (name varchar2(50), vertices obj_point_list)');
    schema()->run('create type "OBJ_Mixed_Point" as object ("x" number, y number)');
    schema()->run(<<<'SQL'
        create type obj_all_scalars as object (
            c_char char(5),
            c_nchar nchar(5),
            c_varchar varchar2(20),
            c_nvarchar nvarchar2(20),
            c_raw raw(10),
            c_number number,
            c_float binary_float,
            c_double binary_double,
            c_boolean boolean,
            c_date date,
            c_ts timestamp(9),
            c_tstz timestamp(9) with time zone,
            c_tsltz timestamp(9) with local time zone,
            c_ids interval day(9) to second(9),
            c_iym interval year(9) to month
        )
        SQL);
    schema()->run('create type obj_tstz_holder as object (moment timestamp with time zone)');
    schema()->run('create type obj_lob_holder as object (id number, doc clob, ndoc nclob, bin blob)');
    schema()->run('create type obj_xml_holder as object (id number, doc sys.xmltype)');
    schema()->run('create type obj_ref_holder as object (id number, target ref obj_point)');
    schema()->run('create type obj_bfile_holder as object (id number, f bfile)');
    schema()->run('create type obj_animal as object (name varchar2(20)) not final');
    schema()->run('create type obj_dog under obj_animal (breed varchar2(20))');
    schema()->run('create synonym obj_point_alias for obj_point');

    schema()->run(<<<'SQL'
        create table obj_shapes (id number(10) primary key, center obj_point, tags obj_string_varray, outline obj_point_list)
            nested table outline store as obj_shapes_outline
        SQL);
    schema()->run(<<<'SQL'
        insert into obj_shapes values (
            1,
            obj_point(1.5, -2, 'center'),
            obj_string_varray('red', unistr('caf\00e9')),
            obj_point_list(obj_point(0, 0, 'a'), obj_point(1, 0, 'b'), obj_point(0, 1, null))
        )
        SQL);
    schema()->run('insert into obj_shapes values (2, null, null, null)');
    schema()->run('insert into obj_shapes values (3, obj_point(null, null, null), obj_string_varray(), obj_point_list())');
    schema()->run('create table obj_all_scalars_tab (id number(10) primary key, value obj_all_scalars)');
    schema()->run(<<<'SQL'
        insert into obj_all_scalars_tab values (
            1,
            obj_all_scalars(
                'ab', unistr('\00e7'), unistr('h\00e9llo'), unistr('\65e5\672c'), hextoraw('00FF80'),
                12.5, 0.1f, 0.1d, true,
                to_date('2026-03-01 10:00:00', 'YYYY-MM-DD HH24:MI:SS'),
                timestamp '2026-03-01 10:00:00.123456789',
                timestamp '2026-03-01 10:00:00.123456789 +05:45',
                timestamp '2026-03-01 10:00:00.123456789 +00:00',
                interval '3 04:05:06.789' day to second,
                interval '2-5' year to month
            )
        )
        SQL);
    schema()->run('insert into obj_all_scalars_tab values (2, obj_all_scalars(null, null, null, null, null, null, null, null, null, null, null, null, null, null, null))');
    schema()->run('create table obj_animals (id number(10) primary key, animal obj_animal)');
    schema()->run("insert into obj_animals values (1, obj_animal('cat'))");
    schema()->run("insert into obj_animals values (2, obj_dog('rex', 'labrador'))");

    schema()->run(<<<'SQL'
        create package obj_pkg as
            type t_number_map is table of number index by pls_integer;
            function make_point(p_x number, p_y number, p_label varchar2) return obj_point;
            procedure move_point(io_point in out obj_point, p_dx number, p_dy number);
            function count_vertices(p_polygon obj_polygon) return number;
            function sum_numbers(p_numbers obj_number_list) return number;
            procedure reverse_numbers(p_numbers obj_number_list, o_reversed out obj_number_list);
            procedure append_tag(io_tags in out obj_string_varray, p_tag varchar2);
            function sum_map(p_map t_number_map) return number;
            procedure fill_map(p_count number, o_map out t_number_map);
            function points(p_count number) return obj_point_list pipelined;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body obj_pkg as
            function make_point(p_x number, p_y number, p_label varchar2) return obj_point is
            begin
                return obj_point(p_x, p_y, p_label);
            end;

            procedure move_point(io_point in out obj_point, p_dx number, p_dy number) is
            begin
                io_point.x := io_point.x + p_dx;
                io_point.y := io_point.y + p_dy;
            end;

            function count_vertices(p_polygon obj_polygon) return number is
            begin
                return p_polygon.vertices.count;
            end;

            -- NULL for a NULL Collection, 0 for an empty one.
            function sum_numbers(p_numbers obj_number_list) return number is
                l_sum number := 0;
            begin
                if p_numbers is null then
                    return null;
                end if;
                for i in 1 .. p_numbers.count loop
                    l_sum := l_sum + p_numbers(i);
                end loop;
                return l_sum;
            end;

            procedure reverse_numbers(p_numbers obj_number_list, o_reversed out obj_number_list) is
            begin
                if p_numbers is null then
                    return;
                end if;
                o_reversed := obj_number_list();
                o_reversed.extend(p_numbers.count);
                for i in 1 .. p_numbers.count loop
                    o_reversed(i) := p_numbers(p_numbers.count - i + 1);
                end loop;
            end;

            procedure append_tag(io_tags in out obj_string_varray, p_tag varchar2) is
            begin
                io_tags.extend;
                io_tags(io_tags.count) := p_tag;
            end;

            function sum_map(p_map t_number_map) return number is
                l_sum number := 0;
                l_key pls_integer := p_map.first;
            begin
                while l_key is not null loop
                    l_sum := l_sum + p_map(l_key);
                    l_key := p_map.next(l_key);
                end loop;
                return l_sum;
            end;

            -- Sparse keys 10, 20, ... holding 1, 2, ...
            procedure fill_map(p_count number, o_map out t_number_map) is
            begin
                for i in 1 .. p_count loop
                    o_map(i * 10) := i;
                end loop;
            end;

            function points(p_count number) return obj_point_list pipelined is
            begin
                for i in 1 .. p_count loop
                    pipe row (obj_point(i, i * i, 'P' || i));
                end loop;
                return;
            end;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package obj_kinds as
            type t_record is record (id pls_integer, name varchar2(20), flag boolean);
            type t_numbers is table of number;
            type t_point_map is table of obj_point index by pls_integer;
            type t_string_map is table of varchar2(20) index by varchar2(10);
            function sparse_numbers return obj_number_list;
            function string_map return t_string_map;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body obj_kinds as
            -- 1 .. 5 with 2 and 4 deleted.
            function sparse_numbers return obj_number_list is
                l_numbers obj_number_list := obj_number_list(1, 2, 3, 4, 5);
            begin
                l_numbers.delete(2);
                l_numbers.delete(4);
                return l_numbers;
            end;

            function string_map return t_string_map is
                l_map t_string_map;
            begin
                l_map('a') := 'x';
                l_map('b') := 'y';
                return l_map;
            end;
        end;
        SQL);
});

/**
 * @return iterable<string, array{Bindable, string}>
 */
dataset('mistakes', function () {
    $point = ['x' => 1, 'y' => 2, 'label' => null];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'a Collection that is not a list' => [new In\Collection('obj_number_list', [1 => 1, 0 => 2]), "Bind 'i': a Collection is a list (keys 0, 1, 2… in order), but key 1 is not; pass array_values() of it, or bind an Associative array with In\Indexed."];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'a Collection with string keys' => [new In\Collection('obj_number_list', ['a' => 1]), "Bind 'i': a Collection is a list (keys 0, 1, 2… in order), but key 'a' is not;"];
    yield 'a nested Collection that is not a list' => [new In\Struct('obj_polygon', ['name' => 'p', 'vertices' => [2 => $point]]), "Bind 'i', attribute VERTICES: a Collection is a list"];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'an Indexed string key' => [new In\Indexed('obj_pkg.t_number_map', ['a' => 1]), "Bind 'i': an Associative array has int keys, but key 'a' is a string; INDEX BY VARCHAR2 is not supported yet."];
    yield 'an Indexed key beyond 32 bits' => [new In\Indexed('obj_pkg.t_number_map', [2147483648 => 1]), "Bind 'i': key 2147483648 is outside the 32-bit range of an Associative array's keys (INDEX BY PLS_INTEGER)."];
    yield 'an unknown Struct key' => [new In\Struct('obj_point', $point + ['z' => 1]), "Bind 'i': " . schemaPlaceholder() . ".OBJ_POINT has no attribute 'z'; its attributes are X, Y, LABEL."];
    yield 'a missing Struct key' => [new In\Struct('obj_point', ['x' => 1]), "Bind 'i': the value has no key for attributes Y, LABEL of " . schemaPlaceholder() . '.OBJ_POINT; give every attribute, null for a NULL one.'];
    yield 'Struct keys differing only in case' => [new In\Struct('obj_point', $point + ['X' => 1]), "Bind 'i': keys 'x' and 'X' differ only in case and name the same attribute; keep one of them."];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'a list as a Struct' => [new In\Struct('obj_point', [1, 2, null]), "Bind 'i': an Object is keyed by attribute name, but key 0 is an int (is the array a list?)."];
    yield 'Struct of a Collection type' => [new In\Struct('obj_number_list', ['x' => 1]), "Bind 'i': " . schemaPlaceholder() . '.OBJ_NUMBER_LIST is a Collection type (nested table or VARRAY), not an Object type; use Collection, not Struct.'];
    yield 'Collection of an Object type' => [new In\Collection('obj_point', []), "Bind 'i': " . schemaPlaceholder() . '.OBJ_POINT is an Object type, not a Collection type (nested table or VARRAY); use Struct, not Collection.'];
    yield 'Collection of an Associative array type' => [new In\Collection('obj_pkg.t_number_map', []), "Bind 'i': " . schemaPlaceholder() . '.OBJ_PKG.T_NUMBER_MAP is an Associative array type (INDEX BY PLS_INTEGER), not a Collection type (nested table or VARRAY); use Indexed, not Collection.'];
    yield 'Indexed of a nested table type' => [new In\Indexed('obj_number_list', []), "Bind 'i': " . schemaPlaceholder() . '.OBJ_NUMBER_LIST is a Collection type (nested table or VARRAY), not an Associative array type (INDEX BY PLS_INTEGER); use Collection, not Indexed.'];
    yield 'an invalid type name' => [new In\Struct('fx point', $point), "Bind 'i': the type name 'fx point' is not valid: each dot-separated part must be an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes."];
    yield 'a type name with four parts' => [new In\Struct('a.b.c.d', $point), "Bind 'i': the type name 'a.b.c.d' has 4 parts; a type name has at most 3: schema.package.type."];
    yield 'text for a NUMBER' => [new In\Struct('obj_point', ['x' => 'one'] + $point), "Bind 'i', attribute X: a NUMBER takes a decimal literal with '.', such as '12.30', as text."];
    yield 'an array for a NUMBER' => [new In\Struct('obj_point', ['x' => []] + $point), "Bind 'i', attribute X: a NUMBER holds an int, a float or a decimal string or null, not array."];
    yield 'NaN for a NUMBER' => [new In\Collection('obj_number_list', [\NAN]), "Bind 'i', element 0: this float is outside NUMBER's range (NaN, INF, or beyond 1e-130..1e126)."];
    yield 'a string too long' => [new In\Struct('obj_point', ['label' => str_repeat('x', 31)] + $point), "Bind 'i': cannot store a value of 31 bytes (the maximum is 30) in attribute LABEL of type VARCHAR2(30)"];
    yield 'an int for a VARCHAR2 element' => [new In\Collection('obj_string_varray', [1]), "Bind 'i', element 0: a VARCHAR2(100) holds a string or null, not int."];
    yield 'a string for a timestamp' => [new In\Struct('obj_all_scalars', ['C_DATE' => 'today'] + allScalars()), "Bind 'i', attribute C_DATE: the text is not a DATE as TemporalMode::String writes it, such as '2026-03-01 10:00:00'."];
    yield 'a nested Object of the wrong shape' => [new In\Struct('obj_polygon', ['name' => 'p', 'vertices' => [1]]), "Bind 'i', attribute VERTICES, element 0: the value must be an array keyed by attribute name or null."];
});

describe('reading objects', function (): void {
    it('gives object and collection columns as arrays', function (): void {
        $connection = $this->connect();

        $rows = $this->rows($connection, 'select * from obj_shapes order by id');

        expect($rows)->toBe(shapes());
    });

    it('gives the same arrays from the columns of a cursor', function (string $kind): void {
        $connection = $this->connect();
        $query = 'select * from obj_shapes order by id';
        $cursor = $kind === 'REF CURSOR'
            ? $connection->execute("begin open :o for $query; end;", ['o' => new Out\Cursor()], $this->options())->outValues()['o'][0]
            : $connection->execute("declare c sys_refcursor; begin open c for $query; dbms_sql.return_result(c); end;", [], $this->options())->implicitResults()[0];
        assert($cursor instanceof CursorContract);

        $rows = drain($cursor);

        expect($rows)->toBe(shapes());
    })->with(['REF CURSOR', 'Implicit result']);

    it('gives the objects of a pipelined function as arrays', function (): void {
        $connection = $this->connect();

        $rows = $this->rows($connection, 'select value(t) as p from table(obj_pkg.points(2)) t');

        expect($rows)->toBe([
            ['P' => ['X' => 1, 'Y' => 1, 'LABEL' => 'P1']],
            ['P' => ['X' => 2, 'Y' => 4, 'LABEL' => 'P2']],
        ]);
    });

    it('describes object and collection columns with their type', function (): void {
        $connection = $this->connect();
        $schema = $this->value($connection, "select sys_context('userenv', 'current_schema') from dual");
        assert(\is_string($schema));
        $cursor = $connection->execute('select center, tags, outline from obj_shapes', [], $this->options())->cursor();
        assert($cursor !== null);

        $columns = array_map(static fn (array $column): array => [$column['type'], $column['objectType']], $cursor->columns());
        $cursor->close();

        expect($columns)->toBe([
            [Type::Object->value, "$schema.OBJ_POINT"],
            [Type::Collection->value, "$schema.OBJ_STRING_VARRAY"],
            [Type::Collection->value, "$schema.OBJ_POINT_LIST"],
        ]);
    });
});

describe('attributes', function (): void {
    it('follow the key case in their keys', function (): void {
        $connection = $this->connect();

        $rows = $this->rows(
            $connection,
            "select center, obj_point_list(obj_point(0, 0, 'a')) from obj_shapes where id = 1",
            options: $this->options(rowShape: RowShape::List, keyCase: KeyCase::Lower),
        );

        expect($rows)->toBe([[['x' => '1.5', 'y' => -2, 'label' => 'center'], [['x' => 0, 'y' => 0, 'label' => 'a']]]]);
    });

    it('keep the case of a quoted name', function (): void {
        $connection = $this->connect();

        $rows = $this->rows($connection, 'select "OBJ_Mixed_Point"(1, 2) as p from dual');

        expect($rows)->toBe([['P' => ['x' => 1, 'Y' => 2]]]);
    });

    it('map like columns under the default mapping', function (): void {
        $connection = $this->connect();

        $rows = inTimeZone('America/Sao_Paulo', fn (): array => $this->rows($connection, 'select value from obj_all_scalars_tab order by id'));

        $zone = 'America/Sao_Paulo';
        expect(shown(struct($rows[0]['VALUE'])))->toBe([
            'C_CHAR' => 'ab   ',
            'C_NCHAR' => 'ç    ',
            'C_VARCHAR' => 'héllo',
            'C_NVARCHAR' => '日本',
            'C_RAW' => "\x00\xff\x80",
            'C_NUMBER' => '12.5',
            'C_FLOAT' => 0.1,
            'C_DOUBLE' => 0.1,
            'C_BOOLEAN' => true,
            'C_DATE' => "+2026-03-01 10:00:00.000000 $zone",
            'C_TS' => "+2026-03-01 10:00:00.123456 $zone",
            'C_TSTZ' => '+2026-03-01 10:00:00.123456 +05:45',
            // Stored at UTC: 07:00 in São Paulo.
            'C_TSLTZ' => "+2026-03-01 07:00:00.123456 $zone",
            'C_IDS' => '+0Y0M3DT4H5M6S.789000',
            'C_IYM' => '+2Y5M0DT0H0M0S.000000',
        ])
            ->and($rows[1]['VALUE'])->toBe(allScalars());
    });

    it('follow the float mapping for numbers', function (): void {
        $connection = $this->connect();

        $value = struct($this->rows($connection, 'select value from obj_all_scalars_tab where id = 1', options: $this->options(values: new ValueMapping(numbers: NumberMode::Float)))[0]['VALUE']);

        expect($value['C_NUMBER'])->toBe(12.5);
    });

    it('follow the string mapping for temporals and keep intervals as objects', function (): void {
        $connection = $this->connect();

        $value = struct(inTimeZone('America/Sao_Paulo', fn (): mixed => $this->rows($connection, 'select value from obj_all_scalars_tab where id = 1', options: $this->options(values: new ValueMapping(temporals: TemporalMode::String)))[0]['VALUE']));

        expect([$value['C_DATE'], $value['C_TS'], $value['C_TSTZ'], $value['C_TSLTZ']])->toBe(['2026-03-01 10:00:00', '2026-03-01 10:00:00.123456789', '2026-03-01 10:00:00.123456789 +05:45', '2026-03-01 07:00:00.123456789 -03:00'])
            ->and($value['C_IDS'])->toBeInstanceOf(DateInterval::class);
    });

    it('follow the lower key case in their keys', function (): void {
        $connection = $this->connect();

        $rows = $this->rows($connection, 'select value from obj_all_scalars_tab where id = 1', options: $this->options(keyCase: KeyCase::Lower));

        expect(array_keys(struct($rows[0]['value'])))->toBe(array_map(strtolower(...), array_keys(allScalars())));
    });

    it('read a boolean as stored', function (string $flag, bool | null $expected, string $through): void {
        $connection = $this->connect();
        $built = "obj_all_scalars(null, null, null, null, null, null, null, null, $flag, null, null, null, null, null, null)";

        $value = $through === 'PL/SQL'
            ? $connection->execute("begin :o := $built; end;", ['o' => new Out\Struct('obj_all_scalars')], $this->options())->outValues()['o'][0]
            : $this->value($connection, "select $built from dual");

        expect(struct($value)['C_BOOLEAN'])->toBe($expected);
    })->with([
        'true' => ['true', true],
        'false' => ['false', false],
        'null' => ['null', null],
    ])->with(['PL/SQL', 'SQL']);
});

describe('binding objects', function (): void {
    it('binds a struct in out and in out', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'begin :o := obj_pkg.make_point(:x, :y, :label); obj_pkg.move_point(:io, 1, 2); :n := obj_pkg.count_vertices(:polygon); end;',
            [
                'x' => 1, 'y' => '0.5', 'label' => 'p',
                'o' => new Out\Struct('obj_point'),
                'io' => new InOut\Struct('obj_point', ['x' => 1, 'y' => 1, 'label' => 'moved']),
                'polygon' => new In\Struct('obj_polygon', ['name' => 'tri', 'vertices' => [point(0, 0), point(1, 0), point(0, 1)]]),
                'n' => new Out\Number(),
            ],
            $this->options(),
        )->outValues();

        expect($values)->toBe([
            'o' => [['X' => 1, 'Y' => '0.5', 'LABEL' => 'p']],
            'io' => [['X' => 2, 'Y' => 3, 'LABEL' => 'moved']],
            'n' => [3],
        ]);
    });

    it('binds a collection in out and in out', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            "begin obj_pkg.reverse_numbers(:numbers, :reversed); obj_pkg.append_tag(:tags, 'z'); :sum := obj_pkg.sum_numbers(:numbers); end;",
            [
                'numbers' => new In\Collection('obj_number_list', [1, 2.5, -3]),
                'reversed' => new Out\Collection('obj_number_list'),
                'tags' => new InOut\Collection('obj_string_varray', ['x', 'y']),
                'sum' => new Out\Number(),
            ],
            $this->options(),
        )->outValues();

        expect([$values['reversed'], $values['tags'], $values['sum']])->toBe([[[-3, '2.5', 1]], [['x', 'y', 'z']], ['0.5']]);
    });

    it('binds an associative array in out and in out', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'begin obj_pkg.fill_map(3, :filled); :sum := obj_pkg.sum_map(:map); :io := :io; end;',
            [
                'filled' => new Out\Indexed('obj_pkg.t_number_map'),
                'map' => new In\Indexed('obj_pkg.t_number_map', [7 => 1, -2 => 2]),
                'io' => new InOut\Indexed('obj_pkg.t_number_map', [1 => 1]),
                'sum' => new Out\Number(),
            ],
            $this->options(),
        )->outValues();

        expect([$values['filled'], $values['sum'], $values['io']])->toBe([[[10 => 1, 20 => 2, 30 => 3]], [3], [[1 => 1]]]);
    });

    it('binds a struct and collections into sql', function (): void {
        $connection = $this->connect();
        $options = $this->options(autocommit: false);

        $inserted = $connection->execute(INSERT_SHAPE, newShape(), $options)->rowCount();
        $rows = $this->rows($connection, 'select center, tags, outline from obj_shapes where id = 99', options: $options);
        $connection->rollback();

        expect($inserted)->toBe(1)
            ->and($rows)->toBe([['CENTER' => ['X' => 5, 'Y' => 6, 'LABEL' => 'new'], 'TAGS' => ['t'], 'OUTLINE' => []]]);
    });

    it('gives back the struct and collection an insert returns', function (string $sql): void {
        $connection = $this->connect();

        $values = $connection->execute(
            $sql,
            newShape() + ['center_o' => new Out\Struct('obj_point'), 'tags_o' => new Out\Collection('obj_string_varray')],
            $this->options(autocommit: false),
        )->outValues();
        $connection->rollback();

        expect([$values['center_o'], $values['tags_o']])->toBe([[['X' => 5, 'Y' => 6, 'LABEL' => 'new']], [['t']]]);
    })->with([
        'SQL' => [INSERT_SHAPE . ' returning center, tags into :center_o, :tags_o'],
        'a block' => ['begin ' . INSERT_SHAPE . ' returning center, tags into :center_o, :tags_o; end;'],
    ]);

    it('gives back the objects of every row an update returns', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'update obj_shapes set id = id where id in (1, 2, 3) returning id, center, tags into :id_o, :center_o, :tags_o',
            ['id_o' => new Out\Number(), 'center_o' => new Out\Struct('obj_point'), 'tags_o' => new Out\Collection('obj_string_varray')],
            $this->options(autocommit: false),
        )->outValues();
        $connection->rollback();

        $rows = array_map(null, $values['id_o'], $values['center_o'], $values['tags_o']);
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        expect($rows)->toBe([
            [1, ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'], ['red', "caf\u{e9}"]],
            [2, null, null],
            [3, ['X' => null, 'Y' => null, 'LABEL' => null], []],
        ]);
    });

    it('fails loudly returning a nested table column and the connection stays usable', function (string $sql): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute($sql, ['outline_o' => new Out\Collection('obj_point_list')], $this->options(autocommit: false));
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }
        $connection->rollback();

        // It comes back as a reference to its storage table, not decoded.
        assert($thrown instanceof UnsupportedException);
        expect($thrown->getMessage())->toContain('database object stored in a LOB')
            ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
    })->with([
        'SQL' => ['update obj_shapes set id = id where id = 1 returning outline into :outline_o'],
        'a block' => ['begin update obj_shapes set id = id where id = 1 returning outline into :outline_o; end;'],
    ]);

    it('binds a fetched value back unchanged', function (ValueMapping $values): void {
        $connection = $this->connect();
        $options = $this->options(values: $values);

        [$value, $shape, $back] = inTimeZone('Asia/Kathmandu', function () use ($connection, $options): array {
            $value = struct($this->rows($connection, 'select value from obj_all_scalars_tab where id = 1', options: $options)[0]['VALUE']);
            $shape = $this->rows($connection, 'select center, tags, outline from obj_shapes where id = 1', options: $options)[0];

            return [$value, $shape, $connection->execute(
                'begin :o := :i; :center_o := :center; :tags_o := :tags; :outline_o := :outline; end;',
                [
                    'i' => new In\Struct('obj_all_scalars', $value), 'o' => new Out\Struct('obj_all_scalars'),
                    'center' => new In\Struct('obj_point', struct($shape['CENTER'])), 'center_o' => new Out\Struct('obj_point'),
                    'tags' => new In\Collection('obj_string_varray', elements($shape['TAGS'])), 'tags_o' => new Out\Collection('obj_string_varray'),
                    'outline' => new In\Collection('obj_point_list', elements($shape['OUTLINE'])), 'outline_o' => new Out\Collection('obj_point_list'),
                ],
                $options,
            )->outValues()];
        });

        expect(shown(struct($back['o'][0])))->toBe(shown($value))
            ->and([$back['center_o'][0], $back['tags_o'][0], $back['outline_o'][0]])->toBe([$shape['CENTER'], $shape['TAGS'], $shape['OUTLINE']]);
    })->with([
        'default' => [new ValueMapping()],
        'String temporals, Float numbers' => [new ValueMapping(numbers: NumberMode::Float, temporals: TemporalMode::String)],
    ]);

    it('sends attributes as their types store them', function (): void {
        $value = allScalars();
        $value['C_NUMBER'] = 0.1;
        $value['C_DATE'] = new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('Asia/Tokyo'));
        $value['C_TSTZ'] = new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('-03:30'));
        $value['C_TSLTZ'] = new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('+02:00'));
        $value['C_BOOLEAN'] = true;
        $value['C_NCHAR'] = 'ç';

        $text = $this->connect()->execute(
            "declare v obj_all_scalars := :i; begin :s := to_char(v.c_number) || '|' || to_char(v.c_date, 'YYYY-MM-DD HH24:MI:SS') || '|' || to_char(v.c_tstz, 'YYYY-MM-DD HH24:MI:SS.FF3 TZH:TZM') || '|' || to_char(sys_extract_utc(v.c_tsltz), 'YYYY-MM-DD HH24:MI:SS') || '|' || case when v.c_boolean then 'T' else 'F' end || '|' || v.c_nchar || '|'; end;",
            ['i' => new In\Struct('obj_all_scalars', $value), 's' => new Out\Varchar()],
            $this->options(),
        )->outValues()['s'][0];

        // A CHAR or NCHAR attribute is stored as given, unpadded.
        expect($text)->toBe('.1|2026-03-01 10:00:00|2026-03-01 10:00:00.500 -03:30|2026-03-01 08:00:00|T|ç|');
    });

    it('keeps null empty and all null values apart in and out', function (): void {
        $nullPoint = ['X' => null, 'Y' => null, 'LABEL' => null];
        $connection = $this->connect();

        $values = $connection->execute(
            <<<'SQL'
                begin
                  :null_sum := obj_pkg.sum_numbers(:null_list);
                  :empty_sum := obj_pkg.sum_numbers(:empty_list);
                  :null_is_null := case when :null_point is null then 'Y' else 'N' end;
                  :all_null_is_null := case when :all_null_point is null then 'Y' else 'N' end;
                  obj_pkg.reverse_numbers(:null_list, :reversed_null);
                  obj_pkg.reverse_numbers(:with_nulls, :reversed_nulls);
                  :point_o := :all_null_point;
                  :null_point_o := :null_point;
                end;
                SQL,
            [
                'null_list' => new In\Collection('obj_number_list', null),
                'empty_list' => new In\Collection('obj_number_list', []),
                'with_nulls' => new In\Collection('obj_number_list', [null, 1, null]),
                'null_point' => new In\Struct('obj_point', null),
                'all_null_point' => new In\Struct('obj_point', $nullPoint),
                'null_sum' => new Out\Number(), 'empty_sum' => new Out\Number(),
                'null_is_null' => new Out\Varchar(), 'all_null_is_null' => new Out\Varchar(),
                'reversed_null' => new Out\Collection('obj_number_list'),
                'reversed_nulls' => new Out\Collection('obj_number_list'),
                'point_o' => new Out\Struct('obj_point'), 'null_point_o' => new Out\Struct('obj_point'),
            ],
            $this->options(),
        )->outValues();

        expect(array_filter($values, static fn (array $list): bool => $list !== []))->toBe([
            'null_sum' => [null], 'empty_sum' => [0],
            'null_is_null' => ['Y'], 'all_null_is_null' => ['N'],
            'reversed_null' => [null], 'reversed_nulls' => [[null, 1, null]],
            'point_o' => [$nullPoint], 'null_point_o' => [null],
        ]);
    });

    it('keeps the sparse and negative keys of an associative array in order', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            ECHO_BLOCK,
            ['i' => new In\Indexed('obj_pkg.t_number_map', [10 => 2, -5 => 1, 3 => 3, -2147483648 => 4, 2147483647 => 5]), 'o' => new Out\Indexed('obj_pkg.t_number_map')],
            $this->options(),
        )->outValues();

        expect($values['o'][0])->toBe([-2147483648 => 4, -5 => 1, 3 => 3, 10 => 2, 2147483647 => 5]);
    });

    it('gives a sparse nested table as a list', function (): void {
        $connection = $this->connect();

        $values = $connection->execute('begin :o := obj_kinds.sparse_numbers; end;', ['o' => new Out\Collection('obj_number_list')], $this->options())->outValues();

        expect($values['o'][0])->toBe([1, 3, 5]);
    });

    it('takes ten thousand ids in a table of a collection bind', function (string $type, array $ids): void {
        /** @var list<int|string> $ids */
        $connection = $this->connect();
        $bind = ['ids' => new In\Collection($type, $ids)];

        $all = $this->rows($connection, 'select count(*) as n, sum(column_value) as s from table(:ids)', $bind);
        $joined = $this->rows($connection, 'select count(*) as n, sum(i.id) as s from (select level as id from dual connect by level <= 1000) i join table(:ids) t on t.column_value = i.id', $bind);

        expect($all)->toBe([['N' => 10000, 'S' => 50005000]])
            ->and($joined)->toBe([['N' => 1000, 'S' => 500500]]);
    })->with([
        'SYS.ODCINUMBERLIST' => ['sys.odcinumberlist', range(1, 10000)],
        'SYS.ODCIVARCHAR2LIST' => ['sys.odcivarchar2list', array_map(strval(...), range(1, 10000))],
        'a user type' => ['obj_number_list', range(1, 10000)],
    ]);

    it('fails before any round trip naming the fix for a mistake', function (Bindable $bind, string $message): void {
        $connection = $this->connect();
        $schema = $this->value($connection, "select sys_context('userenv', 'current_schema') from dual");
        assert(\is_string($schema));
        // Describe every type the cases name, so a case costs no Round trip.
        $connection->execute(
            'begin :a := :a; :b := :b; :c := :c; :d := :d; :e := :e; :f := :f; end;',
            [
                'a' => new Out\Struct('obj_point'), 'b' => new Out\Collection('obj_number_list'), 'c' => new Out\Indexed('obj_pkg.t_number_map'),
                'd' => new Out\Struct('obj_polygon'), 'e' => new Out\Collection('obj_string_varray'), 'f' => new Out\Struct('obj_all_scalars'),
            ],
            $this->options(),
        );
        $sid = $this->sid($connection);
        $thrown = null;

        $trips = roundTrips($sid, function () use ($connection, $bind, &$thrown): void {
            try {
                $connection->execute(ECHO_BLOCK, ['i' => $bind, 'o' => new Out\Number()], $this->options());
            } catch (UsageException $usageException) {
                $thrown = $usageException;
            }
        });

        $expected = str_replace(schemaPlaceholder(), $schema, $message);
        assert($thrown instanceof UsageException && $expected !== '');
        expect($thrown->getMessage())->toStartWith($expected)
            ->and($trips)->toBe(0);
    })->with('mistakes');
});

describe('type names', function (): void {
    it('resolves a type name under oracle identifier rules', function (string $name): void {
        $connection = $this->connect();
        $schema = $this->value($connection, "select sys_context('userenv', 'current_schema') from dual");
        assert(\is_string($schema));
        $name = str_replace(schemaPlaceholder(), strtolower($schema), $name);

        $values = $connection->execute(ECHO_BLOCK, ['i' => new In\Struct($name, ['X' => 1, 'Y' => 2, 'LABEL' => 'a']), 'o' => new Out\Struct($name)], $this->options())->outValues();

        expect($values['o'])->toBe([['X' => 1, 'Y' => 2, 'LABEL' => 'a']]);
    })->with([
        // Unquoted parts are case-insensitive, a quoted part is kept.
        'lowercase' => ['obj_point'],
        'uppercase' => ['OBJ_POINT'],
        'quoted' => ['"OBJ_POINT"'],
        'mixed case' => ['Obj_Point'],
        'schema-qualified' => [schemaPlaceholder() . '.obj_point'],
        'a synonym' => ['obj_point_alias'],
    ]);

    it('resolves a quoted mixed case type name', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(ECHO_BLOCK, ['i' => new In\Struct('"OBJ_Mixed_Point"', ['x' => 1, 'y' => 2]), 'o' => new Out\Struct('"OBJ_Mixed_Point"')], $this->options())->outValues();

        expect($values['o'])->toBe([['x' => 1, 'Y' => 2]]);
    });

    it('resolves package types', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'begin :r := :r; :n := :n; end;',
            [
                'r' => new InOut\Struct('obj_kinds.t_record', ['id' => -7, 'name' => 'r', 'flag' => true]),
                'n' => new InOut\Collection('obj_kinds.t_numbers', [1, 2]),
            ],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['r' => [['ID' => -7, 'NAME' => 'r', 'FLAG' => true]], 'n' => [[1, 2]]]);
    });

    it('fails naming an unknown type', function (string $unknown): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute(ECHO_BLOCK, ['i' => new In\Struct($unknown, null), 'o' => new Out\Number()], $this->options());
        } catch (QueryException $queryException) {
            $thrown = $queryException;
        }

        assert($thrown instanceof QueryException);
        expect($thrown->getMessage())->toStartWith("Bind 'i': the type ")
            ->toEndWith(' does not exist, or this user cannot see it.');
    })->with([
        'a quoted name written unquoted' => ['obj_mixed_point'],
        'a missing type' => ['obj_no_such_type'],
        'a missing package type' => ['obj_pkg.no_such_type'],
    ]);

    it('describes a type with round trips only on its first use in a session', function (): void {
        $pool = $this->ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$pool);
        $sid = $this->sid($connection);
        $echo = fn (Session $connection, string $name): mixed => $connection->execute(
            ECHO_BLOCK,
            ['i' => new In\Collection($name, [[1], []]), 'o' => new Out\Collection($name)],
            $this->options(),
        )->outValues()['o'];

        $first = roundTrips($sid, static fn (): mixed => $echo($connection, 'obj_number_matrix'));
        $again = roundTrips($sid, static fn (): mixed => $echo($connection, 'OBJ_NUMBER_MATRIX'));
        $connection->close();
        $next = $this->connect(...$pool);
        $nextLoan = roundTrips($sid, static fn (): mixed => $echo($next, 'Obj_Number_Matrix'));
        $column = roundTrips($sid, fn (): array => $this->rows($next, 'select obj_number_matrix(obj_number_list(1)) from dual'));

        expect($this->sid($next))->toBe($sid)
            ->and($first)->toBeGreaterThan(1)
            ->and($again)->toBe(1)
            ->and($nextLoan)->toBe(1)
            ->and($column)->toBe(1);
    });

    it('fails loudly or stays consistent after alter type elsewhere', function (): void {
        $type = 'obj_evolving_' . bin2hex(random_bytes(3));
        $connection = $this->connect(standalone: true);
        $connection->execute("create type $type as object (a number)", [], $this->options());

        $echo = fn (array $value): mixed => $connection->execute(
            ECHO_BLOCK,
            ['i' => new In\Struct($type, struct($value)), 'o' => new Out\Struct($type)],
            $this->options(),
        )->outValues()['o'][0];
        $echo(['a' => 1]);
        $this->connect()->execute("alter type $type add attribute (b varchar2(10)) cascade", [], $this->options());
        $fresh = $this->connect(standalone: true);
        $outcomes = [];
        $messages = [];

        foreach ([
            'bind the old shape' => static fn (): mixed => $echo(['a' => 2]),
            'bind the new shape' => static fn (): mixed => $echo(['a' => 3, 'b' => 'x']),
            'fetch a value' => fn (): mixed => $this->value($connection, "select $type(4, 'y') from dual"),
            'fetch a value again' => fn (): mixed => $this->value($connection, "select $type(4, 'y') from dual"),
            'still usable' => fn (): mixed => $this->value($connection, 'select 1 from dual'),
            'a new Session fetches' => fn (): mixed => $this->value($fresh, "select $type(5, 'z') from dual"),
            'a new Session binds' => fn (): mixed => $fresh->execute(
                "declare v $type := :i; begin :o := v.a || v.b; end;",
                ['i' => new In\Struct($type, ['a' => 6, 'b' => 'w']), 'o' => new Out\Varchar()],
                $this->options(),
            )->outValues()['o'][0],
            'a new Session binds OUT' => fn (): mixed => $fresh->execute(
                "begin :o := $type(7, 'v'); end;",
                ['o' => new Out\Struct($type)],
                $this->options(),
            )->outValues()['o'][0],
        ] as $case => $run) {
            try {
                $outcomes[$case] = $run();
            } catch (OracleException $e) {
                $outcomes[$case] = $e::class . ($e->getCode() === 0 ? '' : ' ' . $e->getCode());
                $messages[$case] = $e->getMessage();
            }
        }

        expect($outcomes)->toBe([
            'bind the old shape' => QueryException::class . ' 3101',
            'bind the new shape' => UsageException::class,
            'fetch a value' => QueryException::class,
            'fetch a value again' => QueryException::class,
            'still usable' => 1,
            'a new Session fetches' => ['A' => 5, 'B' => 'z'],
            'a new Session binds' => '6w',
            'a new Session binds OUT' => ['A' => 7, 'B' => 'v'],
        ])
            ->and($messages['fetch a value'] ?? '')->toContain(strtoupper($type) . ' has changed since this connection described it');
    });
});

describe('unsupported attributes', function (): void {
    it('fail loudly on read when v1 does not map their type', function (string $value, string $named): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $this->rows($connection, "select $value as v from dual");
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }

        assert($thrown instanceof UnsupportedException);
        expect($thrown->getMessage())->toContain($named)
            ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
    })->with([
        // JSON, VECTOR and LONG cannot be attributes of an Object type at all.
        'CLOB' => ["obj_lob_holder(1, to_clob('x'), null, null)", 'CLOB'],
        'NCLOB' => ["obj_lob_holder(1, null, to_nclob('x'), null)", 'NCLOB'],
        'BLOB' => ["obj_lob_holder(1, null, null, to_blob(hextoraw('00')))", 'BLOB'],
        'XMLTYPE' => ["obj_xml_holder(1, sys.xmltype('<a/>'))", 'XMLTYPE'],
        'REF' => ['obj_ref_holder(1, null)', 'TDS type code 9'],
        'BFILE' => ['obj_bfile_holder(1, null)', 'TDS type code 31'],
        'a region-named TSTZ' => ["obj_tstz_holder(timestamp '2026-03-01 10:00:00 America/Sao_Paulo')", 'time zone region'],
    ]);

    it('fail loudly on bind when they hold a lob value', function (string $attribute, string $value): void {
        $holder = ['id' => 1, 'doc' => null, 'ndoc' => null, 'bin' => null];
        $holder[$attribute] = $value;
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute(ECHO_BLOCK, ['i' => new In\Struct('obj_lob_holder', $holder), 'o' => new Out\Struct('obj_lob_holder')], $this->options());
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }

        assert($thrown instanceof UnsupportedException);
        expect($thrown->getMessage())->toStartWith("Bind 'i', attribute " . strtoupper($attribute) . ': ')
            ->toEndWith(' values in an Object or Collection are not supported yet.');
    })->with([
        'CLOB' => ['doc', 'x'],
        'NCLOB' => ['ndoc', 'x'],
        'BLOB' => ['bin', "\x00"],
    ]);

    it('fail loudly on bind when the type has a ref or bfile attribute', function (string $type): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute(ECHO_BLOCK, ['i' => new In\Struct($type, null), 'o' => new Out\Number()], $this->options());
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }

        assert($thrown instanceof UnsupportedException);
        expect($thrown->getMessage())->toStartWith("Bind 'i': TDS type code");
    })->with(['obj_ref_holder', 'obj_bfile_holder']);

    it('bind and read as null when they are null lobs', function (): void {
        $connection = $this->connect();
        $holder = ['id' => 1, 'doc' => null, 'ndoc' => null, 'bin' => null];

        $values = $connection->execute(ECHO_BLOCK, ['i' => new In\Struct('obj_lob_holder', $holder), 'o' => new Out\Struct('obj_lob_holder')], $this->options())->outValues();

        expect($values['o'])->toBe([['ID' => 1, 'DOC' => null, 'NDOC' => null, 'BIN' => null]]);
    });
});

describe('inheritance', function (): void {
    it('reads a supertype instance in a supertype column', function (): void {
        $connection = $this->connect();

        $rows = $this->rows($connection, 'select animal from obj_animals where id = 1');

        expect($rows)->toBe([['ANIMAL' => ['NAME' => 'cat']]]);
    });

    it('fails loudly reading a subtype instance', function (): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $this->rows($connection, 'select animal from obj_animals where id = 2');
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }

        assert($thrown instanceof UnsupportedException);
        expect($thrown->getMessage())->toContain('OBJ_ANIMAL holds an instance of an object subtype');
    });

    it('fails loudly binding a subtype instance', function (): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute(ECHO_BLOCK, ['i' => new In\Struct('obj_dog', ['name' => 'rex', 'breed' => 'lab']), 'o' => new Out\Number()], $this->options());
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }

        assert($thrown instanceof UnsupportedException);
        expect($thrown->getMessage())->toStartWith("Bind 'i': ")
            ->toContain('OBJ_DOG holds an instance of an object subtype');
    });
});

describe('nested collections', function (): void {
    it('binds a collection of collections in and out', function (): void {
        $matrix = [[1, 2], [], null, [null]];
        $connection = $this->connect();

        $values = $connection->execute(ECHO_BLOCK, ['i' => new In\Collection('obj_number_matrix', $matrix), 'o' => new Out\Collection('obj_number_matrix')], $this->options())->outValues();

        expect($values['o'])->toBe([$matrix]);
    });

    it('binds an associative array of objects in and out', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            ECHO_BLOCK,
            ['i' => new In\Indexed('obj_kinds.t_point_map', [5 => point(1, 2), -1 => null]), 'o' => new Out\Indexed('obj_kinds.t_point_map')],
            $this->options(),
        )->outValues();

        expect($values['o'])->toBe([[-1 => null, 5 => ['X' => 1, 'Y' => 2, 'LABEL' => null]]]);
    });

    it('fails loudly binding an index by varchar2', function (Bindable $bind): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute('begin :o := obj_kinds.string_map; end;', ['o' => $bind], $this->options());
        } catch (UnsupportedException | UsageException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->getMessage())->toContain('INDEX BY VARCHAR2');
    })->with([
        // @phpstan-ignore argument.type (deliberately string keys)
        'In\Indexed with string keys' => [new In\Indexed('obj_kinds.t_string_map', ['a' => 'x'])],
        'In\Indexed with int keys' => [new In\Indexed('obj_kinds.t_string_map', [1 => 'x'])],
        'Out\Indexed' => [new Out\Indexed('obj_kinds.t_string_map')],
        'Out\Collection' => [new Out\Collection('obj_kinds.t_string_map')],
    ]);
});

/**
 * The rows of obj_shapes: every column set, every column NULL, and empty
 * Collections in an all-NULL Object.
 *
 * @return list<array<string, mixed>>
 */
function shapes(): array
{
    return [
        [
            'ID' => 1,
            'CENTER' => ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'],
            'TAGS' => ['red', 'café'],
            'OUTLINE' => [
                ['X' => 0, 'Y' => 0, 'LABEL' => 'a'],
                ['X' => 1, 'Y' => 0, 'LABEL' => 'b'],
                ['X' => 0, 'Y' => 1, 'LABEL' => null],
            ],
        ],
        ['ID' => 2, 'CENTER' => null, 'TAGS' => null, 'OUTLINE' => null],
        ['ID' => 3, 'CENTER' => ['X' => null, 'Y' => null, 'LABEL' => null], 'TAGS' => [], 'OUTLINE' => []],
    ];
}

/**
 * A new obj_shapes row 99, bound.
 *
 * @return array<string, Bindable|int>
 */
function newShape(): array
{
    return [
        'id' => 99,
        'center' => new In\Struct('obj_point', ['x' => 5, 'y' => 6, 'label' => 'new']),
        'tags' => new In\Collection('obj_string_varray', ['t']),
        'outline' => new In\Collection('obj_point_list', []),
    ];
}

/**
 * @return list<array<int|string, mixed>>
 */
function drain(CursorContract $cursor): array
{
    $rows = [];
    while (($batch = $cursor->fetchBatch()) !== []) {
        array_push($rows, ...$batch);
    }
    $cursor->close();

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function struct(mixed $value): array
{
    assert(\is_array($value));

    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * @return list<mixed>
 */
function elements(mixed $value): array
{
    assert(\is_array($value) && array_is_list($value));

    return $value;
}

/**
 * @return array{x: int, y: int, label: null}
 */
function point(int $x, int $y): array
{
    return ['x' => $x, 'y' => $y, 'label' => null];
}

/**
 * The attributes of obj_all_scalars, keyed as Native shows them, all NULL.
 *
 * @return array<string, mixed>
 */
function allScalars(): array
{
    return array_fill_keys(
        ['C_CHAR', 'C_NCHAR', 'C_VARCHAR', 'C_NVARCHAR', 'C_RAW', 'C_NUMBER', 'C_FLOAT', 'C_DOUBLE', 'C_BOOLEAN', 'C_DATE', 'C_TS', 'C_TSTZ', 'C_TSLTZ', 'C_IDS', 'C_IYM'],
        null,
    );
}

/**
 * @param array<array-key, mixed> $values
 *
 * @return array<array-key, mixed>
 */
function shown(array $values): array
{
    return array_map(temporalText(...), $values);
}

/** Stands for the current schema in data providers. */
function schemaPlaceholder(): string
{
    return '{schema}';
}
