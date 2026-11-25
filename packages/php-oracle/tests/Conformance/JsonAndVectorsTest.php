<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Direction;
use Rajska\Oracle\Bind\Json;
use Rajska\Oracle\Bind\Vector;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\SparseVector;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\ValueMapping;
use Rajska\Oracle\VectorFormat;

/**
 * JSON and VECTOR values.
 */
final class JsonAndVectorsTest extends ConformanceTestCase
{
    #[Test]
    public function json_decodes_like_json_decode_with_exact_numbers(): void
    {
        $sql = <<<'SQL'
            select json('{"list": [1, 2.5, {"deep": null}], "yes": true, "no": false,
                          "text": "héllo", "big": 12345678901234567890, "half": 0.5,
                          "empty": [], "nothing": {}, "1": "numeric key"}') doc,
                   json('5') scalar, json('"x"') text, cast(null as json) nothing
              from dual
            SQL;
        $connection = $this->connect();

        $exact = self::rows($connection, $sql)[0];
        self::assertSame([
            1 => 'numeric key',
            'big' => '12345678901234567890',
            'empty' => [],
            'half' => '0.5',
            'list' => [1, '2.5', ['deep' => null]],
            'no' => false,
            'nothing' => [],
            'text' => 'héllo',
            'yes' => true,
        ], self::sorted($exact['DOC']));
        self::assertSame(['SCALAR' => 5, 'TEXT' => 'x', 'NOTHING' => null], array_slice($exact, 1));

        $float = self::rows($connection, $sql, options: self::options(values: new ValueMapping(numbers: NumberMode::Float)))[0];
        $doc = self::sorted($float['DOC']);
        self::assertIsArray($doc);
        self::assertSame([1, 2.5, ['deep' => null]], $doc['list']);
        self::assertSame(0.5, $doc['half']);
        self::assertSame('12345678901234567890', $doc['big']);
    }

    #[Test]
    public function json_extended_scalars_follow_their_column_rules(): void
    {
        $sql = <<<'SQL'
            select json_object(
                     'ts' value timestamp '2026-03-01 10:00:00.123456789',
                     'tz' value timestamp '2026-03-01 10:00:00.5 +05:45',
                     'ds' value interval '1 2:3:4.5' day to second,
                     'ym' value interval '1-2' year to month,
                     'bytes' value hextoraw('00FF'),
                     'bd' value to_binary_double(0.1),
                     'bf' value to_binary_float(0.1),
                     'v' value vector('[1.5, -2]', 2, float32),
                     'sparse' value vector('[10, [1, 5], [1.5, 0.1]]', 10, float32, sparse)
                     returning json) doc
              from dual
            SQL;

        Temporals::inZone('America/Sao_Paulo', function () use ($sql): void {
            $connection = $this->connect();

            $objects = self::sorted(self::rows($connection, $sql)[0]['DOC']);
            self::assertIsArray($objects);
            self::assertSame([
                'bd' => 0.1,
                'bf' => 0.1,
                'bytes' => "\x00\xff",
                'ds' => '+0Y0M1DT2H3M4S.500000',
                'ts' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
                'tz' => '+2026-03-01 10:00:00.500000 +05:45',
                'v' => [1.5, -2.0],
                'ym' => '+1Y2M0DT0H0M0S.000000',
            ], array_map(Temporals::shown(...), array_diff_key($objects, ['sparse' => null])));
            self::assertInstanceOf(SparseVector::class, $objects['sparse']);
            self::assertSame([10, [1 => 1.5, 5 => 0.1]], [$objects['sparse']->dimensions, $objects['sparse']->values]);

            $strings = self::sorted(self::rows($connection, $sql, options: self::options(values: new ValueMapping(temporals: TemporalMode::String)))[0]['DOC']);
            self::assertIsArray($strings);
            self::assertSame('2026-03-01 10:00:00.123456789', $strings['ts']);
            self::assertSame('2026-03-01 10:00:00.500000000 +05:45', $strings['tz']);
        });
    }

    #[Test]
    public function vector_columns_give_lists_or_sparse_vectors(): void
    {
        $rows = self::rows($this->connect(), 'select v32, v64, v8, vbin, s32, s64, s8, flex from fx_vectors order by id');

        self::assertEquals([
            [
                'V32' => [1.5, -2.25, 0.1],
                'V64' => [1.5, -2.25, 0.1],
                'V8' => [1, -2, 127],
                'VBIN' => [1, 255],
                'S32' => new SparseVector(10, [1 => 1.5, 5 => 0.1]),
                'S64' => new SparseVector(10, [1 => 1.5, 5 => 0.1]),
                'S8' => new SparseVector(10, [0 => -128, 9 => 127]),
                'FLEX' => [1.0, 2.0, 3.0, 4.0],
            ],
            array_fill_keys(['V32', 'V64', 'V8', 'VBIN', 'S32', 'S64', 'S8', 'FLEX'], null),
        ], $rows);
        // assertEquals compares objects loosely: check the exact types too.
        self::assertSame([1.5, -2.25, 0.1], $rows[0]['V32']);
        self::assertInstanceOf(SparseVector::class, $rows[0]['S8']);
        self::assertSame([0 => -128, 9 => 127], $rows[0]['S8']->values);
        self::assertInstanceOf(SparseVector::class, $rows[0]['S32']);
        self::assertSame([1 => 1.5, 5 => 0.1], $rows[0]['S32']->values);
    }

