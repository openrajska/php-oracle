<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Collection;
use Rajska\Oracle\Bind\Cursor;
use Rajska\Oracle\Bind\Indexed;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Struct;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\Type;
use Rajska\Oracle\ValueMapping;

/**
 * Conformance catalog, Objects, Collections and Associative arrays (#25):
 * plain PHP arrays both ways, as column values, OUT values and `Struct`,
 * `Collection` and `Indexed` binds (fixtures/sql/03_object.sql,
 * 04_pipe.sql, 12_object_kinds.sql).
 */
final class ObjectsTest extends ConformanceTestCase
{
    /** Echoes an IN bind into an OUT bind of the same type. */
    private const string ECHO = 'begin :o := :i; end;';

    /** fx_shapes, as rows: values, all NULL, empty Collections. */
    private const array SHAPES = [
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

    private const array NULL_POINT = ['X' => null, 'Y' => null, 'LABEL' => null];

    /**
     * Objects 1: an Object column is an array keyed by attribute name, a
     * Collection a list, a Collection of Objects a list of arrays; NULL,
     * all-NULL and empty stay apart (Objects 2).
     */
    #[Test]
    public function object_and_collection_columns_arrive_as_arrays(): void
    {
        self::assertSame(self::SHAPES, self::rows($this->connect(), 'select * from fx_shapes order by id'));
    }

    /**
     * Objects 1: the same holds in a REF CURSOR, an Implicit result and
     * `TABLE(pipelined_fn())`.
     */
    #[Test]
    public function the_same_arrays_arrive_through_a_ref_cursor_an_implicit_result_and_a_pipelined_function(): void
    {
        $connection = $this->connect();

        $execution = $connection->execute(
            'begin open :o for select * from fx_shapes order by id; end;',
            ['o' => Cursor::out()],
            self::options(),
        );
        $cursor = $execution->outValues()['o'][0];
        self::assertInstanceOf(DriverCursor::class, $cursor);
        self::assertSame(self::SHAPES, self::drain($cursor));

        $execution = $connection->execute(
            'declare c sys_refcursor; begin open c for select * from fx_shapes order by id; dbms_sql.return_result(c); end;',
            [],
            self::options(),
        );
        self::assertSame(self::SHAPES, self::drain($execution->implicitResults()[0]));

        self::assertSame(
            [
                ['P' => ['X' => 1, 'Y' => 1, 'LABEL' => 'P1']],
                ['P' => ['X' => 2, 'Y' => 4, 'LABEL' => 'P2']],
            ],
            self::rows($connection, 'select value(t) as p from table(fx_pipe.points(2)) t'),
        );
    }

    /**
     * Objects 1: attribute keys follow `keyCase` (as written for a quoted
     * attribute under Native), and the row shape does not reach inside an
     * Object.
     */
    #[Test]
    public function attribute_keys_follow_key_case(): void
    {
        $connection = $this->connect();

        self::assertSame(
            [[['x' => '1.5', 'y' => -2, 'label' => 'center'], [['x' => 0, 'y' => 0, 'label' => 'a']]]],
            self::rows(
                $connection,
                'select center, fx_point_list(fx_point(0, 0, \'a\')) from fx_shapes where id = 1',
                options: self::options(rowShape: RowShape::List, keyCase: KeyCase::Lower),
            ),
        );
        self::assertSame(
            [['P' => ['x' => 1, 'Y' => 2]]],
            self::rows($connection, 'select "FX_Mixed_Point"(1, 2) as p from dual'),
        );
    }

    /**
     * An Object or Collection column is described with its type: `Column`'s
     * `objectType` is the canonical name, and nested table and VARRAY are
     * both `collection`.
     */
    #[Test]
    public function object_and_collection_columns_describe_their_type(): void
    {
        $connection = $this->connect();
        $schema = self::value($connection, "select sys_context('userenv', 'current_schema') from dual");
        self::assertIsString($schema);
        $cursor = $connection->execute('select center, tags, outline from fx_shapes', [], self::options())->cursor();
        self::assertNotNull($cursor);

        $columns = array_map(
            static fn(array $column): array => [$column['type'], $column['objectType']],
            $cursor->columns(),
        );

        self::assertSame(
            [
                [Type::Object->value, "$schema.FX_POINT"],
                [Type::Collection->value, "$schema.FX_STRING_VARRAY"],
                [Type::Collection->value, "$schema.FX_POINT_LIST"],
            ],
            $columns,
        );
        $cursor->close();
    }

    /**
     * Objects 3: attributes follow every `ValueMapping`, as columns of
     * their type do. fx_all_scalars row 1 holds every attribute type of
     * the coverage table; row 2 is all NULL.
     */
    #[Test]
    public function attributes_follow_every_value_mapping(): void
    {
        Temporals::inZone('America/Sao_Paulo', function (): void {
            $connection = $this->connect();
            $sql = 'select value from fx_all_scalars_tab order by id';
            $zone = 'America/Sao_Paulo';

            $default = self::rows($connection, $sql);
            self::assertSame(
                [
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
                ],
                self::shown(self::struct($default[0]['VALUE'])),
            );
            self::assertSame(array_fill_keys(array_keys(self::allScalars()), null), $default[1]['VALUE']);

            $float = self::rows($connection, $sql, options: self::options(values: new ValueMapping(numbers: NumberMode::Float)));
            self::assertSame(12.5, self::struct($float[0]['VALUE'])['C_NUMBER']);

            $string = self::struct(self::rows($connection, $sql, options: self::options(values: new ValueMapping(temporals: TemporalMode::String)))[0]['VALUE']);
            self::assertSame(
                ['2026-03-01 10:00:00', '2026-03-01 10:00:00.123456789', '2026-03-01 10:00:00.123456789 +05:45', '2026-03-01 07:00:00.123456789 -03:00'],
                [$string['C_DATE'], $string['C_TS'], $string['C_TSTZ'], $string['C_TSLTZ']],
            );
            // INTERVALs stay objects.
            self::assertInstanceOf(DateInterval::class, $string['C_IDS']);

            $lower = self::rows($connection, $sql, options: self::options(keyCase: KeyCase::Lower));
            self::assertSame(array_map(strtolower(...), array_keys(self::allScalars())), array_keys(self::struct($lower[0]['value'])));
        });
    }

    /**
     * Objects 3, BOOLEAN: the server writes TRUE in a BOOLEAN attribute as
     * `00 00 00 01` when PL/SQL builds the Object and as `01 00 00 00`
     * when SQL builds it; both read TRUE (fork patch, #71).
     */
    #[Test]
    public function a_boolean_attribute_reads_as_stored(): void
    {
        $connection = $this->connect();
        $built = static fn(string $flag): string => "fx_all_scalars(null, null, null, null, null, null, null, null, $flag, null, null, null, null, null, null)";

        foreach (['true' => true, 'false' => false, 'null' => null] as $flag => $expected) {
            $plsql = $connection->execute("begin :o := {$built($flag)}; end;", ['o' => Struct::out('fx_all_scalars')], self::options());
            self::assertSame($expected, self::struct($plsql->outValues()['o'][0])['C_BOOLEAN'], "PL/SQL $flag");

            $sql = self::struct(self::value($connection, "select {$built($flag)} from dual"));
            self::assertSame($expected, $sql['C_BOOLEAN'], "SQL $flag");
        }
    }

    /**
     * Objects 4: Struct, Collection and Indexed round-trip IN, OUT and IN
     * OUT, through PL/SQL parameters of their type.
     */
    #[Test]
    public function struct_collection_and_indexed_round_trip_in_out_and_in_out(): void
    {
        $connection = $this->connect();

        $point = $connection->execute(
            'begin :o := fx_object.make_point(:x, :y, :label); fx_object.move_point(:io, 1, 2); :n := fx_object.count_vertices(:polygon); end;',
            [
                'x' => 1, 'y' => '0.5', 'label' => 'p',
                'o' => Struct::out('fx_point'),
                'io' => Struct::inOut('fx_point', ['x' => 1, 'y' => 1, 'label' => 'moved']),
                'polygon' => Struct::in('fx_polygon', ['name' => 'tri', 'vertices' => [self::point(0, 0), self::point(1, 0), self::point(0, 1)]]),
                'n' => Number::out(),
            ],
            self::options(),
        )->outValues();
        self::assertSame([['X' => 1, 'Y' => '0.5', 'LABEL' => 'p']], $point['o']);
        self::assertSame([['X' => 2, 'Y' => 3, 'LABEL' => 'moved']], $point['io']);
        self::assertSame([3], $point['n']);

        $collections = $connection->execute(
            'begin fx_object.reverse_numbers(:numbers, :reversed); fx_object.append_tag(:tags, \'z\'); :sum := fx_object.sum_numbers(:numbers); end;',
            [
                'numbers' => Collection::in('fx_number_list', [1, 2.5, -3]),
                'reversed' => Collection::out('fx_number_list'),
                'tags' => Collection::inOut('fx_string_varray', ['x', 'y']),
                'sum' => Number::out(),
            ],
            self::options(),
        )->outValues();
        self::assertSame([[-3, '2.5', 1]], $collections['reversed']);
        self::assertSame([['x', 'y', 'z']], $collections['tags']);
        self::assertSame(['0.5'], $collections['sum']);

        $map = $connection->execute(
            'begin fx_object.fill_map(3, :filled); :sum := fx_object.sum_map(:map); :io := :io; end;',
            [
                'filled' => Indexed::out('fx_object.t_number_map'),
                'map' => Indexed::in('fx_object.t_number_map', [7 => 1, -2 => 2]),
                'io' => Indexed::inOut('fx_object.t_number_map', [1 => 1]),
                'sum' => Number::out(),
            ],
            self::options(),
        )->outValues();
        self::assertSame([[10 => 1, 20 => 2, 30 => 3]], $map['filled']);
        self::assertSame([3], $map['sum']);
        self::assertSame([[1 => 1]], $map['io']);
    }

    /**
     * Objects 4: Struct and Collection bind into SQL too, and come back
     * from SQL `RETURNING … INTO`, one value per returned row (#73), as
     * from `RETURNING … INTO` inside a PL/SQL block. An atomic NULL is
     * `null`.
     */
    #[Test]
    public function struct_and_collection_bind_into_sql_and_come_back_from_returning_into(): void
    {
        $connection = $this->connect();
        $options = self::options(autocommit: false);
        $binds = [
            'id' => 99,
            'center' => Struct::in('fx_point', ['x' => 5, 'y' => 6, 'label' => 'new']),
            'tags' => Collection::in('fx_string_varray', ['t']),
            'outline' => Collection::in('fx_point_list', []),
        ];
        $insert = 'insert into fx_shapes (id, center, tags, outline) values (:id, :center, :tags, :outline)';

        $returned = $connection->execute(
            "$insert returning center, tags into :center_o, :tags_o",
            $binds + ['center_o' => Struct::out('fx_point'), 'tags_o' => Collection::out('fx_string_varray')],
            $options,
        );
        self::assertSame(1, $returned->rowCount());
        self::assertSame([['X' => 5, 'Y' => 6, 'LABEL' => 'new']], $returned->outValues()['center_o']);
        self::assertSame([['t']], $returned->outValues()['tags_o']);
        $connection->execute('delete from fx_shapes where id = 99', [], $options);

        $updated = $connection->execute(
            'update fx_shapes set id = id where id in (1, 2, 3) returning id, center, tags into :id_o, :center_o, :tags_o',
            ['id_o' => Number::out(), 'center_o' => Struct::out('fx_point'), 'tags_o' => Collection::out('fx_string_varray')],
            $options,
        );
        $rows = array_map(null, $updated->outValues()['id_o'], $updated->outValues()['center_o'], $updated->outValues()['tags_o']);
        usort($rows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame([
            [1, ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'], ['red', "caf\u{e9}"]],
            [2, null, null],
            [3, ['X' => null, 'Y' => null, 'LABEL' => null], []],
        ], $rows);

        // A nested table column comes back as a reference to its storage
        // table, which is not decoded: loud, and the Connection stays usable.
        $returnOutline = 'update fx_shapes set id = id where id = 1 returning outline into :outline_o';
        foreach ([$returnOutline, "begin $returnOutline; end;"] as $sql) {
            try {
                $connection->execute($sql, ['outline_o' => Collection::out('fx_point_list')], $options);
                self::fail('RETURNING INTO a nested table column ran.');
            } catch (UnsupportedException $e) {
                self::assertStringContainsString('database object stored in a LOB', $e->getMessage());
            }
        }

        $block = $connection->execute(
            "begin $insert returning center, tags into :center_o, :tags_o; end;",
            $binds + ['center_o' => Struct::out('fx_point'), 'tags_o' => Collection::out('fx_string_varray')],
            $options,
        );
        self::assertSame([['X' => 5, 'Y' => 6, 'LABEL' => 'new']], $block->outValues()['center_o']);
        self::assertSame([['t']], $block->outValues()['tags_o']);
        $connection->execute('delete from fx_shapes where id = 99', [], $options);

        self::assertSame(1, $connection->execute($insert, $binds, $options)->rowCount());
        self::assertSame(
            [['CENTER' => ['X' => 5, 'Y' => 6, 'LABEL' => 'new'], 'TAGS' => ['t'], 'OUTLINE' => []]],
            self::rows($connection, 'select center, tags, outline from fx_shapes where id = 99', options: $options),
        );
        $connection->rollback();
    }

    /**
     * Objects 4: a fetched value binds back unchanged, under the default
     * mapping and under `TemporalMode::String` and `NumberMode::Float`.
     *
     * @return iterable<string, array{ValueMapping}>
     */
    public static function mappings(): iterable
    {
        yield 'default' => [new ValueMapping()];
        yield 'String temporals, Float numbers' => [new ValueMapping(numbers: NumberMode::Float, temporals: TemporalMode::String)];
    }

    #[Test]
    #[DataProvider('mappings')]
    public function a_fetched_value_binds_back_unchanged(ValueMapping $values): void
    {
        Temporals::inZone('Asia/Kathmandu', function () use ($values): void {
            $connection = $this->connect();
            $options = self::options(values: $values);
            $value = self::struct(self::rows($connection, 'select value from fx_all_scalars_tab where id = 1', options: $options)[0]['VALUE']);
            $shapes = self::rows($connection, 'select center, tags, outline from fx_shapes where id = 1', options: $options)[0];

            $back = $connection->execute(
                'begin :o := :i; :center_o := :center; :tags_o := :tags; :outline_o := :outline; end;',
                [
                    'i' => Struct::in('fx_all_scalars', $value), 'o' => Struct::out('fx_all_scalars'),
                    'center' => Struct::in('fx_point', self::struct($shapes['CENTER'])), 'center_o' => Struct::out('fx_point'),
                    'tags' => Collection::in('fx_string_varray', self::elements($shapes['TAGS'])), 'tags_o' => Collection::out('fx_string_varray'),
                    'outline' => Collection::in('fx_point_list', self::elements($shapes['OUTLINE'])), 'outline_o' => Collection::out('fx_point_list'),
                ],
                $options,
            )->outValues();

            self::assertSame(self::shown($value), self::shown(self::struct($back['o'][0])));
            self::assertSame(
                [$shapes['CENTER'], $shapes['TAGS'], $shapes['OUTLINE']],
                [$back['center_o'][0], $back['tags_o'][0], $back['outline_o'][0]],
            );
        });
    }

    /**
     * Objects 4: values reach the server as the attribute types store them:
     * the Wall clock of a DATE, the Instant and offset of a TSTZ, the
     * Instant of a TSLTZ, an exact NUMBER from a float.
     */
    #[Test]
    public function bound_attributes_reach_the_server_as_their_types_store_them(): void
    {
        $value = array_fill_keys(array_keys(self::allScalars()), null);
        $value['C_NUMBER'] = 0.1;
        $value['C_DATE'] = new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('Asia/Tokyo'));
        $value['C_TSTZ'] = new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('-03:30'));
        $value['C_TSLTZ'] = new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('+02:00'));
        $value['C_BOOLEAN'] = true;
        $value['C_NCHAR'] = 'ç';

        $text = $this->connect()->execute(
            "declare v fx_all_scalars := :i; begin :s := to_char(v.c_number) || '|' || to_char(v.c_date, 'YYYY-MM-DD HH24:MI:SS') || '|' || to_char(v.c_tstz, 'YYYY-MM-DD HH24:MI:SS.FF3 TZH:TZM') || '|' || to_char(sys_extract_utc(v.c_tsltz), 'YYYY-MM-DD HH24:MI:SS') || '|' || case when v.c_boolean then 'T' else 'F' end || '|' || v.c_nchar || '|'; end;",
            ['i' => Struct::in('fx_all_scalars', $value), 's' => Varchar::out()],
            self::options(),
        )->outValues()['s'][0];

        // A CHAR or NCHAR attribute is stored as given, unpadded (C2).
        self::assertSame('.1|2026-03-01 10:00:00|2026-03-01 10:00:00.500 -03:30|2026-03-01 08:00:00|T|ç|', $text);
    }

