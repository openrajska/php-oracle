<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\JsonAndVectorTest;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Override;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\DataType\VectorFormat;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

beforeAll(function (): void {
    schema()->run(<<<'SQL'
        create table jv_vectors (
            id number(10) primary key,
            v32 vector(3, float32),
            v64 vector(3, float64),
            v8 vector(3, int8),
            vbin vector(16, binary),
            s32 vector(10, float32, sparse),
            s64 vector(10, float64, sparse),
            s8 vector(10, int8, sparse),
            flex vector(*, *)
        )
        SQL);
    schema()->run(<<<'SQL'
        insert into jv_vectors values (
            1,
            vector('[1.5, -2.25, 0.1]', 3, float32),
            vector('[1.5, -2.25, 0.1]', 3, float64),
            vector('[1, -2, 127]', 3, int8),
            vector('[1, 255]', 16, binary),
            vector('[10, [1, 5], [1.5, 0.1]]', 10, float32, sparse),
            vector('[10, [1, 5], [1.5, 0.1]]', 10, float64, sparse),
            vector('[10, [0, 9], [-128, 127]]', 10, int8, sparse),
            vector('[1, 2, 3, 4]', 4, float64)
        )
        SQL);
    schema()->run('insert into jv_vectors (id) values (2)');
    schema()->run(<<<'SQL'
        create global temporary table jv_rows (
            id number(10),
            v32 vector(3, float32),
            s32 vector(10, float32, sparse),
            doc json
        ) on commit delete rows
        SQL);
    // Its documents carry an OSON id (_id).
    schema()->run('create json collection table jv_docs');
    schema()->run(<<<'SQL'
        insert into jv_docs values ('{"name": "first"}')
        SQL);
});

const JSON_DOCUMENT = <<<'SQL'
    select json('{"list": [1, 2.5, {"deep": null}], "yes": true, "no": false,
                  "text": "héllo", "big": 12345678901234567890, "half": 0.5,
                  "empty": [], "nothing": {}, "1": "numeric key"}') doc,
           json('5') scalar, json('"x"') text, cast(null as json) nothing
      from dual
    SQL;

test('json decodes like json decode with exact numbers', function (): void {
    $connection = $this->connect();

    $row = $this->rows($connection, JSON_DOCUMENT)[0];

    expect(sorted($row['DOC']))->toBe([
        1 => 'numeric key',
        'big' => '12345678901234567890',
        'empty' => [],
        'half' => '0.5',
        'list' => [1, '2.5', ['deep' => null]],
        'no' => false,
        'nothing' => [],
        'text' => 'héllo',
        'yes' => true,
    ])
        ->and(\array_slice($row, 1))->toBe(['SCALAR' => 5, 'TEXT' => 'x', 'NOTHING' => null]);
});

test('json numbers follow the float mapping and big integers stay exact', function (): void {
    $connection = $this->connect();

    $doc = sorted($this->rows($connection, JSON_DOCUMENT, options: $this->options(values: new ValueMapping(numbers: NumberMode::Float)))[0]['DOC']);

    assert(\is_array($doc));
    expect($doc['list'])->toBe([1, 2.5, ['deep' => null]])
        ->and($doc['half'])->toBe(0.5)
        ->and($doc['big'])->toBe('12345678901234567890');
});

const JSON_EXTENDED_SCALARS = <<<'SQL'
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

test('json extended scalars map like their column types', function (): void {
    $connection = $this->connect();

    $doc = sorted(inTimeZone('America/Sao_Paulo', fn (): mixed => $this->rows($connection, JSON_EXTENDED_SCALARS)[0]['DOC']));

    assert(\is_array($doc));
    assert($doc['sparse'] instanceof SparseVector);
    expect(array_map(temporalText(...), array_diff_key($doc, ['sparse' => null])))->toBe([
        'bd' => 0.1,
        'bf' => 0.1,
        'bytes' => "\x00\xff",
        'ds' => '+0Y0M1DT2H3M4S.500000',
        'ts' => '+2026-03-01 10:00:00.123456 America/Sao_Paulo',
        'tz' => '+2026-03-01 10:00:00.500000 +05:45',
        'v' => [1.5, -2.0],
        'ym' => '+1Y2M0DT0H0M0S.000000',
    ])
        ->and([$doc['sparse']->dimensions, $doc['sparse']->values])->toBe([10, [1 => 1.5, 5 => 0.1]]);
});

test('json temporals follow the string mapping', function (): void {
    $connection = $this->connect();

    $doc = sorted($this->rows($connection, JSON_EXTENDED_SCALARS, options: $this->options(values: new ValueMapping(temporals: TemporalMode::String)))[0]['DOC']);

    assert(\is_array($doc));
    expect($doc['ts'])->toBe('2026-03-01 10:00:00.123456789')
        ->and($doc['tz'])->toBe('2026-03-01 10:00:00.500000000 +05:45');
});

