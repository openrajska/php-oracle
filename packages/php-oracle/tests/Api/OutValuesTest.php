<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\OutValuesTest;

use Closure;
use DateTimeImmutable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\DataType\VectorFormat;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\OutValue;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\Tests\Support\TestDatabase;
use RuntimeException;

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});

afterEach(function (): void {
    $this->connection->close();
});

test('a block reads its out and in out binds', function (): void {
    $execution = $this->connection->run(
        "begin :total := :a + :b; :greeting := :greeting || ', world'; :ok := :total > 2; end;",
        ['a' => 1, 'b' => new In\Number('2.5'), 'total' => new Out\Number(), 'greeting' => new InOut\Varchar('hello'), 'ok' => new Out\Boolean()],
    );

    expect($execution->out('total')->string())->toBe('3.5')
        ->and($execution->out('greeting')->string())->toBe('hello, world')
        ->and($execution->out('ok')->bool())->toBeTrue()
        ->and($execution->out('total')->list())->toBe(['3.5'])
        ->and($execution->out('total')->value())->toBe('3.5');
});

test('getters narrow and never convert', function (): void {
    $execution = $this->connection->run(
        "begin :fraction := 12.5; :integral := 5; :nothing := null; :text := '5'; end;",
        ['fraction' => new Out\Number(), 'integral' => new Out\Number(), 'nothing' => new Out\Number(), 'text' => new Out\Varchar()],
    );

    expect($execution->out('integral')->int())->toBe(5)
        ->and($execution->out('nothing')->int())->toBeNull()
        ->and($execution->out('nothing')->string())->toBeNull()
        ->and($execution->out('nothing')->bool())->toBeNull()
        ->and($execution->out('nothing')->value())->toBeNull();

    assertFails(fn (): int | null => $execution->out('fraction')->int(), "OUT bind 'fraction' holds string, not an int. A NUMBER that is not an integer, or beyond 64 bits, arrives as its exact string: read it with string(), or map it to a float with ValueMapping(numbers: NumberMode::Float).");
    assertFails(fn (): float | null => $execution->out('integral')->float(), "OUT bind 'integral' holds int, not a float. Read it with int().");
    assertFails(fn (): string | null => $execution->out('integral')->string(), "OUT bind 'integral' holds int, not a string. Read it with int().");
    assertFails(fn (): int | null => $execution->out('text')->int(), "OUT bind 'text' holds string, not an int.");
    assertFails(fn (): bool | null => $execution->out('text')->bool(), "OUT bind 'text' holds string, not a bool.");
});

test('the value mapping decides what a getter finds', function (): void {
    $execution = $this->connection->run(
        'begin :fraction := 12.5; end;',
        ['fraction' => new Out\Number()],
        new Options(values: new ValueMapping(numbers: NumberMode::Float)),
    );

    expect($execution->out('fraction')->float())->toBe(12.5);
});

test('date time and interval read temporal out values', function (): void {
    $sql = "begin
                  :d := date '2026-03-01'; :tz := timestamp '2026-03-01 10:00:00 +05:45';
                  :ds := interval '1 02:00:00' day to second; :nothing := null;
                end;";
    $binds = ['d' => new Out\Date(), 'tz' => new Out\TimestampTz(), 'ds' => new Out\IntervalDs(), 'nothing' => new Out\IntervalYm()];
    $execution = Temporals::inZone('UTC', fn (): Execution => $this->connection->run($sql, $binds));

    expect(Temporals::shown($execution->out('d')->dateTime()))->toBe('+2026-03-01 00:00:00.000000 UTC')
        ->and(Temporals::shown($execution->out('tz')->dateTime()))->toBe('+2026-03-01 10:00:00.000000 +05:45')
        ->and(Temporals::shown($execution->out('ds')->interval()))->toBe('+0Y0M1DT2H0M0S.000000')
        ->and($execution->out('nothing')->interval())->toBeNull()
        ->and($execution->out('nothing')->dateTime())->toBeNull();

    assertFails(fn () => $execution->out('d')->interval(), "OUT bind 'd' holds DateTimeImmutable, not a DateInterval. Read it with dateTime().");
    assertFails(fn () => $execution->out('ds')->dateTime(), "OUT bind 'ds' holds DateInterval, not a DateTimeImmutable. Read it with interval().");
    assertFails(fn () => $execution->out('d')->string(), "OUT bind 'd' holds DateTimeImmutable, not a string. Read it with dateTime().");

    $string = $this->connection->run($sql, $binds, new Options(values: new ValueMapping(temporals: TemporalMode::String)));
    expect($string->out('d')->string())->toBe('2026-03-01 00:00:00');
    assertFails(fn (): DateTimeImmutable | null => $string->out('d')->dateTime(), "OUT bind 'd' holds string, not a DateTimeImmutable. Under ValueMapping(temporals: TemporalMode::String), a temporal value arrives as ISO text: read it with string().");
});

