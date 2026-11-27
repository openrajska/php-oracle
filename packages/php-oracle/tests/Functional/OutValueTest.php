<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\OutValueTest;

use Closure;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\DataType\VectorFormat;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\OutValue;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

const TEMPORALS = "begin
    :d := date '2026-03-01'; :tz := timestamp '2026-03-01 10:00:00 +05:45';
    :ds := interval '1 02:00:00' day to second; :nothing := null;
end;";

beforeEach(function (): void {
    schema()->run('create type out_point as object (x number, y number, label varchar2(30))');
    schema()->run('create type out_tags as varray(10) of varchar2(100)');
    schema()->run(<<<'SQL'
        create package out_maps as
            type t_number_map is table of number index by pls_integer;
        end;
        SQL);
    schema()->run('create table out_shapes (id number(10) primary key, center out_point, tags out_tags)');
    schema()->run("insert into out_shapes values (1, out_point(1.5, -2, 'center'), out_tags('red', unistr('caf\\00e9')))");
    schema()->run('insert into out_shapes values (2, null, null)');
    schema()->run('create table out_items (id number(10) primary key, text_value clob)');
    schema()->run(<<<'SQL'
        insert into out_items
        select level, case when level = 1 then to_clob(unistr('Hello, LOB! A\00e7\00e3o \65e5\672c')) end from dual connect by level <= 4
        SQL);
    schema()->run('create global temporary table out_inserts (id number(10), code char(5)) on commit delete rows');

    $this->connection = $this->connect();
});

test('a block reads its out and in out binds', function (): void {
    $execution = $this->connection->run(
        "begin :total := :a + :b; :greeting := :greeting || ', world'; :ok := :total > 2; end;",
        ['a' => 1, 'b' => new In\Number('2.5'), 'total' => new Out\Number(), 'greeting' => new InOut\Varchar('hello'), 'ok' => new Out\Boolean()],
    );

    expect($execution->out('total')->string())->toBe('3.5')
        ->and($execution->out('greeting')->string())->toBe('hello, world')
        ->and($execution->out('ok')->bool())->toBeTrue()
        ->and($execution->out('total')->value())->toBe('3.5')
        ->and($execution->out('total')->list())->toBe(['3.5']);
});

/**
 * A getter, the OUT value it reads, and the message naming the right one.
 *
 * @return iterable<string, array{Closure(OutValue): mixed, Out\Number|Out\Varchar, string, string}>
 */
dataset('getterMistakes', function () {
    yield 'int() of a fraction' => [
        static fn (OutValue $value): mixed => $value->int(),
        new Out\Number(),
        '12.5',
        "OUT bind 'v' holds string, not an int. A NUMBER that is not an integer, or beyond 64 bits, arrives as its exact string: read it with string(), or map it to a float with ValueMapping(numbers: NumberMode::Float).",
    ];
    yield 'float() of an integer' => [
        static fn (OutValue $value): mixed => $value->float(),
        new Out\Number(),
        '5',
        "OUT bind 'v' holds int, not a float. Read it with int().",
    ];
    yield 'string() of an integer' => [
        static fn (OutValue $value): mixed => $value->string(),
        new Out\Number(),
        '5',
        "OUT bind 'v' holds int, not a string. Read it with int().",
    ];
    yield 'int() of text' => [
        static fn (OutValue $value): mixed => $value->int(),
        new Out\Varchar(),
        "'5'",
        "OUT bind 'v' holds string, not an int.",
    ];
    yield 'bool() of text' => [
        static fn (OutValue $value): mixed => $value->bool(),
        new Out\Varchar(),
        "'5'",
        "OUT bind 'v' holds string, not a bool.",
    ];
});

test('a getter never converts and names the one to use', function (Closure $getter, Out\Number | Out\Varchar $bind, string $value, string $message): void {
    $execution = $this->connection->run("begin :v := $value; end;", ['v' => $bind]);

    $read = static fn (): mixed => $getter($execution->out('v'));

    expect($read)->toThrow(UsageException::class, $message);
})->with('getterMistakes');

test('a null out value reads as null through every getter', function (string $getter): void {
    $execution = $this->connection->run('begin :v := null; end;', ['v' => new Out\Number()]);

    $value = $execution->out('v')->$getter();

    expect($value)->toBeNull();
})->with(['int', 'string', 'bool', 'value', 'dateTime', 'interval']);

test('the value mapping decides what a getter finds', function (): void {
    $float = new Options(values: new ValueMapping(numbers: NumberMode::Float));

    $execution = $this->connection->run('begin :fraction := 12.5; end;', ['fraction' => new Out\Number()], $float);

    expect($execution->out('fraction')->float())->toBe(12.5);
});