test('vector columns give lists or sparse vectors', function (): void {
    $connection = $this->connect();

    $rows = $this->rows($connection, 'select v32, v64, v8, vbin, s32, s64, s8, flex from jv_vectors order by id');

    assert($rows[0]['S8'] instanceof SparseVector);
    assert($rows[0]['S32'] instanceof SparseVector);
    expect($rows)->toEqual([
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
    ])
        // toEqual compares loosely: the exact types too.
        ->and($rows[0]['V32'])->toBe([1.5, -2.25, 0.1])
        ->and($rows[0]['S8']->values)->toBe([0 => -128, 9 => 127])
        ->and($rows[0]['S32']->values)->toBe([1 => 1.5, 5 => 0.1]);
});

test('vector columns describe dimensions format and sparseness', function (): void {
    $cursor = $this->connect()->execute('select v32, v64, v8, vbin, s32, s8, flex from jv_vectors', [], $this->options())->cursor();
    assert($cursor !== null);

    $columns = $cursor->columns();

    expect(array_map(static fn (array $column): array => [$column['name'], $column['type'], $column['vectorDimensions'], $column['vectorFormat'], $column['sparse']], $columns))->toBe([
        ['V32', 'vector', 3, 'float32', false],
        ['V64', 'vector', 3, 'float64', false],
        ['V8', 'vector', 3, 'int8', false],
        ['VBIN', 'vector', 16, 'binary', false],
        ['S32', 'vector', 10, 'float32', true],
        ['S8', 'vector', 10, 'int8', true],
        ['FLEX', 'vector', null, null, false],
    ]);
});

test('an oson id fails loudly and the rest of the document reads', function (): void {
    $connection = $this->connect();
    $thrown = null;

    try {
        $this->rows($connection, 'select data from jv_docs');
    } catch (UnsupportedException $unsupportedException) {
        $thrown = $unsupportedException;
    }

    assert($thrown instanceof UnsupportedException);
    expect($thrown->getMessage())->toContain('OSON id')
        ->and($this->rows($connection, "select json_value(data, '$.name') name from jv_docs"))->toBe([['NAME' => 'first']]);
});

/**
 * @return iterable<string, array{mixed, mixed}>
 */
dataset('jsonInputs', function () {
    yield 'associative array' => [['name' => 'Ada', 'tags' => ['x', 'y'], 'deep' => ['k' => null]], ['deep' => ['k' => null], 'name' => 'Ada', 'tags' => ['x', 'y']]];
    yield 'list' => [[1, 'two', true, null, [3]], [1, 'two', true, null, [3]]];
    yield 'empty list' => [[], []];
    yield 'stdClass' => [(object) ['a' => 1, 'b' => (object) []], ['a' => 1, 'b' => []]];
    yield 'JsonSerializable' => [new class implements JsonSerializable {
        #[Override]
        public function jsonSerialize(): mixed
        {
            return ['kind' => 'serialized', 'nested' => new ArrayObject()];
        }
    }, ['kind' => 'serialized', 'nested' => []]];
    yield 'object with public properties' => [new class {
        public int $visible = 1;

        protected int $hidden = 2;
    }, ['visible' => 1]];
    yield 'backed enum' => [[RowShape::Assoc], ['assoc']];
    yield 'float' => [0.1, '0.1'];
    yield 'floats inside' => [['x' => 0.1, 'y' => -2.5e-7], ['x' => '0.1', 'y' => '-0.00000025']];
    yield 'int' => [\PHP_INT_MAX, \PHP_INT_MAX];
    yield 'string' => ['héllo', 'héllo'];
    yield 'DateTimeInterface' => [
        new DateTimeImmutable('2026-03-01 10:00:00.5', new DateTimeZone('Asia/Tokyo')),
        '+2026-03-01 10:00:00.500000 America/Sao_Paulo',
    ];
    yield 'null' => [null, null];
});

test('json in stores each input kind as json', function (mixed $input, mixed $expected): void {
    $connection = $this->connect();
    $options = $this->options(autocommit: false);

    $read = inTimeZone('America/Sao_Paulo', function () use ($connection, $input, $options): mixed {
        $connection->execute('insert into jv_rows (id, doc) values (1, :doc)', ['doc' => new In\Json($input)], $options);
        $read = $this->rows($connection, 'select doc from jv_rows', options: $options)[0]['DOC'];
        $connection->rollback();

        return $read;
    });

    expect(temporalText(sorted($read)))->toBe($expected);
})->with('jsonInputs');

