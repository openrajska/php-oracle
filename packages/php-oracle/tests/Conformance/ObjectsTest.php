<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ObjectsTest;

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
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;

test('object and collection columns arrive as arrays', function (): void {
    $shapes = [
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

    expect($this->rows($this->connect(), 'select * from fx_shapes order by id'))->toBe($shapes);
});

test('the same arrays arrive through a ref cursor an implicit result and a pipelined function', function (): void {
    $shapes = [
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

    $connection = $this->connect();

    $execution = $connection->execute(
        'begin open :o for select * from fx_shapes order by id; end;',
        ['o' => new Out\Cursor()],
        $this->options(),
    );
    $cursor = $execution->outValues()['o'][0];
    assert($cursor instanceof CursorContract);
    expect(drain($cursor))->toBe($shapes);

    $execution = $connection->execute(
        'declare c sys_refcursor; begin open c for select * from fx_shapes order by id; dbms_sql.return_result(c); end;',
        [],
        $this->options(),
    );
    expect(drain($execution->implicitResults()[0]))->toBe($shapes)
        ->and($this->rows($connection, 'select value(t) as p from table(fx_pipe.points(2)) t'))->toBe([
            ['P' => ['X' => 1, 'Y' => 1, 'LABEL' => 'P1']],
            ['P' => ['X' => 2, 'Y' => 4, 'LABEL' => 'P2']],
        ]);
});

test('attribute keys follow key case', function (): void {
    $connection = $this->connect();

    expect($this->rows(
        $connection,
        "select center, fx_point_list(fx_point(0, 0, 'a')) from fx_shapes where id = 1",
        options: $this->options(rowShape: RowShape::List, keyCase: KeyCase::Lower),
    ))->toBe([[['x' => '1.5', 'y' => -2, 'label' => 'center'], [['x' => 0, 'y' => 0, 'label' => 'a']]]])
        ->and($this->rows($connection, 'select "FX_Mixed_Point"(1, 2) as p from dual'))->toBe([['P' => ['x' => 1, 'Y' => 2]]]);
});

test('object and collection columns describe their type', function (): void {
    $connection = $this->connect();
    $schema = $this->value($connection, "select sys_context('userenv', 'current_schema') from dual");
    assert(\is_string($schema));
    $cursor = $connection->execute('select center, tags, outline from fx_shapes', [], $this->options())->cursor();
    assert($cursor !== null);

    $columns = array_map(
        static fn (array $column): array => [$column['type'], $column['objectType']],
        $cursor->columns(),
    );

    expect($columns)->toBe([
        [Type::Object->value, "$schema.FX_POINT"],
        [Type::Collection->value, "$schema.FX_STRING_VARRAY"],
        [Type::Collection->value, "$schema.FX_POINT_LIST"],
    ]);
    $cursor->close();
});

test('attributes follow every value mapping', function (): void {
    Temporals::inZone('America/Sao_Paulo', function (): void {
        $connection = $this->connect();
        $sql = 'select value from fx_all_scalars_tab order by id';
        $zone = 'America/Sao_Paulo';

        $default = $this->rows($connection, $sql);
        expect(shown(struct($default[0]['VALUE'])))->toBe([
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
            ->and($default[1]['VALUE'])->toBe(array_fill_keys(array_keys(allScalars()), null));

        $float = $this->rows($connection, $sql, options: $this->options(values: new ValueMapping(numbers: NumberMode::Float)));
        expect(struct($float[0]['VALUE'])['C_NUMBER'])->toBe(12.5);

        $string = struct($this->rows($connection, $sql, options: $this->options(values: new ValueMapping(temporals: TemporalMode::String)))[0]['VALUE']);
        expect([$string['C_DATE'], $string['C_TS'], $string['C_TSTZ'], $string['C_TSLTZ']])->toBe(['2026-03-01 10:00:00', '2026-03-01 10:00:00.123456789', '2026-03-01 10:00:00.123456789 +05:45', '2026-03-01 07:00:00.123456789 -03:00']);
        // INTERVALs stay objects.
        expect($string['C_IDS'])->toBeInstanceOf(DateInterval::class);

        $lower = $this->rows($connection, $sql, options: $this->options(keyCase: KeyCase::Lower));
        expect(array_keys(struct($lower[0]['value'])))->toBe(array_map(strtolower(...), array_keys(allScalars())));
    });
});

test('a boolean attribute reads as stored', function (): void {
    $connection = $this->connect();
    $built = static fn (string $flag): string => "fx_all_scalars(null, null, null, null, null, null, null, null, $flag, null, null, null, null, null, null)";

    foreach (['true' => true, 'false' => false, 'null' => null] as $flag => $expected) {
        $plsql = $connection->execute("begin :o := {$built($flag)}; end;", ['o' => new Out\Struct('fx_all_scalars')], $this->options());
        expect(struct($plsql->outValues()['o'][0])['C_BOOLEAN'])->toBe($expected, "PL/SQL $flag");

        $sql = struct($this->value($connection, "select {$built($flag)} from dual"));
        expect($sql['C_BOOLEAN'])->toBe($expected, "SQL $flag");
    }
});

test('struct collection and indexed round trip in out and in out', function (): void {
    $connection = $this->connect();

    $point = $connection->execute(
        'begin :o := fx_object.make_point(:x, :y, :label); fx_object.move_point(:io, 1, 2); :n := fx_object.count_vertices(:polygon); end;',
        [
            'x' => 1, 'y' => '0.5', 'label' => 'p',
            'o' => new Out\Struct('fx_point'),
            'io' => new InOut\Struct('fx_point', ['x' => 1, 'y' => 1, 'label' => 'moved']),
            'polygon' => new In\Struct('fx_polygon', ['name' => 'tri', 'vertices' => [point(0, 0), point(1, 0), point(0, 1)]]),
            'n' => new Out\Number(),
        ],
        $this->options(),
    )->outValues();
    expect($point['o'])->toBe([['X' => 1, 'Y' => '0.5', 'LABEL' => 'p']])
        ->and($point['io'])->toBe([['X' => 2, 'Y' => 3, 'LABEL' => 'moved']])
        ->and($point['n'])->toBe([3]);

    $collections = $connection->execute(
        "begin fx_object.reverse_numbers(:numbers, :reversed); fx_object.append_tag(:tags, 'z'); :sum := fx_object.sum_numbers(:numbers); end;",
        [
            'numbers' => new In\Collection('fx_number_list', [1, 2.5, -3]),
            'reversed' => new Out\Collection('fx_number_list'),
            'tags' => new InOut\Collection('fx_string_varray', ['x', 'y']),
            'sum' => new Out\Number(),
        ],
        $this->options(),
    )->outValues();
    expect($collections['reversed'])->toBe([[-3, '2.5', 1]])
        ->and($collections['tags'])->toBe([['x', 'y', 'z']])
        ->and($collections['sum'])->toBe(['0.5']);

    $map = $connection->execute(
        'begin fx_object.fill_map(3, :filled); :sum := fx_object.sum_map(:map); :io := :io; end;',
        [
            'filled' => new Out\Indexed('fx_object.t_number_map'),
            'map' => new In\Indexed('fx_object.t_number_map', [7 => 1, -2 => 2]),
            'io' => new InOut\Indexed('fx_object.t_number_map', [1 => 1]),
            'sum' => new Out\Number(),
        ],
        $this->options(),
    )->outValues();
    expect($map['filled'])->toBe([[10 => 1, 20 => 2, 30 => 3]])
        ->and($map['sum'])->toBe([3])
        ->and($map['io'])->toBe([[1 => 1]]);
});

test('struct and collection bind into sql and come back from returning into', function (): void {
    $connection = $this->connect();
    $options = $this->options(autocommit: false);
    $binds = [
        'id' => 99,
        'center' => new In\Struct('fx_point', ['x' => 5, 'y' => 6, 'label' => 'new']),
        'tags' => new In\Collection('fx_string_varray', ['t']),
        'outline' => new In\Collection('fx_point_list', []),
    ];
    $insert = 'insert into fx_shapes (id, center, tags, outline) values (:id, :center, :tags, :outline)';

    $returned = $connection->execute(
        "$insert returning center, tags into :center_o, :tags_o",
        $binds + ['center_o' => new Out\Struct('fx_point'), 'tags_o' => new Out\Collection('fx_string_varray')],
        $options,
    );
    expect($returned->rowCount())->toBe(1)
        ->and($returned->outValues()['center_o'])->toBe([['X' => 5, 'Y' => 6, 'LABEL' => 'new']])
        ->and($returned->outValues()['tags_o'])->toBe([['t']]);

    $connection->execute('delete from fx_shapes where id = 99', [], $options);

    $updated = $connection->execute(
        'update fx_shapes set id = id where id in (1, 2, 3) returning id, center, tags into :id_o, :center_o, :tags_o',
        ['id_o' => new Out\Number(), 'center_o' => new Out\Struct('fx_point'), 'tags_o' => new Out\Collection('fx_string_varray')],
        $options,
    );
    $rows = array_map(null, $updated->outValues()['id_o'], $updated->outValues()['center_o'], $updated->outValues()['tags_o']);
    usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    expect($rows)->toBe([
        [1, ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'], ['red', "caf\u{e9}"]],
        [2, null, null],
        [3, ['X' => null, 'Y' => null, 'LABEL' => null], []],
    ]);

    // A nested table column comes back as a reference to its storage
    // table, which is not decoded: loud, and the Connection stays usable.
    $returnOutline = 'update fx_shapes set id = id where id = 1 returning outline into :outline_o';
    foreach ([$returnOutline, "begin $returnOutline; end;"] as $sql) {
        try {
            $connection->execute($sql, ['outline_o' => new Out\Collection('fx_point_list')], $options);
            $this->fail('RETURNING INTO a nested table column ran.');
        } catch (UnsupportedException $e) {
            expect($e->getMessage())->toContain('database object stored in a LOB');
        }
    }

    $block = $connection->execute(
        "begin $insert returning center, tags into :center_o, :tags_o; end;",
        $binds + ['center_o' => new Out\Struct('fx_point'), 'tags_o' => new Out\Collection('fx_string_varray')],
        $options,
    );
    expect($block->outValues()['center_o'])->toBe([['X' => 5, 'Y' => 6, 'LABEL' => 'new']])
        ->and($block->outValues()['tags_o'])->toBe([['t']]);

    $connection->execute('delete from fx_shapes where id = 99', [], $options);

    expect($connection->execute($insert, $binds, $options)->rowCount())->toBe(1)
        ->and($this->rows($connection, 'select center, tags, outline from fx_shapes where id = 99', options: $options))->toBe([['CENTER' => ['X' => 5, 'Y' => 6, 'LABEL' => 'new'], 'TAGS' => ['t'], 'OUTLINE' => []]]);

    $connection->rollback();
});

/**
 * @return iterable<string, array{ValueMapping}>
 */
dataset('mappings', function () {
    yield 'default' => [new ValueMapping()];
    yield 'String temporals, Float numbers' => [new ValueMapping(numbers: NumberMode::Float, temporals: TemporalMode::String)];
});

test('a fetched value binds back unchanged', function (ValueMapping $values): void {
    Temporals::inZone('Asia/Kathmandu', function () use ($values): void {
        $connection = $this->connect();
        $options = $this->options(values: $values);
        $value = struct($this->rows($connection, 'select value from fx_all_scalars_tab where id = 1', options: $options)[0]['VALUE']);
        $shapes = $this->rows($connection, 'select center, tags, outline from fx_shapes where id = 1', options: $options)[0];

        $back = $connection->execute(
            'begin :o := :i; :center_o := :center; :tags_o := :tags; :outline_o := :outline; end;',
            [
                'i' => new In\Struct('fx_all_scalars', $value), 'o' => new Out\Struct('fx_all_scalars'),
                'center' => new In\Struct('fx_point', struct($shapes['CENTER'])), 'center_o' => new Out\Struct('fx_point'),
                'tags' => new In\Collection('fx_string_varray', elements($shapes['TAGS'])), 'tags_o' => new Out\Collection('fx_string_varray'),
                'outline' => new In\Collection('fx_point_list', elements($shapes['OUTLINE'])), 'outline_o' => new Out\Collection('fx_point_list'),
            ],
            $options,
        )->outValues();

        expect(shown(struct($back['o'][0])))->toBe(shown($value))
            ->and([$back['center_o'][0], $back['tags_o'][0], $back['outline_o'][0]])->toBe([$shapes['CENTER'], $shapes['TAGS'], $shapes['OUTLINE']]);
    });
})->with('mappings');

test('bound attributes reach the server as their types store them', function (): void {
    $value = array_fill_keys(array_keys(allScalars()), null);
    $value['C_NUMBER'] = 0.1;
    $value['C_DATE'] = new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('Asia/Tokyo'));
    $value['C_TSTZ'] = new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('-03:30'));
    $value['C_TSLTZ'] = new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('+02:00'));
    $value['C_BOOLEAN'] = true;
    $value['C_NCHAR'] = 'ç';

    $text = $this->connect()->execute(
        "declare v fx_all_scalars := :i; begin :s := to_char(v.c_number) || '|' || to_char(v.c_date, 'YYYY-MM-DD HH24:MI:SS') || '|' || to_char(v.c_tstz, 'YYYY-MM-DD HH24:MI:SS.FF3 TZH:TZM') || '|' || to_char(sys_extract_utc(v.c_tsltz), 'YYYY-MM-DD HH24:MI:SS') || '|' || case when v.c_boolean then 'T' else 'F' end || '|' || v.c_nchar || '|'; end;",
        ['i' => new In\Struct('fx_all_scalars', $value), 's' => new Out\Varchar()],
        $this->options(),
    )->outValues()['s'][0];

    // A CHAR or NCHAR attribute is stored as given, unpadded (C2).
    expect($text)->toBe('.1|2026-03-01 10:00:00|2026-03-01 10:00:00.500 -03:30|2026-03-01 08:00:00|T|ç|');
});

test('null empty and all null values stay apart in and out', function (): void {
    $nullPoint = ['X' => null, 'Y' => null, 'LABEL' => null];

    $connection = $this->connect();

    $out = $connection->execute(
        <<<'SQL'
                begin
                  :null_sum := fx_object.sum_numbers(:null_list);
                  :empty_sum := fx_object.sum_numbers(:empty_list);
                  :null_is_null := case when :null_point is null then 'Y' else 'N' end;
                  :all_null_is_null := case when :all_null_point is null then 'Y' else 'N' end;
                  fx_object.reverse_numbers(:null_list, :reversed_null);
                  fx_object.reverse_numbers(:with_nulls, :reversed_nulls);
                  :point_o := :all_null_point;
                  :null_point_o := :null_point;
                end;
                SQL,
        [
            'null_list' => new In\Collection('fx_number_list', null),
            'empty_list' => new In\Collection('fx_number_list', []),
            'with_nulls' => new In\Collection('fx_number_list', [null, 1, null]),
            'null_point' => new In\Struct('fx_point', null),
            'all_null_point' => new In\Struct('fx_point', $nullPoint),
            'null_sum' => new Out\Number(), 'empty_sum' => new Out\Number(),
            'null_is_null' => new Out\Varchar(), 'all_null_is_null' => new Out\Varchar(),
            'reversed_null' => new Out\Collection('fx_number_list'),
            'reversed_nulls' => new Out\Collection('fx_number_list'),
            'point_o' => new Out\Struct('fx_point'), 'null_point_o' => new Out\Struct('fx_point'),
        ],
        $this->options(),
    )->outValues();

    expect(array_intersect_key($out, array_flip(['null_sum', 'empty_sum', 'null_is_null', 'all_null_is_null', 'reversed_null', 'reversed_nulls', 'point_o', 'null_point_o'])))->toBe([
        'null_sum' => [null], 'empty_sum' => [0],
        'null_is_null' => ['Y'], 'all_null_is_null' => ['N'],
        'reversed_null' => [null], 'reversed_nulls' => [[null, 1, null]],
        'point_o' => [$nullPoint], 'null_point_o' => [null],
    ]);
});

test('indexed keeps sparse and negative keys in order and a sparse nested table gives a list', function (): void {
    $out = $this->connect()->execute(
        'begin :o := :i; :sparse := fx_kinds.sparse_numbers; end;',
        [
            'i' => new In\Indexed('fx_object.t_number_map', [10 => 2, -5 => 1, 3 => 3, -2147483648 => 4, 2147483647 => 5]),
            'o' => new Out\Indexed('fx_object.t_number_map'),
            'sparse' => new Out\Collection('fx_number_list'),
        ],
        $this->options(),
    )->outValues();

    expect($out['o'][0])->toBe([-2147483648 => 4, -5 => 1, 3 => 3, 10 => 2, 2147483647 => 5])
        ->and($out['sparse'][0])->toBe([1, 3, 5]);
});

/**
 * @return iterable<string, array{string, list<int|string>}>
 */
dataset('idLists', function () {
    yield 'SYS.ODCINUMBERLIST' => ['sys.odcinumberlist', range(1, 10000)];
    yield 'SYS.ODCIVARCHAR2LIST' => ['sys.odcivarchar2list', array_map(strval(...), range(1, 10000))];
    yield 'a user type' => ['fx_number_list', range(1, 10000)];
});

test('table of a collection bind takes ten thousand ids', function (string $type, array $ids): void {
    /** @var list<int|string> $ids */
    $connection = $this->connect();

    expect($this->rows($connection, 'select count(*) as n, sum(column_value) as s from table(:ids)', ['ids' => new In\Collection($type, $ids)]))->toBe([['N' => 10000, 'S' => 50005000]]);

    // fx_items holds ids 1 to 1000.
    expect($this->rows(
        $connection,
        'select count(*) as n, sum(i.id) as s from fx_items i join table(:ids) t on t.column_value = i.id',
        ['ids' => new In\Collection($type, $ids)],
    ))->toBe([['N' => 1000, 'S' => 500500]]);
})->with('idLists');

/**
 * @return iterable<string, array{Bindable, string}>
 */
dataset('mistakes', function () {
    $point = ['x' => 1, 'y' => 2, 'label' => null];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'a Collection that is not a list' => [new In\Collection('fx_number_list', [1 => 1, 0 => 2]), "Bind 'i': a Collection is a list (keys 0, 1, 2… in order), but key 1 is not; pass array_values() of it, or bind an Associative array with In\Indexed."];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'a Collection with string keys' => [new In\Collection('fx_number_list', ['a' => 1]), "Bind 'i': a Collection is a list (keys 0, 1, 2… in order), but key 'a' is not;"];
    yield 'a nested Collection that is not a list' => [new In\Struct('fx_polygon', ['name' => 'p', 'vertices' => [2 => $point]]), "Bind 'i', attribute VERTICES: a Collection is a list"];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'an Indexed string key' => [new In\Indexed('fx_object.t_number_map', ['a' => 1]), "Bind 'i': an Associative array has int keys, but key 'a' is a string; INDEX BY VARCHAR2 is not supported yet."];
    yield 'an Indexed key beyond 32 bits' => [new In\Indexed('fx_object.t_number_map', [2147483648 => 1]), "Bind 'i': key 2147483648 is outside the 32-bit range of an Associative array's keys (INDEX BY PLS_INTEGER)."];
    yield 'an unknown Struct key' => [new In\Struct('fx_point', $point + ['z' => 1]), "Bind 'i': " . schemaPlaceholder() . ".FX_POINT has no attribute 'z'; its attributes are X, Y, LABEL."];
    yield 'a missing Struct key' => [new In\Struct('fx_point', ['x' => 1]), "Bind 'i': the value has no key for attributes Y, LABEL of " . schemaPlaceholder() . '.FX_POINT; give every attribute, null for a NULL one.'];
    yield 'Struct keys differing only in case' => [new In\Struct('fx_point', $point + ['X' => 1]), "Bind 'i': keys 'x' and 'X' differ only in case and name the same attribute; keep one of them."];

    // @phpstan-ignore argument.type (deliberately the wrong shape)
    yield 'a list as a Struct' => [new In\Struct('fx_point', [1, 2, null]), "Bind 'i': an Object is keyed by attribute name, but key 0 is an int (is the array a list?)."];
    yield 'Struct of a Collection type' => [new In\Struct('fx_number_list', ['x' => 1]), "Bind 'i': " . schemaPlaceholder() . '.FX_NUMBER_LIST is a Collection type (nested table or VARRAY), not an Object type; use Collection, not Struct.'];
    yield 'Collection of an Object type' => [new In\Collection('fx_point', []), "Bind 'i': " . schemaPlaceholder() . '.FX_POINT is an Object type, not a Collection type (nested table or VARRAY); use Struct, not Collection.'];
    yield 'Collection of an Associative array type' => [new In\Collection('fx_object.t_number_map', []), "Bind 'i': " . schemaPlaceholder() . '.FX_OBJECT.T_NUMBER_MAP is an Associative array type (INDEX BY PLS_INTEGER), not a Collection type (nested table or VARRAY); use Indexed, not Collection.'];
    yield 'Indexed of a nested table type' => [new In\Indexed('fx_number_list', []), "Bind 'i': " . schemaPlaceholder() . '.FX_NUMBER_LIST is a Collection type (nested table or VARRAY), not an Associative array type (INDEX BY PLS_INTEGER); use Collection, not Indexed.'];
    yield 'an invalid type name' => [new In\Struct('fx point', $point), "Bind 'i': the type name 'fx point' is not valid: each dot-separated part must be an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes."];
    yield 'a type name with four parts' => [new In\Struct('a.b.c.d', $point), "Bind 'i': the type name 'a.b.c.d' has 4 parts; a type name has at most 3: schema.package.type."];
    yield 'text for a NUMBER' => [new In\Struct('fx_point', ['x' => 'one'] + $point), "Bind 'i', attribute X: a NUMBER takes a decimal literal with '.', such as '12.30', as text."];
    yield 'an array for a NUMBER' => [new In\Struct('fx_point', ['x' => []] + $point), "Bind 'i', attribute X: a NUMBER holds an int, a float or a decimal string or null, not array."];
    yield 'NaN for a NUMBER' => [new In\Collection('fx_number_list', [\NAN]), "Bind 'i', element 0: this float is outside NUMBER's range (NaN, INF, or beyond 1e-130..1e126)."];
    yield 'a string too long' => [new In\Struct('fx_point', ['label' => str_repeat('x', 31)] + $point), "Bind 'i': cannot store a value of 31 bytes (the maximum is 30) in attribute LABEL of type VARCHAR2(30)"];
    yield 'an int for a VARCHAR2 element' => [new In\Collection('fx_string_varray', [1]), "Bind 'i', element 0: a VARCHAR2(100) holds a string or null, not int."];
    yield 'a string for a timestamp' => [new In\Struct('fx_all_scalars', ['C_DATE' => 'today'] + array_fill_keys(array_keys(allScalars()), null)), "Bind 'i', attribute C_DATE: the text is not a DATE as TemporalMode::String writes it, such as '2026-03-01 10:00:00'."];
    yield 'a nested Object of the wrong shape' => [new In\Struct('fx_polygon', ['name' => 'p', 'vertices' => [1]]), "Bind 'i', attribute VERTICES, element 0: the value must be an array keyed by attribute name or null."];
});

test('a mistake fails before any round trip naming the fix', function (Bindable $bind, string $message): void {
    $echo = 'begin :o := :i; end;';

    $connection = $this->connect();
    $schema = $this->value($connection, "select sys_context('userenv', 'current_schema') from dual");
    assert(\is_string($schema));
    $message = str_replace(schemaPlaceholder(), $schema, $message);
    \assert($message !== '');

    // Describe every type the cases name, so a case costs no Round trip.
    $connection->execute(
        'begin :a := :a; :b := :b; :c := :c; :d := :d; :e := :e; :f := :f; end;',
        [
            'a' => new Out\Struct('fx_point'), 'b' => new Out\Collection('fx_number_list'), 'c' => new Out\Indexed('fx_object.t_number_map'),
            'd' => new Out\Struct('fx_polygon'), 'e' => new Out\Collection('fx_string_varray'), 'f' => new Out\Struct('fx_all_scalars'),
        ],
        $this->options(),
    );
    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();

    try {
        $trips = $counter->during($sid, function () use ($connection, $bind, $message, $echo): void {
            try {
                $connection->execute($echo, ['i' => $bind, 'o' => new Out\Number()], $this->options());
                $this->fail('The bind was accepted.');
            } catch (UsageException $usageException) {
                expect($usageException->getMessage())->toStartWith($message);
            }
        });
    } finally {
        $counter->close();
    }
    expect($trips)->toBe(0);
})->with('mistakes');

/**
 * Unquoted parts are case-insensitive, a quoted part is kept.
 *
 * @return iterable<string, array{string}>
 */
dataset('pointNames', function () {
    yield 'lowercase' => ['fx_point'];
    yield 'uppercase' => ['FX_POINT'];
    yield 'quoted' => ['"FX_POINT"'];
    yield 'mixed case' => ['Fx_Point'];
    yield 'schema-qualified' => [schemaPlaceholder() . '.fx_point'];
    yield 'a synonym' => ['fx_point_alias'];
});

test('a type name resolves under oracle identifier rules', function (string $name): void {
    $echo = 'begin :o := :i; end;';

    $connection = $this->connect();
    $schema = $this->value($connection, "select sys_context('userenv', 'current_schema') from dual");
    assert(\is_string($schema));
    $name = str_replace(schemaPlaceholder(), strtolower($schema), $name);

    $out = $connection->execute($echo, ['i' => new In\Struct($name, ['X' => 1, 'Y' => 2, 'LABEL' => 'a']), 'o' => new Out\Struct($name)], $this->options());

    expect($out->outValues()['o'])->toBe([['X' => 1, 'Y' => 2, 'LABEL' => 'a']]);
})->with('pointNames');

test('quoted names and package types resolve and an unknown type fails', function (): void {
    $echo = 'begin :o := :i; end;';

    $connection = $this->connect();

    $out = $connection->execute(
        'begin :o := :i; :r := :r; :n := :n; end;',
        [
            'i' => new In\Struct('"FX_Mixed_Point"', ['x' => 1, 'y' => 2]),
            'o' => new Out\Struct('"FX_Mixed_Point"'),
            'r' => new InOut\Struct('fx_kinds.t_record', ['id' => -7, 'name' => 'r', 'flag' => true]),
            'n' => new InOut\Collection('fx_kinds.t_numbers', [1, 2]),
        ],
        $this->options(),
    )->outValues();
    expect($out['o'])->toBe([['x' => 1, 'Y' => 2]])
        ->and($out['r'])->toBe([['ID' => -7, 'NAME' => 'r', 'FLAG' => true]])
        ->and($out['n'])->toBe([[1, 2]]);

    foreach (['fx_mixed_point', 'fx_no_such_type', 'fx_object.no_such_type'] as $unknown) {
        try {
            $connection->execute($echo, ['i' => new In\Struct($unknown, null), 'o' => new Out\Number()], $this->options());
            $this->fail("$unknown resolved.");
        } catch (QueryException $e) {
            expect($e->getMessage())->toStartWith("Bind 'i': the type ")
                ->and($e->getMessage())->toEndWith(' does not exist, or this user cannot see it.');
        }
    }
});

test('describing a type costs round trips only on its first use in a session', function (): void {
    $echoSql = 'begin :o := :i; end;';

    $pool = $this->ownPool() + ['maxConnections' => 1];
    $connection = $this->connect(...$pool);
    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();
    $echo = fn (Session $connection, string $name): mixed => $connection->execute(
        $echoSql,
        ['i' => new In\Collection($name, [[1], []]), 'o' => new Out\Collection($name)],
        $this->options(),
    )->outValues()['o'];

    try {
        $first = $counter->during($sid, static fn (): mixed => $echo($connection, 'fx_number_matrix'));
        $again = $counter->during($sid, static fn (): mixed => $echo($connection, 'FX_NUMBER_MATRIX'));
        $connection->close();
        $next = $this->connect(...$pool);
        expect($this->sid($next))->toBe($sid);
        $nextLoan = $counter->during($sid, static fn (): mixed => $echo($next, 'Fx_Number_Matrix'));
        $query = 'select fx_number_matrix(fx_number_list(1)) from dual';
        $column = $counter->during($sid, fn (): array => $this->rows($next, $query));
    } finally {
        $counter->close();
    }

    expect($first)->toBeGreaterThan(1)
        ->and($again)->toBe(1)
        ->and($nextLoan)->toBe(1)
        ->and($column)->toBe(1);
});

test('after alter type elsewhere a session fails loudly or stays consistent', function (): void {
    $echoSql = 'begin :o := :i; end;';

    $type = 'fx_evolving_' . bin2hex(random_bytes(3));
    $connection = $this->connect(standalone: true);
    $other = $this->connect();
    $connection->execute("create type $type as object (a number)", [], $this->options());

    try {
        $echo = fn (array $value): mixed => $connection->execute(
            $echoSql,
            ['i' => new In\Struct($type, struct($value)), 'o' => new Out\Struct($type)],
            $this->options(),
        )->outValues()['o'][0];
        expect($echo(['a' => 1]))->toBe(['A' => 1]);

        $other->execute("alter type $type add attribute (b varchar2(10)) cascade", [], $this->options());

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
    } finally {
        $other->execute("drop type $type force", [], $this->options());
    }
});

test('a varray over its limit fails on the server', function (): void {
    $echo = 'begin :o := :i; end;';

    try {
        $this->connect()->execute(
            $echo,
            ['i' => new In\Collection('fx_string_varray', array_fill(0, 11, 'x')), 'o' => new Out\Collection('fx_string_varray')],
            $this->options(),
        );
        $this->fail('The VARRAY took 11 elements.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(22165);
    }
});

/**
 * JSON, VECTOR and LONG cannot be attributes of an Object type at all.
 *
 * @return iterable<string, array{string, string}>
 */
dataset('laterAttributes', function () {
    yield 'CLOB' => ["fx_lob_holder(1, to_clob('x'), null, null)", 'CLOB'];
    yield 'NCLOB' => ["fx_lob_holder(1, null, to_nclob('x'), null)", 'NCLOB'];
    yield 'BLOB' => ["fx_lob_holder(1, null, null, to_blob(hextoraw('00')))", 'BLOB'];
    yield 'XMLTYPE' => ["fx_xml_holder(1, sys.xmltype('<a/>'))", 'XMLTYPE'];
    yield 'REF' => ['fx_ref_holder(1, null)', 'TDS type code 9'];
    yield 'BFILE' => ['fx_bfile_holder(1, null)', 'TDS type code 31'];
    yield 'a region-named TSTZ' => ["fx_tstz_holder(timestamp '2026-03-01 10:00:00 America/Sao_Paulo')", 'time zone region'];
});

test('a later attribute fails loudly on read', function (string $value, string $named): void {
    $connection = $this->connect();

    try {
        $this->rows($connection, "select $value as v from dual");
        $this->fail('The value was read.');
    } catch (UnsupportedException $unsupportedException) {
        expect($unsupportedException->getMessage())->toContain($named);
    }
    expect($this->value($connection, 'select 1 from dual'))->toBe(1);
})->with('laterAttributes');

test('a later attribute fails loudly on bind and its null reads as null', function (): void {
    $echo = 'begin :o := :i; end;';

    $connection = $this->connect();

    foreach (['doc' => 'x', 'ndoc' => 'x', 'bin' => "\x00"] as $attribute => $value) {
        $holder = ['id' => 1, 'doc' => null, 'ndoc' => null, 'bin' => null];
        $holder[$attribute] = $value;
        try {
            $connection->execute($echo, ['i' => new In\Struct('fx_lob_holder', $holder), 'o' => new Out\Struct('fx_lob_holder')], $this->options());
            $this->fail("A value for $attribute was bound.");
        } catch (UnsupportedException $e) {
            expect($e->getMessage())->toStartWith("Bind 'i', attribute " . strtoupper($attribute) . ': ')
                ->and($e->getMessage())->toEndWith(' values in an Object or Collection are not supported yet.');
        }
    }
    foreach (['fx_ref_holder', 'fx_bfile_holder'] as $type) {
        try {
            $connection->execute($echo, ['i' => new In\Struct($type, null), 'o' => new Out\Number()], $this->options());
            $this->fail("$type was bound.");
        } catch (UnsupportedException $e) {
            expect($e->getMessage())->toStartWith("Bind 'i': TDS type code");
        }
    }

    $out = $connection->execute(
        $echo,
        ['i' => new In\Struct('fx_lob_holder', ['id' => 1, 'doc' => null, 'ndoc' => null, 'bin' => null]), 'o' => new Out\Struct('fx_lob_holder')],
        $this->options(),
    );
    expect($out->outValues()['o'])->toBe([['ID' => 1, 'DOC' => null, 'NDOC' => null, 'BIN' => null]]);
});

test('a subtype instance fails loudly', function (): void {
    $echo = 'begin :o := :i; end;';

    $connection = $this->connect();

    expect($this->rows($connection, 'select animal from fx_animals where id = 1'))->toBe([['ANIMAL' => ['NAME' => 'cat']]]);
    try {
        $this->rows($connection, 'select animal from fx_animals where id = 2');
        $this->fail('The subtype instance was read.');
    } catch (UnsupportedException $unsupportedException) {
        expect($unsupportedException->getMessage())->toContain('FX_ANIMAL holds an instance of an object subtype');
    }
    try {
        $connection->execute($echo, ['i' => new In\Struct('fx_dog', ['name' => 'rex', 'breed' => 'lab']), 'o' => new Out\Number()], $this->options());
        $this->fail('The subtype instance was bound.');
    } catch (UnsupportedException $unsupportedException) {
        expect($unsupportedException->getMessage())->toStartWith("Bind 'i': ")
            ->and($unsupportedException->getMessage())->toContain('FX_DOG holds an instance of an object subtype');
    }
});

test('a collection of collections round trips', function (): void {
    $matrix = [[1, 2], [], null, [null]];
    $points = [5 => point(1, 2), -1 => null];

    $out = $this->connect()->execute(
        'begin :o := :i; :points_o := :points; end;',
        [
            'i' => new In\Collection('fx_number_matrix', $matrix), 'o' => new Out\Collection('fx_number_matrix'),
            'points' => new In\Indexed('fx_kinds.t_point_map', $points), 'points_o' => new Out\Indexed('fx_kinds.t_point_map'),
        ],
        $this->options(),
    )->outValues();

    expect($out['o'])->toBe([$matrix])
        ->and($out['points_o'])->toBe([[-1 => null, 5 => ['X' => 1, 'Y' => 2, 'LABEL' => null]]]);
});

test('an index by varchar2 bind fails loudly', function (): void {
    $connection = $this->connect();

    foreach ([
        // @phpstan-ignore argument.type (deliberately string keys)
        new In\Indexed('fx_kinds.t_string_map', ['a' => 'x']),
        new In\Indexed('fx_kinds.t_string_map', [1 => 'x']),
        new Out\Indexed('fx_kinds.t_string_map'),
        new Out\Collection('fx_kinds.t_string_map'),
    ] as $bind) {
        try {
            $connection->execute('begin :o := fx_kinds.string_map; end;', ['o' => $bind], $this->options());
            $this->fail('An INDEX BY VARCHAR2 bind ran.');
        } catch (UnsupportedException | UsageException $e) {
            expect($e->getMessage())->toContain('INDEX BY VARCHAR2');
        }
    }
});

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
    expect(array_keys($value))->each->toBeString();

    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * @return list<mixed>
 */
function elements(mixed $value): array
{
    assert(\is_array($value));
    assert(array_is_list($value));

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
 * The attributes of fx_all_scalars, keyed as Native shows them.
 *
 * @return array<string, null>
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
    return array_map(Temporals::shown(...), $values);
}

/** Stands for the current schema in data providers. */
function schemaPlaceholder(): string
{
    return '{schema}';
}