test('temporal out values read as date times and intervals', function (): void {
    $execution = inTimeZone('UTC', fn (): Execution => $this->connection->run(TEMPORALS, temporalBinds()));

    expect(temporalText($execution->out('d')->dateTime()))->toBe('+2026-03-01 00:00:00.000000 UTC')
        ->and(temporalText($execution->out('tz')->dateTime()))->toBe('+2026-03-01 10:00:00.000000 +05:45')
        ->and(temporalText($execution->out('ds')->interval()))->toBe('+0Y0M1DT2H0M0S.000000');
});

/**
 * @return iterable<string, array{Closure(Execution): mixed, string}>
 */
dataset('temporalGetterMistakes', function () {
    yield 'interval() of a date' => [
        static fn (Execution $execution): mixed => $execution->out('d')->interval(),
        "OUT bind 'd' holds DateTimeImmutable, not a DateInterval. Read it with dateTime().",
    ];
    yield 'dateTime() of an interval' => [
        static fn (Execution $execution): mixed => $execution->out('ds')->dateTime(),
        "OUT bind 'ds' holds DateInterval, not a DateTimeImmutable. Read it with interval().",
    ];
    yield 'string() of a date' => [
        static fn (Execution $execution): mixed => $execution->out('d')->string(),
        "OUT bind 'd' holds DateTimeImmutable, not a string. Read it with dateTime().",
    ];
});

test('a temporal getter of the wrong kind names the one to use', function (Closure $read, string $message): void {
    $execution = $this->connection->run(TEMPORALS, temporalBinds());

    $call = static fn (): mixed => $read($execution);

    expect($call)->toThrow(UsageException::class, $message);
})->with('temporalGetterMistakes');

test('under temporal mode string a temporal out value reads as text', function (): void {
    $string = new Options(values: new ValueMapping(temporals: TemporalMode::String));

    $execution = $this->connection->run(TEMPORALS, temporalBinds(), $string);

    expect($execution->out('d')->string())->toBe('2026-03-01 00:00:00')
        ->and(static fn (): mixed => $execution->out('d')->dateTime())
        ->toThrow(UsageException::class, "OUT bind 'd' holds string, not a DateTimeImmutable. Under ValueMapping(temporals: TemporalMode::String), a temporal value arrives as ISO text: read it with string().");
});

test('array reads objects collections and associative arrays', function (): void {
    $execution = $this->connection->run(
        'begin :point := out_point(1, 2, :label); :tags := :tags; :map := :map; :none := null; end;',
        [
            'label' => 'p',
            'point' => new Out\Struct('out_point'),
            'tags' => new InOut\Collection('out_tags', ['a', null]),
            'map' => new InOut\Indexed('out_maps.t_number_map', [-1 => 1]),
            'none' => new Out\Struct('out_point'),
        ],
    );

    expect($execution->out('point')->array())->toBe(['X' => 1, 'Y' => 2, 'LABEL' => 'p'])
        ->and($execution->out('tags')->array())->toBe(['a', null])
        ->and($execution->out('map')->array())->toBe([-1 => 1])
        ->and($execution->out('none')->array())->toBeNull();
});

/**
 * @return iterable<string, array{Closure(Execution): mixed, string}>
 */
dataset('arrayGetterMistakes', function () {
    yield 'array() of a number' => [
        static fn (Execution $execution): mixed => $execution->out('n')->array(),
        "OUT bind 'n' holds int, not an array. Read it with int().",
    ];
    yield 'string() of an object' => [
        static fn (Execution $execution): mixed => $execution->out('point')->string(),
        "OUT bind 'point' holds array, not a string. Read it with array().",
    ];
});

test('an array getter of the wrong kind names the one to use', function (Closure $read, string $message): void {
    $execution = $this->connection->run('begin :point := out_point(1, 2, null); :n := 1; end;', ['point' => new Out\Struct('out_point'), 'n' => new Out\Number()]);

    $call = static fn (): mixed => $read($execution);

    expect($call)->toThrow(UsageException::class, $message);
})->with('arrayGetterMistakes');

test('json and vector out values have their mapped types', function (): void {
    $execution = $this->connection->run(
        "begin :doc := :doc; :scalar := json('5'); :dense := :dense; :sparse := :sparse; end;",
        [
            'doc' => new InOut\Json(['a' => [1, 0.5]]),
            'scalar' => new Out\Json(),
            'dense' => new InOut\Vector([1, 2], VectorFormat::Int8),
            'sparse' => new InOut\Vector(new SparseVector(4, [2 => 0.5])),
        ],
    );

    $sparse = $execution->out('sparse')->value();

    assert($sparse instanceof SparseVector);
    expect($execution->out('doc')->array())->toBe(['a' => [1, '0.5']])
        ->and($execution->out('scalar')->int())->toBe(5)
        ->and($execution->out('dense')->array())->toBe([1, 2])
        ->and([$sparse->dimensions, $sparse->values])->toBe([4, [2 => 0.5]]);
});