test('json in refuses what json cannot hold', function (mixed $value): void {
    $thrown = null;

    try {
        new In\Json($value);
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    assert($thrown instanceof UsageException);
    expect($thrown->getMessage())->toStartWith('A JSON bind');
})->with([
    'NaN' => [\NAN],
    'INF inside' => [[\INF]],
    'a resource' => [fopen('php://memory', 'r')],
]);

test('json text that is not utf8 fails when bound', function (): void {
    $connection = $this->connect();

    $execute = fn () => $connection->execute('select :doc from dual', ['doc' => new In\Json(['bytes' => "\xff"])], $this->options());

    expect($execute)->toThrow(UsageException::class, "Bind 'doc': JSON text must be valid UTF-8");
});

test('a float64 vector bind fills a float32 column', function (): void {
    $connection = $this->connect();
    $options = $this->options(autocommit: false);

    $connection->execute(
        'insert into jv_rows (id, v32, s32) values (1, :dense, :sparse)',
        ['dense' => new In\Vector([1.5, -2, 0.1]), 'sparse' => new In\Vector(new SparseVector(10, [5 => 0.1, 1 => 1.5]))],
        $options,
    );
    [$row] = $this->rows($connection, 'select v32, s32 from jv_rows', options: $options);
    $connection->rollback();

    assert($row['S32'] instanceof SparseVector);
    expect($row['V32'])->toBe([1.5, -2.0, 0.1])
        ->and([$row['S32']->dimensions, $row['S32']->values])->toBe([10, [1 => 1.5, 5 => 0.1]]);
});

/**
 * @return iterable<string, array{In\Vector, mixed}>
 */
dataset('vectorBinds', function () {
    yield 'Float64' => [new In\Vector([1.5, -2, 0.1]), [1.5, -2.0, 0.1]];
    yield 'Float32' => [new In\Vector([1.5, -2, 0.1], VectorFormat::Float32), [1.5, -2.0, 0.1]];
    yield 'Int8' => [new In\Vector([1, -128, 127], VectorFormat::Int8), [1, -128, 127]];
    yield 'Binary' => [new In\Vector([0b10110100, 0, 255], VectorFormat::Binary), [0b10110100, 0, 255]];
    yield 'sparse Float64' => [new In\Vector(new SparseVector(10, [9 => -0.5, 0 => 2])), [10, [0 => 2.0, 9 => -0.5]]];
    yield 'sparse Float32' => [new In\Vector(new SparseVector(3, [1 => 0.1]), VectorFormat::Float32), [3, [1 => 0.1]]];
    yield 'sparse Int8' => [new In\Vector(new SparseVector(4, [3 => -7]), VectorFormat::Int8), [4, [3 => -7]]];
    yield 'NULL' => [new In\Vector(null), null];
});

test('vector in reads back as bound in each format', function (In\Vector $bind, mixed $expected): void {
    $connection = $this->connect();

    $read = $this->rows($connection, 'select :v v from dual', ['v' => $bind])[0]['V'];

    expect($read instanceof SparseVector ? [$read->dimensions, $read->values] : $read)->toBe($expected);
})->with('vectorBinds');

/**
 * @return iterable<string, array{In\Vector, string}>
 */
dataset('vectorMistakes', function () {
    yield 'empty list' => [new In\Vector([]), 'a dense VECTOR is a non-empty list of numbers'];

    // @phpstan-ignore argument.type (the mistake is the point)
    yield 'not a list' => [new In\Vector([1 => 1.0]), 'a dense VECTOR is a non-empty list of numbers'];

    // @phpstan-ignore argument.type (the mistake is the point)
    yield 'not a number' => [new In\Vector(['1']), 'a FLOAT64 VECTOR holds finite ints and floats'];
    yield 'NaN' => [new In\Vector([\NAN]), 'a FLOAT64 VECTOR holds finite ints and floats'];
    yield 'beyond FLOAT32' => [new In\Vector([1e39], VectorFormat::Float32), 'a FLOAT32 VECTOR holds ints and floats within FLOAT32'];
    yield 'beyond INT8' => [new In\Vector([128], VectorFormat::Int8), 'an INT8 VECTOR holds ints from -128 to 127'];
    yield 'float in INT8' => [new In\Vector([1.5], VectorFormat::Int8), 'an INT8 VECTOR holds ints from -128 to 127'];
    yield 'beyond a byte' => [new In\Vector([256], VectorFormat::Binary), 'a BINARY VECTOR holds bytes'];
    yield 'sparse BINARY' => [new In\Vector(new SparseVector(8, [0 => 1]), VectorFormat::Binary), 'a sparse VECTOR cannot be BINARY'];
});

test('vector in checks its values before any round trip', function (In\Vector $bind, string $message): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $thrown = null;

    $trips = $this->roundTrips($sid, function () use ($connection, $bind, &$thrown): void {
        try {
            $connection->execute('select :v from dual', ['v' => $bind], $this->options());
        } catch (UsageException $usageException) {
            $thrown = $usageException;
        }
    });

    assert($thrown instanceof UsageException);
    expect($thrown->getMessage())->toContain("Bind 'v': $message")
        ->and($trips)->toBe(0);
})->with('vectorMistakes');