    /**
     * Objects 2 and 4: NULL semantics on IN and OUT. `in(null)` is a typed
     * NULL; `[]` an empty Collection; an Object of NULLs is not NULL; NULL
     * elements are kept.
     */
    #[Test]
    public function null_empty_and_all_null_values_stay_apart_in_and_out(): void
    {
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
                'null_list' => Collection::in('fx_number_list', null),
                'empty_list' => Collection::in('fx_number_list', []),
                'with_nulls' => Collection::in('fx_number_list', [null, 1, null]),
                'null_point' => Struct::in('fx_point', null),
                'all_null_point' => Struct::in('fx_point', self::NULL_POINT),
                'null_sum' => Number::out(), 'empty_sum' => Number::out(),
                'null_is_null' => Varchar::out(), 'all_null_is_null' => Varchar::out(),
                'reversed_null' => Collection::out('fx_number_list'),
                'reversed_nulls' => Collection::out('fx_number_list'),
                'point_o' => Struct::out('fx_point'), 'null_point_o' => Struct::out('fx_point'),
            ],
            self::options(),
        )->outValues();

        self::assertSame(
            [
                'null_sum' => [null], 'empty_sum' => [0],
                'null_is_null' => ['Y'], 'all_null_is_null' => ['N'],
                'reversed_null' => [null], 'reversed_nulls' => [[null, 1, null]],
                'point_o' => [self::NULL_POINT], 'null_point_o' => [null],
            ],
            array_intersect_key($out, array_flip(['null_sum', 'empty_sum', 'null_is_null', 'all_null_is_null', 'reversed_null', 'reversed_nulls', 'point_o', 'null_point_o'])),
        );
    }

    /**
     * Objects 5: an Associative array keeps sparse and negative keys, in key
     * order; a nested table with deleted elements gives the rest as a list.
     */
    #[Test]
    public function indexed_keeps_sparse_and_negative_keys_in_order_and_a_sparse_nested_table_gives_a_list(): void
    {
        $out = $this->connect()->execute(
            'begin :o := :i; :sparse := fx_kinds.sparse_numbers; end;',
            [
                'i' => Indexed::in('fx_object.t_number_map', [10 => 2, -5 => 1, 3 => 3, -2147483648 => 4, 2147483647 => 5]),
                'o' => Indexed::out('fx_object.t_number_map'),
                'sparse' => Collection::out('fx_number_list'),
            ],
            self::options(),
        )->outValues();

        self::assertSame([-2147483648 => 4, -5 => 1, 3 => 3, 10 => 2, 2147483647 => 5], $out['o'][0]);
        self::assertSame([1, 3, 5], $out['sparse'][0]);
    }

    /**
     * Objects 6: `TABLE(:ids)` with 10,000 ids, over SYS.ODCINUMBERLIST,
     * SYS.ODCIVARCHAR2LIST and a user type, joined to a table.
     *
     * @return iterable<string, array{string, list<int|string>}>
     */
    public static function idLists(): iterable
    {
        yield 'SYS.ODCINUMBERLIST' => ['sys.odcinumberlist', range(1, 10000)];
        yield 'SYS.ODCIVARCHAR2LIST' => ['sys.odcivarchar2list', array_map(strval(...), range(1, 10000))];
        yield 'a user type' => ['fx_number_list', range(1, 10000)];
    }

    /**
     * @param list<int|string> $ids
     */
    #[Test]
    #[DataProvider('idLists')]
    public function table_of_a_collection_bind_takes_ten_thousand_ids(string $type, array $ids): void
    {
        $connection = $this->connect();

        self::assertSame(
            [['N' => 10000, 'S' => 50005000]],
            self::rows($connection, 'select count(*) as n, sum(column_value) as s from table(:ids)', ['ids' => Collection::in($type, $ids)]),
        );
        // fx_items holds ids 1 to 1000.
        self::assertSame(
            [['N' => 1000, 'S' => 500500]],
            self::rows(
                $connection,
                'select count(*) as n, sum(i.id) as s from fx_items i join table(:ids) t on t.column_value = i.id',
                ['ids' => Collection::in($type, $ids)],
            ),
        );
    }

    /**
     * Objects 8: each mistake fails before any Round trip (the types are
     * described already), naming the fix.
     *
     * @return iterable<string, array{Bind, string}>
     */
    public static function mistakes(): iterable
    {
        $point = ['x' => 1, 'y' => 2, 'label' => null];
        // @phpstan-ignore argument.type (deliberately the wrong shape)
        yield 'a Collection that is not a list' => [Collection::in('fx_number_list', [1 => 1, 0 => 2]), "Bind 'i': a Collection is a list (keys 0, 1, 2… in order), but key 1 is not; pass array_values() of it, or bind an Associative array with Indexed::in()."];
        // @phpstan-ignore argument.type (deliberately the wrong shape)
        yield 'a Collection with string keys' => [Collection::in('fx_number_list', ['a' => 1]), "Bind 'i': a Collection is a list (keys 0, 1, 2… in order), but key 'a' is not;"];
        yield 'a nested Collection that is not a list' => [Struct::in('fx_polygon', ['name' => 'p', 'vertices' => [2 => $point]]), "Bind 'i', attribute VERTICES: a Collection is a list"];
        // @phpstan-ignore argument.type (deliberately the wrong shape)
        yield 'an Indexed string key' => [Indexed::in('fx_object.t_number_map', ['a' => 1]), "Bind 'i': an Associative array has int keys, but key 'a' is a string; INDEX BY VARCHAR2 is not supported yet."];
        yield 'an Indexed key beyond 32 bits' => [Indexed::in('fx_object.t_number_map', [2147483648 => 1]), "Bind 'i': key 2147483648 is outside the 32-bit range of an Associative array's keys (INDEX BY PLS_INTEGER)."];
        yield 'an unknown Struct key' => [Struct::in('fx_point', $point + ['z' => 1]), "Bind 'i': " . self::schemaPlaceholder() . ".FX_POINT has no attribute 'z'; its attributes are X, Y, LABEL."];
        yield 'a missing Struct key' => [Struct::in('fx_point', ['x' => 1]), "Bind 'i': the value has no key for attributes Y, LABEL of " . self::schemaPlaceholder() . '.FX_POINT; give every attribute, null for a NULL one.'];
        yield 'Struct keys differing only in case' => [Struct::in('fx_point', $point + ['X' => 1]), "Bind 'i': keys 'x' and 'X' differ only in case and name the same attribute; keep one of them."];
        // @phpstan-ignore argument.type (deliberately the wrong shape)
        yield 'a list as a Struct' => [Struct::in('fx_point', [1, 2, null]), "Bind 'i': an Object is keyed by attribute name, but key 0 is an int (is the array a list?)."];
        yield 'Struct of a Collection type' => [Struct::in('fx_number_list', ['x' => 1]), "Bind 'i': " . self::schemaPlaceholder() . '.FX_NUMBER_LIST is a Collection type (nested table or VARRAY), not an Object type; use Collection, not Struct.'];
        yield 'Collection of an Object type' => [Collection::in('fx_point', []), "Bind 'i': " . self::schemaPlaceholder() . '.FX_POINT is an Object type, not a Collection type (nested table or VARRAY); use Struct, not Collection.'];
        yield 'Collection of an Associative array type' => [Collection::in('fx_object.t_number_map', []), "Bind 'i': " . self::schemaPlaceholder() . '.FX_OBJECT.T_NUMBER_MAP is an Associative array type (INDEX BY PLS_INTEGER), not a Collection type (nested table or VARRAY); use Indexed, not Collection.'];
        yield 'Indexed of a nested table type' => [Indexed::in('fx_number_list', []), "Bind 'i': " . self::schemaPlaceholder() . '.FX_NUMBER_LIST is a Collection type (nested table or VARRAY), not an Associative array type (INDEX BY PLS_INTEGER); use Collection, not Indexed.'];
        yield 'an invalid type name' => [Struct::in('fx point', $point), "Bind 'i': the type name 'fx point' is not valid: each dot-separated part must be an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes."];
        yield 'a type name with four parts' => [Struct::in('a.b.c.d', $point), "Bind 'i': the type name 'a.b.c.d' has 4 parts; a type name has at most 3: schema.package.type."];
        yield 'text for a NUMBER' => [Struct::in('fx_point', ['x' => 'one'] + $point), "Bind 'i', attribute X: a NUMBER takes a decimal literal with '.', such as '12.30', as text."];
        yield 'an array for a NUMBER' => [Struct::in('fx_point', ['x' => []] + $point), "Bind 'i', attribute X: a NUMBER holds an int, a float or a decimal string or null, not array."];
        yield 'NaN for a NUMBER' => [Collection::in('fx_number_list', [\NAN]), "Bind 'i', element 0: this float is outside NUMBER's range (NaN, INF, or beyond 1e-130..1e126)."];
        yield 'a string too long' => [Struct::in('fx_point', ['label' => str_repeat('x', 31)] + $point), "Bind 'i': cannot store a value of 31 bytes (the maximum is 30) in attribute LABEL of type VARCHAR2(30)"];
        yield 'an int for a VARCHAR2 element' => [Collection::in('fx_string_varray', [1]), "Bind 'i', element 0: a VARCHAR2(100) holds a string or null, not int."];
        yield 'a string for a timestamp' => [Struct::in('fx_all_scalars', ['C_DATE' => 'today'] + array_fill_keys(array_keys(self::allScalars()), null)), "Bind 'i', attribute C_DATE: the text is not a DATE as TemporalMode::String writes it, such as '2026-03-01 10:00:00'."];
        yield 'a nested Object of the wrong shape' => [Struct::in('fx_polygon', ['name' => 'p', 'vertices' => [1]]), "Bind 'i', attribute VERTICES, element 0: the value must be an array keyed by attribute name or null."];
    }

    /**
     * Objects 8: these fail before any Round trip with UsageException.
     *
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('mistakes')]
    public function a_mistake_fails_before_any_round_trip_naming_the_fix(Bind $bind, string $message): void
    {
        $connection = $this->connect();
        $schema = self::value($connection, "select sys_context('userenv', 'current_schema') from dual");
        self::assertIsString($schema);
        $message = str_replace(self::schemaPlaceholder(), $schema, $message);
        \assert($message !== '');
        // Describe every type the cases name, so a case costs no Round trip.
        $connection->execute(
            'begin :a := :a; :b := :b; :c := :c; :d := :d; :e := :e; :f := :f; end;',
            [
                'a' => Struct::out('fx_point'), 'b' => Collection::out('fx_number_list'), 'c' => Indexed::out('fx_object.t_number_map'),
                'd' => Struct::out('fx_polygon'), 'e' => Collection::out('fx_string_varray'), 'f' => Struct::out('fx_all_scalars'),
            ],
            self::options(),
        );
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();

        try {
            $trips = $counter->during($sid, static function () use ($connection, $bind, $message): void {
                try {
                    $connection->execute(self::ECHO, ['i' => $bind, 'o' => Number::out()], self::options());
                    self::fail('The bind was accepted.');
                } catch (UsageException $e) {
                    self::assertStringStartsWith($message, $e->getMessage());
                }
            });
        } finally {
            $counter->close();
        }
        self::assertSame(0, $trips);
    }

    /**
     * Objects 9: type names follow Oracle's identifier rules. Unquoted
     * parts are case-insensitive, a quoted part is kept; the current
     * schema, a synonym and `PKG.TYPE` resolve.
     *
     * @return iterable<string, array{string}>
     */
    public static function pointNames(): iterable
    {
        yield 'lowercase' => ['fx_point'];
        yield 'uppercase' => ['FX_POINT'];
        yield 'quoted' => ['"FX_POINT"'];
        yield 'mixed case' => ['Fx_Point'];
        yield 'schema-qualified' => [self::schemaPlaceholder() . '.fx_point'];
        yield 'a synonym' => ['fx_point_alias'];
    }

    #[Test]
    #[DataProvider('pointNames')]
    public function a_type_name_resolves_under_oracle_identifier_rules(string $name): void
    {
        $connection = $this->connect();
        $schema = self::value($connection, "select sys_context('userenv', 'current_schema') from dual");
        self::assertIsString($schema);
        $name = str_replace(self::schemaPlaceholder(), strtolower($schema), $name);

        $out = $connection->execute(self::ECHO, ['i' => Struct::in($name, ['X' => 1, 'Y' => 2, 'LABEL' => 'a']), 'o' => Struct::out($name)], self::options());

        self::assertSame([['X' => 1, 'Y' => 2, 'LABEL' => 'a']], $out->outValues()['o']);
    }

    #[Test]
    public function quoted_names_and_package_types_resolve_and_an_unknown_type_fails(): void
    {
        $connection = $this->connect();

        $out = $connection->execute(
            'begin :o := :i; :r := :r; :n := :n; end;',
            [
                'i' => Struct::in('"FX_Mixed_Point"', ['x' => 1, 'y' => 2]),
                'o' => Struct::out('"FX_Mixed_Point"'),
                'r' => Struct::inOut('fx_kinds.t_record', ['id' => -7, 'name' => 'r', 'flag' => true]),
                'n' => Collection::inOut('fx_kinds.t_numbers', [1, 2]),
            ],
            self::options(),
        )->outValues();
        self::assertSame([['x' => 1, 'Y' => 2]], $out['o']);
        self::assertSame([['ID' => -7, 'NAME' => 'r', 'FLAG' => true]], $out['r']);
        self::assertSame([[1, 2]], $out['n']);

        foreach (['fx_mixed_point', 'fx_no_such_type', 'fx_object.no_such_type'] as $unknown) {
            try {
                $connection->execute(self::ECHO, ['i' => Struct::in($unknown, null), 'o' => Number::out()], self::options());
                self::fail("$unknown resolved.");
            } catch (QueryException $e) {
                self::assertStringStartsWith("Bind 'i': the type ", $e->getMessage());
                self::assertStringEndsWith(' does not exist, or this user cannot see it.', $e->getMessage());
            }
        }
    }

    /**
     * Objects 10: describing a type costs Round trips only on its first use
     * in a Session; `hr.address` and `HR.ADDRESS` are one type, and the
     * Session keeps the description for its next loan.
     */
    #[Test]
    public function describing_a_type_costs_round_trips_only_on_its_first_use_in_a_session(): void
    {
        $pool = self::ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$pool);
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();
        $echo = static fn(DriverConnection $connection, string $name): mixed => $connection->execute(
            self::ECHO,
            ['i' => Collection::in($name, [[1], []]), 'o' => Collection::out($name)],
            self::options(),
        )->outValues()['o'];

        try {
            $first = $counter->during($sid, static fn(): mixed => $echo($connection, 'fx_number_matrix'));
            $again = $counter->during($sid, static fn(): mixed => $echo($connection, 'FX_NUMBER_MATRIX'));
            $connection->close();
            $next = $this->connect(...$pool);
            self::assertSame($sid, self::sid($next));
            $nextLoan = $counter->during($sid, static fn(): mixed => $echo($next, 'Fx_Number_Matrix'));
            $query = 'select fx_number_matrix(fx_number_list(1)) from dual';
            $column = $counter->during($sid, static fn(): array => self::rows($next, $query));
        } finally {
            $counter->close();
        }

        self::assertGreaterThan(1, $first);
        self::assertSame(1, $again);
        self::assertSame(1, $nextLoan);
        self::assertSame(1, $column);
    }

    /**
     * Objects 11: after `ALTER TYPE` in another Session, a Session that
     * described the type keeps the old shape (never invalidated): it fails
     * loudly, or stays consistent with that shape, never corrupted.
     *
     * Observed on 26ai, recorded for Known limitations:
     * - in the Session that described the type before, binding the old
     *   shape fails on the server (ORA-03101), the new attribute is unknown
     *   (`UsageException`), and reading a value fails on the client
     *   (`QueryException`, the image carries the type's new version), every
     *   time; the Session stays usable;
     * - a Session opened after the change reads and binds the new shape (#72).
     */
    #[Test]
    public function after_alter_type_elsewhere_a_session_fails_loudly_or_stays_consistent(): void
    {
        $type = 'fx_evolving_' . bin2hex(random_bytes(3));
        $connection = $this->connect(standalone: true);
        $other = $this->connect();
        $connection->execute("create type $type as object (a number)", [], self::options());

        try {
            $echo = static fn(array $value): mixed => $connection->execute(
                self::ECHO,
                ['i' => Struct::in($type, self::struct($value)), 'o' => Struct::out($type)],
                self::options(),
            )->outValues()['o'][0];
            self::assertSame(['A' => 1], $echo(['a' => 1]));

            $other->execute("alter type $type add attribute (b varchar2(10)) cascade", [], self::options());

            $fresh = $this->connect(standalone: true);
            $outcomes = [];
            $messages = [];
            foreach ([
                'bind the old shape' => static fn(): mixed => $echo(['a' => 2]),
                'bind the new shape' => static fn(): mixed => $echo(['a' => 3, 'b' => 'x']),
                'fetch a value' => fn(): mixed => self::value($connection, "select $type(4, 'y') from dual"),
                'fetch a value again' => fn(): mixed => self::value($connection, "select $type(4, 'y') from dual"),
                'still usable' => fn(): mixed => self::value($connection, 'select 1 from dual'),
                'a new Session fetches' => fn(): mixed => self::value($fresh, "select $type(5, 'z') from dual"),
                'a new Session binds' => fn(): mixed => $fresh->execute(
                    "declare v $type := :i; begin :o := v.a || v.b; end;",
                    ['i' => Struct::in($type, ['a' => 6, 'b' => 'w']), 'o' => Varchar::out()],
                    self::options(),
                )->outValues()['o'][0],
                'a new Session binds OUT' => fn(): mixed => $fresh->execute(
                    "begin :o := $type(7, 'v'); end;",
                    ['o' => Struct::out($type)],
                    self::options(),
                )->outValues()['o'][0],
            ] as $case => $run) {
                try {
                    $outcomes[$case] = $run();
                } catch (OracleException $e) {
                    $outcomes[$case] = $e::class . ($e->getCode() === 0 ? '' : ' ' . $e->getCode());
                    $messages[$case] = $e->getMessage();
                }
            }

            self::assertSame(
                [
                    'bind the old shape' => QueryException::class . ' 3101',
                    'bind the new shape' => UsageException::class,
                    'fetch a value' => QueryException::class,
                    'fetch a value again' => QueryException::class,
                    'still usable' => 1,
                    'a new Session fetches' => ['A' => 5, 'B' => 'z'],
                    'a new Session binds' => '6w',
                    'a new Session binds OUT' => ['A' => 7, 'B' => 'v'],
                ],
                $outcomes,
            );
            self::assertStringContainsString(
                strtoupper($type) . ' has changed since this connection described it',
                $messages['fetch a value'] ?? '',
            );
        } finally {
            $other->execute("drop type $type force", [], self::options());
        }
    }

    /**
     * Objects 12: a VARRAY over its limit fails on the server, ORA-22165.
     */
    #[Test]
    public function a_varray_over_its_limit_fails_on_the_server(): void
    {
        try {
            $this->connect()->execute(
                self::ECHO,
                ['i' => Collection::in('fx_string_varray', array_fill(0, 11, 'x')), 'o' => Collection::out('fx_string_varray')],
                self::options(),
            );
            self::fail('The VARRAY took 11 elements.');
        } catch (QueryException $e) {
            self::assertSame(22165, $e->getCode());
        }
    }

    /**
     * Objects 12: each later attribute type fails loudly on read and on
     * bind; NULL reads as null. JSON, VECTOR and LONG cannot be attributes
     * of an Object type at all.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function laterAttributes(): iterable
    {
        yield 'CLOB' => ["fx_lob_holder(1, to_clob('x'), null, null)", 'CLOB'];
        yield 'NCLOB' => ["fx_lob_holder(1, null, to_nclob('x'), null)", 'NCLOB'];
        yield 'BLOB' => ["fx_lob_holder(1, null, null, to_blob(hextoraw('00')))", 'BLOB'];
        yield 'XMLTYPE' => ["fx_xml_holder(1, sys.xmltype('<a/>'))", 'XMLTYPE'];
        yield 'REF' => ['fx_ref_holder(1, null)', 'TDS type code 9'];
        yield 'BFILE' => ['fx_bfile_holder(1, null)', 'TDS type code 31'];
        yield 'a region-named TSTZ' => ["fx_tstz_holder(timestamp '2026-03-01 10:00:00 America/Sao_Paulo')", 'time zone region'];
    }

    #[Test]
    #[DataProvider('laterAttributes')]
    public function a_later_attribute_fails_loudly_on_read(string $value, string $named): void
    {
        $connection = $this->connect();

        try {
            self::rows($connection, "select $value as v from dual");
            self::fail('The value was read.');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString($named, $e->getMessage());
        }
        // The Connection is still usable.
        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    #[Test]
    public function a_later_attribute_fails_loudly_on_bind_and_its_null_reads_as_null(): void
    {
        $connection = $this->connect();

        foreach (['doc' => 'x', 'ndoc' => 'x', 'bin' => "\x00"] as $attribute => $value) {
            $holder = ['id' => 1, 'doc' => null, 'ndoc' => null, 'bin' => null];
            $holder[$attribute] = $value;
            try {
                $connection->execute(self::ECHO, ['i' => Struct::in('fx_lob_holder', $holder), 'o' => Struct::out('fx_lob_holder')], self::options());
                self::fail("A value for $attribute was bound.");
            } catch (UnsupportedException $e) {
                self::assertStringStartsWith("Bind 'i', attribute " . strtoupper($attribute) . ': ', $e->getMessage());
                self::assertStringEndsWith(' values in an Object or Collection are not supported yet.', $e->getMessage());
            }
        }
        foreach (['fx_ref_holder', 'fx_bfile_holder'] as $type) {
            try {
                $connection->execute(self::ECHO, ['i' => Struct::in($type, null), 'o' => Number::out()], self::options());
                self::fail("$type was bound.");
            } catch (UnsupportedException $e) {
                self::assertStringStartsWith("Bind 'i': TDS type code", $e->getMessage());
            }
        }

        $out = $connection->execute(
            self::ECHO,
            ['i' => Struct::in('fx_lob_holder', ['id' => 1, 'doc' => null, 'ndoc' => null, 'bin' => null]), 'o' => Struct::out('fx_lob_holder')],
            self::options(),
        );
        self::assertSame([['ID' => 1, 'DOC' => null, 'NDOC' => null, 'BIN' => null]], $out->outValues()['o']);
    }

    /**
     * Objects 12: an instance of a subtype fails loudly, read or bound.
     */
    #[Test]
    public function a_subtype_instance_fails_loudly(): void
    {
        $connection = $this->connect();

        self::assertSame([['ANIMAL' => ['NAME' => 'cat']]], self::rows($connection, 'select animal from fx_animals where id = 1'));
        try {
            self::rows($connection, 'select animal from fx_animals where id = 2');
            self::fail('The subtype instance was read.');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('FX_ANIMAL holds an instance of an object subtype', $e->getMessage());
        }
        try {
            $connection->execute(self::ECHO, ['i' => Struct::in('fx_dog', ['name' => 'rex', 'breed' => 'lab']), 'o' => Number::out()], self::options());
            self::fail('The subtype instance was bound.');
        } catch (UnsupportedException $e) {
            self::assertStringStartsWith("Bind 'i': ", $e->getMessage());
            self::assertStringContainsString('FX_DOG holds an instance of an object subtype', $e->getMessage());
        }
    }

    /**
     * Objects 13: a Collection of Collections, and an Associative array of
     * Objects, round-trip.
     */
    #[Test]
    public function a_collection_of_collections_round_trips(): void
    {
        $matrix = [[1, 2], [], null, [null]];
        $points = [5 => self::point(1, 2), -1 => null];

        $out = $this->connect()->execute(
            'begin :o := :i; :points_o := :points; end;',
            [
                'i' => Collection::in('fx_number_matrix', $matrix), 'o' => Collection::out('fx_number_matrix'),
                'points' => Indexed::in('fx_kinds.t_point_map', $points), 'points_o' => Indexed::out('fx_kinds.t_point_map'),
            ],
            self::options(),
        )->outValues();

        self::assertSame([$matrix], $out['o']);
        self::assertSame([[-1 => null, 5 => ['X' => 1, 'Y' => 2, 'LABEL' => null]]], $out['points_o']);
    }

    /**
     * Scope 8: an Associative array indexed by strings fails loudly, IN
     * and OUT, rather than losing its keys.
     */
    #[Test]
    public function an_index_by_varchar2_bind_fails_loudly(): void
    {
        $connection = $this->connect();

        foreach ([
            // @phpstan-ignore argument.type (deliberately string keys)
            Indexed::in('fx_kinds.t_string_map', ['a' => 'x']),
            Indexed::in('fx_kinds.t_string_map', [1 => 'x']),
            Indexed::out('fx_kinds.t_string_map'),
            Collection::out('fx_kinds.t_string_map'),
        ] as $bind) {
            try {
                $connection->execute('begin :o := fx_kinds.string_map; end;', ['o' => $bind], self::options());
                self::fail('An INDEX BY VARCHAR2 bind ran.');
            } catch (UsageException|UnsupportedException $e) {
                self::assertStringContainsString('INDEX BY VARCHAR2', $e->getMessage());
            }
        }
    }

    /**
     * @return list<array<int|string, mixed>>
     */
    private static function drain(DriverCursor $cursor): array
    {
        $rows = [];
        while (($batch = $cursor->fetchBatch()) !== []) {
            array_push($rows, ...$batch);
        }
        $cursor->close();

        return $rows;
    }

    /**
     * An Object as the test reads it.
     *
     * @return array<string, mixed>
     */
    private static function struct(mixed $value): array
    {
        self::assertIsArray($value);
        foreach (array_keys($value) as $key) {
            self::assertIsString($key);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A Collection as the test reads it.
     *
     * @return list<mixed>
     */
    private static function elements(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        return $value;
    }

    /**
     * @return array{x: int, y: int, label: null}
     */
    private static function point(int $x, int $y): array
    {
        return ['x' => $x, 'y' => $y, 'label' => null];
    }

    /**
     * The attributes of fx_all_scalars, keyed as Native shows them.
     *
     * @return array<string, null>
     */
    private static function allScalars(): array
    {
        return array_fill_keys(
            ['C_CHAR', 'C_NCHAR', 'C_VARCHAR', 'C_NVARCHAR', 'C_RAW', 'C_NUMBER', 'C_FLOAT', 'C_DOUBLE', 'C_BOOLEAN', 'C_DATE', 'C_TS', 'C_TSTZ', 'C_TSLTZ', 'C_IDS', 'C_IYM'],
            null,
        );
    }

    /**
     * Each value of an array as `Temporals::shown()` writes it.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function shown(array $values): array
    {
        return array_map(Temporals::shown(...), $values);
    }

    /** Stands for the current schema in data providers. */
    private static function schemaPlaceholder(): string
    {
        return '{schema}';
    }
}