test('returning into from one row gives its values', function (): void {
    $execution = $this->connection->run(
        'insert into out_inserts (id, code) values (:id, :code) returning id, code into :new_id, :new_code',
        ['id' => 42, 'code' => new In\Char('AB'), 'new_id' => new Out\Number(), 'new_code' => new Out\Char()],
    );

    expect($execution->affected())->toBe(1)
        ->and($execution->out('new_id')->int())->toBe(42)
        ->and($execution->out('new_code')->string())->toBe('AB   ')
        ->and($execution->out('new_id')->list())->toBe([42]);
});

test('returning into gives one value per row through list', function (string $where, array $ids): void {
    $execution = $this->connection->run("update out_items set id = id where $where returning id into :id", ['id' => new Out\Number()]);

    $list = $execution->out('id')->list();

    expect($list)->toEqualCanonicalizing($ids);
})->with([
    'two rows' => ['id in (3, 4)', [3, 4]],
    'no row' => ['id < 0', []],
]);

test('a scalar getter of returning into needs exactly one row', function (string $where, string $getter, string $message): void {
    $execution = $this->connection->run("update out_items set id = id where $where returning id into :id", ['id' => new Out\Number()]);

    $read = static fn (): mixed => $execution->out('id')->$getter();

    expect($read)->toThrow(UsageException::class, $message);
})->with([
    'int() of two rows' => ['id in (3, 4)', 'int', "OUT bind 'id' returned 2 rows, and int() needs exactly one; read every returned row with list()."],
    'int() of no row' => ['id < 0', 'int', "OUT bind 'id' returned 0 rows, and int() needs exactly one; read every returned row with list()."],
    'value() of no row' => ['id < 0', 'value', "OUT bind 'id' returned 0 rows, and value() needs exactly one"],
]);

test('returning into a lob gives the lob', function (): void {
    $execution = $this->connection->run('update out_items set id = id where id = 1 returning text_value into :t', ['t' => new Out\Clob()]);

    $lob = $execution->out('t')->lob();

    expect($lob?->contents())->toBe("Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}");
});

test('returning into an object or a collection gives one value per row', function (): void {
    $execution = $this->connection->run(
        'update out_shapes set id = id where id in (1, 2) returning id, center, tags into :id, :center, :tags',
        ['id' => new Out\Number(), 'center' => new Out\Struct('out_point'), 'tags' => new Out\Collection('out_tags')],
    );

    $rows = array_map(null, $execution->out('id')->list(), $execution->out('center')->list(), $execution->out('tags')->list());

    usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    expect($rows)->toBe([
        [1, ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'], ['red', "caf\u{e9}"]],
        [2, null, null],
    ]);
});

/**
 * @return iterable<string, array{string, array<string, mixed>, string, string}>
 */
dataset('outNameMistakes', function () {
    $sql = "begin :total := :a; :Label := 'x'; end;";
    $binds = ['a' => 1, 'total' => new Out\Number(), 'Label' => new InOut\Varchar(null)];
    yield 'an unknown name' => [$sql, $binds, 'missing', "No OUT bind is named 'missing'; they are 'total', 'Label'."];
    yield 'an IN only name' => [$sql, $binds, 'a', "Bind 'a' is IN only, so it has no OUT value; bind it with an Out\\ or InOut\\ class to read it back."];
    yield 'a statement with no OUT bind' => ['begin null; end;', [], 'x', "No OUT bind is named 'x'; the statement has none."];
    yield 'an IN bind of a query' => ['select :v from dual', ['v' => new In\Varchar('y')], 'v', "Bind 'v' is IN only"];
});

test('out of a name that has no out value fails with the sql', function (string $sql, array $binds, string $name, string $message): void {
    /** @var array<string, mixed> $binds */
    $execution = $this->connection->run($sql, $binds);

    $thrown = null;
    try {
        $execution->out($name);
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    expect($thrown?->getMessage())->toContain($message)
        ->and($thrown?->sql())->toBe($sql);
})->with('outNameMistakes');

test('out matches the name ignoring case', function (string $name): void {
    $execution = $this->connection->run('begin :TOTAL := 7; end;', ['Total' => new Out\Number()]);

    $value = $execution->out($name)->int();

    expect($value)->toBe(7);
})->with(['Total', 'total', 'TOTAL']);

test('a number string must be a decimal literal', function (string $class, string $text): void {
    $build = static fn (): object => new $class($text);

    expect($build)->toThrow(UsageException::class, "A NUMBER bind takes a decimal literal with '.' as the separator, such as '12.30'");
})->with([In\Number::class, InOut\Number::class])->with(['1,5', '1e5', '', ' 1', 'abc', '1.2.3', '0x1A']);

test('a number string keeps its decimal literal as written', function (string $text): void {
    $bind = new In\Number($text);

    expect($bind->value)->toBe($text);
})->with(['-.5', '12.', '12.30']);

/**
 * @return array<string, Out\Date|Out\IntervalDs|Out\IntervalYm|Out\TimestampTz>
 */
function temporalBinds(): array
{
    return ['d' => new Out\Date(), 'tz' => new Out\TimestampTz(), 'ds' => new Out\IntervalDs(), 'nothing' => new Out\IntervalYm()];
}