test('a sparse vector checks its dimensions and positions', function (int $dimensions, array $values): void {
    $thrown = null;

    try {
        new SparseVector($dimensions, $values);
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    assert($thrown instanceof UsageException);
    expect($thrown->getMessage())->toStartWith('A SparseVector');
})->with([
    'no dimensions' => [0, []],
    'a position past the dimensions' => [3, [3 => 1.0]],
    'a negative position' => [3, [-1 => 1.0]],
    'a string position' => [3, ['a' => 1.0]],
    'a value that is not a number' => [3, [0 => '1']],
]);

test('json and vector bind in out and in out through plsql', function (): void {
    $connection = $this->connect();

    $values = $connection->execute(
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
            'ji' => new In\Json(['a' => [1, 2]]), 'jo' => new Out\Json(), 'jio' => new InOut\Json(['b' => true]),
            'jnull' => new Out\Json(), 'jionull' => new InOut\Json(null),
            'vi' => new In\Vector([1, 2], VectorFormat::Int8), 'vo' => new Out\Vector(), 'vio' => new InOut\Vector([0.5]),
            'vnull' => new Out\Vector(), 'vionull' => new InOut\Vector(null),
        ],
        $this->options(),
    )->outValues();

    $sparse = $values['vio'][0];
    assert($sparse instanceof SparseVector);
    expect(array_map(static fn (array $list): array => array_map(sorted(...), $list), array_diff_key($values, ['vio' => true])))->toBe([
        'jo' => [['a' => [1, 2]]],
        'jio' => [['added' => 1, 'b' => true]],
        'jnull' => [null],
        'jionull' => [null],
        'vo' => [[1, 2]],
        'vnull' => [null],
        'vionull' => [null],
    ])
        ->and([$sparse->dimensions, $sparse->values])->toBe([10, [2 => 4.5]]);
});

test('json and vector come back from returning into', function (): void {
    $connection = $this->connect();
    $options = $this->options(autocommit: false);

    $values = $connection->execute(
        'insert into jv_rows (id, v32, s32, doc) values (1, :v, :s, :d) returning v32, s32, doc into :rv, :rs, :rd',
        [
            'v' => new In\Vector([1, 2, 3]), 's' => new In\Vector(new SparseVector(10, [4 => 0.5])), 'd' => new In\Json(['a' => 1]),
            'rv' => new Out\Vector(), 'rs' => new Out\Vector(), 'rd' => new Out\Json(),
        ],
        $options,
    )->outValues();
    $connection->rollback();

    assert($values['rs'][0] instanceof SparseVector);
    expect($values['rv'])->toBe([[1.0, 2.0, 3.0]])
        ->and($values['rd'])->toBe([['a' => 1]])
        ->and($values['rs'][0]->values)->toBe([4 => 0.5]);
});

test('json out values follow the value mapping', function (ValueMapping $mapping, array $expected): void {
    $connection = $this->connect();

    $doc = inTimeZone('UTC', fn (): mixed => $connection->execute(
        // Dynamic: PL/SQL's own SQL turns the TIMESTAMP into text.
        <<<'SQL'
            declare
              j json;
            begin
              execute immediate q'~select json_object('half' value 0.5, 'ts' value timestamp '2026-03-01 10:00:00.123456789' returning json) from dual~' into j;
              :doc := j;
            end;
            SQL,
        ['doc' => new Out\Json()],
        $this->options(values: $mapping),
    )->outValues()['doc'][0]);

    $sorted = sorted($doc);
    assert(\is_array($sorted));
    expect(array_map(temporalText(...), $sorted))->toBe($expected);
})->with([
    'the default mapping' => [new ValueMapping(), ['half' => '0.5', 'ts' => '+2026-03-01 10:00:00.123456 UTC']],
    'float numbers and string temporals' => [new ValueMapping(numbers: NumberMode::Float, temporals: TemporalMode::String), ['half' => 0.5, 'ts' => '2026-03-01 10:00:00.123456789']],
]);

/**
 * A decoded JSON object with its keys sorted, at every level: the server
 * does not keep their order.
 */
function sorted(mixed $value): mixed
{
    if (!\is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }

    return array_map(sorted(...), $value);
}