    #[Test]
    public function vector_columns_describe_dimensions_format_and_sparseness(): void
    {
        $cursor = $this->connect()->execute('select v32, v64, v8, vbin, s32, s8, flex from fx_vectors', [], self::options())->cursor();
        self::assertNotNull($cursor);

        self::assertSame([
            ['V32', 'vector', 3, 'float32', false],
            ['V64', 'vector', 3, 'float64', false],
            ['V8', 'vector', 3, 'int8', false],
            ['VBIN', 'vector', 16, 'binary', false],
            ['S32', 'vector', 10, 'float32', true],
            ['S8', 'vector', 10, 'int8', true],
            ['FLEX', 'vector', null, null, false],
        ], array_map(
            static fn(array $column): array => [$column['name'], $column['type'], $column['vectorDimensions'], $column['vectorFormat'], $column['sparse']],
            $cursor->columns(),
        ));
    }

    /** An OSON id is the `_id` of a JSON collection table. */
    #[Test]
    public function an_oson_id_fails_loudly(): void
    {
        $connection = $this->connect();

        try {
            self::rows($connection, 'select data from fx_json_docs');
            self::fail('An OSON id was read.');
        } catch (UnsupportedException $unsupportedException) {
            self::assertStringContainsString('OSON id', $unsupportedException->getMessage());
        }
        self::assertSame([['NAME' => 'first']], self::rows($connection, "select json_value(data, '$.name') name from fx_json_docs"));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function jsonInputs(): iterable
    {
        yield 'associative array' => [['name' => 'Ada', 'tags' => ['x', 'y'], 'deep' => ['k' => null]], ['deep' => ['k' => null], 'name' => 'Ada', 'tags' => ['x', 'y']]];
        yield 'list' => [[1, 'two', true, null, [3]], [1, 'two', true, null, [3]]];
        yield 'empty list' => [[], []];
        yield 'stdClass' => [(object) ['a' => 1, 'b' => (object) []], ['a' => 1, 'b' => []]];
        yield 'JsonSerializable' => [new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['kind' => 'serialized', 'nested' => new ArrayObject()];
            }
        }, ['kind' => 'serialized', 'nested' => []]];
        yield 'object with public properties' => [new class {
            public int $visible = 1;

            protected int $hidden = 2;
        }, ['visible' => 1]];
        yield 'backed enum' => [[Direction::In], ['in']];
        yield 'float' => [0.1, '0.1'];
        yield 'floats inside' => [['x' => 0.1, 'y' => -2.5e-7], ['x' => '0.1', 'y' => '-0.00000025']];
        yield 'int' => [PHP_INT_MAX, PHP_INT_MAX];
        yield 'string' => ['héllo', 'héllo'];
        yield 'DateTimeInterface' => [
            new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('Asia/Tokyo')),
            '+2026-03-01 10:00:00.500000 America/Sao_Paulo',
        ];
        yield 'null' => [null, null];
    }

    /**
     * A `DateTimeInterface` stores its Wall clock as a TIMESTAMP.
     */
    #[Test]
    #[DataProvider('jsonInputs')]
    public function json_in_round_trips_each_input_kind(mixed $input, mixed $expected): void
    {
        Temporals::inZone('America/Sao_Paulo', function () use ($input, $expected): void {
            $connection = $this->connect();
            $options = self::options(autocommit: false);
            try {
                $connection->execute('insert into fx_vector_rows (id, doc) values (1, :doc)', ['doc' => Json::in($input)], $options);
                $read = self::rows($connection, 'select doc from fx_vector_rows', options: $options)[0]['DOC'];
            } finally {
                $connection->rollback();
            }

            self::assertSame($expected, Temporals::shown(self::sorted($read)));
        });
    }

    #[Test]
    public function json_in_refuses_what_json_cannot_hold(): void
    {
        foreach ([NAN, [INF], fopen('php://memory', 'r')] as $value) {
            try {
                Json::in($value);
                self::fail('Json::in() took ' . get_debug_type($value));
            } catch (UsageException $e) {
                self::assertStringStartsWith('Json::in()', $e->getMessage());
            }
        }

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("Bind 'doc': JSON text must be valid UTF-8");
        $this->connect()->execute('select :doc from dual', ['doc' => Json::in(['bytes' => "\xff"])], self::options());
    }

    #[Test]
    public function a_float64_vector_bind_fills_a_float32_column(): void
    {
        $connection = $this->connect();
        $options = self::options(autocommit: false);
        try {
            $connection->execute(
                'insert into fx_vector_rows (id, v32, s32) values (1, :dense, :sparse)',
                ['dense' => Vector::in([1.5, -2, 0.1]), 'sparse' => Vector::in(new SparseVector(10, [5 => 0.1, 1 => 1.5]))],
                $options,
            );
            [$row] = self::rows($connection, 'select v32, s32 from fx_vector_rows', options: $options);
        } finally {
            $connection->rollback();
        }

        self::assertSame([1.5, -2.0, 0.1], $row['V32']);
        self::assertInstanceOf(SparseVector::class, $row['S32']);
        self::assertSame([10, [1 => 1.5, 5 => 0.1]], [$row['S32']->dimensions, $row['S32']->values]);
    }

    /**
     * @return iterable<string, array{Vector, mixed}>
     */
    public static function vectorBinds(): iterable
    {
        yield 'Float64' => [Vector::in([1.5, -2, 0.1]), [1.5, -2.0, 0.1]];
        yield 'Float32' => [Vector::in([1.5, -2, 0.1], VectorFormat::Float32), [1.5, -2.0, 0.1]];
        yield 'Int8' => [Vector::in([1, -128, 127], VectorFormat::Int8), [1, -128, 127]];
        yield 'Binary' => [Vector::in([0b10110100, 0, 255], VectorFormat::Binary), [0b10110100, 0, 255]];
        yield 'sparse Float64' => [Vector::in(new SparseVector(10, [9 => -0.5, 0 => 2])), new SparseVector(10, [0 => 2.0, 9 => -0.5])];
        yield 'sparse Float32' => [Vector::in(new SparseVector(3, [1 => 0.1]), VectorFormat::Float32), new SparseVector(3, [1 => 0.1])];
        yield 'sparse Int8' => [Vector::in(new SparseVector(4, [3 => -7]), VectorFormat::Int8), new SparseVector(4, [3 => -7])];
        yield 'NULL' => [Vector::in(null), null];
    }

    #[Test]
    #[DataProvider('vectorBinds')]
    public function vector_in_round_trips_each_format(Vector $bind, mixed $expected): void
    {
        $read = self::rows($this->connect(), 'select :v v from dual', ['v' => $bind])[0]['V'];

        if ($expected instanceof SparseVector) {
            self::assertInstanceOf(SparseVector::class, $read);
            self::assertSame([$expected->dimensions, $expected->values], [$read->dimensions, $read->values]);
        } else {
            self::assertSame($expected, $read);
        }
    }

    /**
     * @return iterable<string, array{Vector, string}>
     */
    public static function vectorMistakes(): iterable
    {
        yield 'empty list' => [Vector::in([]), 'a dense VECTOR is a non-empty list of numbers'];
        // @phpstan-ignore argument.type (the mistake is the point)
        yield 'not a list' => [Vector::in([1 => 1.0]), 'a dense VECTOR is a non-empty list of numbers'];
        // @phpstan-ignore argument.type (the mistake is the point)
        yield 'not a number' => [Vector::in(['1']), 'a FLOAT64 VECTOR holds finite ints and floats'];
        yield 'NaN' => [Vector::in([NAN]), 'a FLOAT64 VECTOR holds finite ints and floats'];
        yield 'beyond FLOAT32' => [Vector::in([1e39], VectorFormat::Float32), 'a FLOAT32 VECTOR holds ints and floats within FLOAT32'];
        yield 'beyond INT8' => [Vector::in([128], VectorFormat::Int8), 'an INT8 VECTOR holds ints from -128 to 127'];
        yield 'float in INT8' => [Vector::in([1.5], VectorFormat::Int8), 'an INT8 VECTOR holds ints from -128 to 127'];
        yield 'beyond a byte' => [Vector::in([256], VectorFormat::Binary), 'a BINARY VECTOR holds bytes'];
        yield 'sparse BINARY' => [Vector::in(new SparseVector(8, [0 => 1]), VectorFormat::Binary), 'a sparse VECTOR cannot be BINARY'];
    }

    #[Test]
    #[DataProvider('vectorMistakes')]
    public function vector_in_checks_its_values_before_any_round_trip(Vector $bind, string $message): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("Bind 'v': $message");

        $this->connect()->execute('select :v from dual', ['v' => $bind], self::options());
    }

    #[Test]
    public function a_sparse_vector_checks_its_dimensions_and_positions(): void
    {
        foreach ([[0, []], [3, [3 => 1.0]], [3, [-1 => 1.0]], [3, ['a' => 1.0]], [3, [0 => '1']]] as [$dimensions, $values]) {
            try {
                new SparseVector($dimensions, $values);
                self::fail('SparseVector took ' . json_encode([$dimensions, $values]));
            } catch (UsageException $e) {
                self::assertStringStartsWith('A SparseVector', $e->getMessage());
            }
        }
    }

    #[Test]
    public function json_and_vector_bind_in_out_and_in_out_through_plsql(): void
    {
        $execution = $this->connect()->execute(
            "declare
               procedure j(i in json, o out json, io in out json) is
               begin
                 o := i;
                 -- Dynamic: PL/SQL's own SQL parser lacks json_transform.
                 execute immediate q'~select json_transform(:io, set '$.added' = 1) from dual~' into io using io;
               end;
               procedure v(i in vector, o out vector, io in out vector) is
               begin
                 o := i;
                 if io is not null then
                   io := to_vector('[10, [2], [4.5]]', 10, float64, sparse);
                 end if;
               end;
             begin
               j(:ji, :jo, :jio);
               j(null, :jnull, :jionull);
               v(:vi, :vo, :vio);
               v(null, :vnull, :vionull);
             end;",
            [
                'ji' => Json::in(['a' => [1, 2]]), 'jo' => Json::out(), 'jio' => Json::inOut(['b' => true]),
                'jnull' => Json::out(), 'jionull' => Json::inOut(null),
                'vi' => Vector::in([1, 2], VectorFormat::Int8), 'vo' => Vector::out(), 'vio' => Vector::inOut([0.5]),
                'vnull' => Vector::out(), 'vionull' => Vector::inOut(null),
            ],
            self::options(),
        );

        $values = $execution->outValues();
        $sparse = $values['vio'][0];
        unset($values['vio']);
        self::assertSame([
            'jo' => [['a' => [1, 2]]],
            'jio' => [['added' => 1, 'b' => true]],
            'jnull' => [null],
            'jionull' => [null],
            'vo' => [[1, 2]],
            'vnull' => [null],
            'vionull' => [null],
        ], array_map(static fn(array $list): array => array_map(self::sorted(...), $list), $values));
        self::assertInstanceOf(SparseVector::class, $sparse);
        self::assertSame([10, [2 => 4.5]], [$sparse->dimensions, $sparse->values]);
    }

    #[Test]
    public function json_and_vector_come_back_from_returning_into(): void
    {
        $connection = $this->connect();
        $options = self::options(autocommit: false);
        try {
            $execution = $connection->execute(
                'insert into fx_vector_rows (id, v32, s32, doc) values (1, :v, :s, :d) returning v32, s32, doc into :rv, :rs, :rd',
                [
                    'v' => Vector::in([1, 2, 3]), 's' => Vector::in(new SparseVector(10, [4 => 0.5])), 'd' => Json::in(['a' => 1]),
                    'rv' => Vector::out(), 'rs' => Vector::out(), 'rd' => Json::out(),
                ],
                $options,
            );
        } finally {
            $connection->rollback();
        }

        $values = $execution->outValues();
        self::assertSame([[1.0, 2.0, 3.0]], $values['rv']);
        self::assertSame([['a' => 1]], $values['rd']);
        self::assertInstanceOf(SparseVector::class, $values['rs'][0]);
        self::assertSame([4 => 0.5], $values['rs'][0]->values);
    }

    #[Test]
    public function json_out_values_follow_the_value_mapping(): void
    {
        // Dynamic: PL/SQL's own SQL turns the TIMESTAMP into text.
        $sql = <<<'SQL'
            declare
              j json;
            begin
              execute immediate q'~select json_object('half' value 0.5, 'ts' value timestamp '2026-03-01 10:00:00.123456789' returning json) from dual~' into j;
              :doc := j;
            end;
            SQL;
        $connection = $this->connect();

        Temporals::inZone('UTC', function () use ($connection, $sql): void {
            $default = $connection->execute($sql, ['doc' => Json::out()], self::options())->outValues()['doc'][0];
            $default = self::sorted($default);
            self::assertIsArray($default);
            self::assertSame(['half' => '0.5', 'ts' => '+2026-03-01 10:00:00.123456 UTC'], array_map(Temporals::shown(...), $default));
        });

        $mapped = $connection->execute($sql, ['doc' => Json::out()], self::options(values: new ValueMapping(numbers: NumberMode::Float, temporals: TemporalMode::String)))->outValues()['doc'][0];
        self::assertSame(['half' => 0.5, 'ts' => '2026-03-01 10:00:00.123456789'], self::sorted($mapped));
    }

    /**
     * A decoded JSON object with its keys sorted, at every level: the server
     * does not keep their order.
     */
    private static function sorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