test('array reads objects and collections and fails on a scalar', function (): void {
    $execution = $this->connection->run(
        'begin :point := fx_point(1, 2, :label); :tags := :tags; :map := :map; :none := null; :n := 1; end;',
        [
            'label' => 'p',
            'point' => new Out\Struct('fx_point'),
            'tags' => new InOut\Collection('fx_string_varray', ['a', null]),
            'map' => new InOut\Indexed('fx_object.t_number_map', [-1 => 1]),
            'none' => new Out\Struct('fx_point'),
            'n' => new Out\Number(),
        ],
    );

    expect($execution->out('point')->array())->toBe(['X' => 1, 'Y' => 2, 'LABEL' => 'p'])
        ->and($execution->out('tags')->array())->toBe(['a', null])
        ->and($execution->out('map')->array())->toBe([-1 => 1])
        ->and($execution->out('none')->array())->toBeNull();
    assertFails(fn (): array | null => $execution->out('n')->array(), "OUT bind 'n' holds int, not an array. Read it with int().");
    assertFails(fn (): string | null => $execution->out('point')->string(), "OUT bind 'point' holds array, not a string. Read it with array().");
});

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

    expect($execution->out('doc')->array())->toBe(['a' => [1, '0.5']])
        ->and($execution->out('scalar')->int())->toBe(5)
        ->and($execution->out('dense')->array())->toBe([1, 2]);

    $sparse = $execution->out('sparse')->value();
    assert($sparse instanceof SparseVector);
    expect([$sparse->dimensions, $sparse->values])->toBe([4, [2 => 0.5]]);
});

test('returning into gives one value per row', function (): void {
    $one = $this->connection->run(
        'insert into fx_bind_rows (id, code) values (:id, :code) returning id, code into :new_id, :new_code',
        ['id' => 42, 'code' => new In\Char('AB'), 'new_id' => new Out\Number(), 'new_code' => new Out\Char()],
    );
    expect($one->affected())->toBe(1)
        ->and($one->out('new_id')->int())->toBe(42)
        ->and($one->out('new_code')->string())->toBe('AB   ')
        ->and($one->out('new_id')->list())->toBe([42]);

    $two = $this->connection->run('update fx_items set price = price where id in (3, 4) returning id into :id', ['id' => new Out\Number()]);
    $none = $this->connection->run('update fx_items set price = price where id < 0 returning id into :id', ['id' => new Out\Number()]);

    expect($two->out('id')->list())->toEqualCanonicalizing([3, 4])
        ->and($none->out('id')->list())->toBeEmpty();
    assertFails(fn (): int | null => $two->out('id')->int(), "OUT bind 'id' returned 2 rows, and int() needs exactly one; read every returned row with list().");
    assertFails(fn (): int | null => $none->out('id')->int(), "OUT bind 'id' returned 0 rows, and int() needs exactly one; read every returned row with list().");
    assertFails(fn (): mixed => $none->out('id')->value(), "OUT bind 'id' returned 0 rows, and value() needs exactly one");
});

test('returning into a lob an object or a collection gives one value per row', function (): void {
    $lob = $this->connection->run(
        'update fx_lobs set label = label where id = 1 returning text_value into :t',
        ['t' => new Out\Clob()],
    );
    expect($lob->out('t')->lob()?->contents())->toBe("Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}");

    $shapes = $this->connection->run(
        'update fx_shapes set id = id where id in (1, 2) returning id, center, tags into :id, :center, :tags',
        ['id' => new Out\Number(), 'center' => new Out\Struct('fx_point'), 'tags' => new Out\Collection('fx_string_varray')],
    );
    $rows = array_map(null, $shapes->out('id')->list(), $shapes->out('center')->list(), $shapes->out('tags')->list());
    usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    expect($rows)->toBe([
        [1, ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'], ['red', "caf\u{e9}"]],
        [2, null, null],
    ]);
});

test('out with an unknown or in only name fails', function (): void {
    $sql = "begin :total := :a; :Label := 'x'; end;";
    $execution = $this->connection->run($sql, ['a' => 1, 'total' => new Out\Number(), 'Label' => new InOut\Varchar(null)]);

    assertFails(fn (): OutValue => $execution->out('missing'), "No OUT bind is named 'missing'; they are 'total', 'Label'.", $sql);
    assertFails(fn (): OutValue => $execution->out('a'), "Bind 'a' is IN only, so it has no OUT value; bind it with an Out\\ or InOut\\ class to read it back.", $sql);
    assertFails(fn (): OutValue => $this->connection->run('begin null; end;')->out('x'), "No OUT bind is named 'x'; the statement has none.");
    assertFails(fn (): OutValue => $this->connection->run('select :v from dual', ['v' => new In\Varchar('y')])->out('v'), "Bind 'v' is IN only");
});

test('out matches the name ignoring case', function (): void {
    $execution = $this->connection->run('begin :TOTAL := 7; end;', ['Total' => new Out\Number()]);

    expect($execution->out('Total')->int())->toBe(7)
        ->and($execution->out('total')->int())->toBe(7);
});

test('a number string must be a decimal literal', function (): void {
    foreach (['1,5', '1e5', '', ' 1', 'abc', '1.2.3', '0x1A'] as $text) {
        assertFails(fn (): In\Number => new In\Number($text), "A NUMBER bind takes a decimal literal with '.' as the separator, such as '12.30'");
        assertFails(fn (): InOut\Number => new InOut\Number($text), "A NUMBER bind takes a decimal literal");
    }
    expect(new In\Number('-.5')->value)->toBe('-.5')
        ->and(new In\Number('12.')->value)->toBe('12.');
});

function assertFails(Closure $call, string $message, string | null $sql = null): void
{
    try {
        $call();
        throw new RuntimeException("Expected a UsageException: $message");
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toContain($message);
        if ($sql !== null) {
            expect($usageException->sql())->toBe($sql);
        }
    }
}
