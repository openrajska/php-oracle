<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\CallsTest;

use DateTimeImmutable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\Cursor;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\NumberMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\TemporalMode;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Subprogram\PendingFunction;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\Tests\Support\TestDatabase;

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});

afterEach(function (): void {
    $this->connection->close();
});

test('a procedure reads its out and in out parameters by name', function (): void {
    $execution = $this->connection->procedure('fx_call.echo', [
        'p_num' => 21,
        'o_doubled' => new Out\Number(),
        'o_label' => new Out\Varchar(),
        'io_text' => new InOut\Varchar('hi'),
    ]);

    expect($execution->out('o_doubled')->int())->toBe(42)
        ->and($execution->out('o_label')->string())->toBe('default')
        ->and($execution->out('IO_TEXT')->string())->toBe('hi!');
});

test('a repeated call binds its own values and a colliding name is still refused', function (): void {
    foreach ([21 => 42, 5 => 10] as $number => $doubled) {
        $execution = $this->connection->procedure('fx_call.echo', [
            'p_num' => $number,
            'o_doubled' => new Out\Number(),
            'o_label' => new Out\Varchar(),
            'io_text' => new InOut\Varchar('hi'),
        ]);
        expect($execution->out('o_doubled')->int())->toBe($doubled);
    }

    try {
        $this->connection->procedure("fx_call.echo\0p_num", [
            'o_doubled' => new Out\Number(),
            'o_label' => new Out\Varchar(),
            'io_text' => new InOut\Varchar('hi'),
        ]);
        $this->fail('A name with a NUL byte ran.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toStartWith("Call name 'fx_call.echo\0p_num' is not valid");
    }
});

test('a procedure gives a complete execution', function (): void {
    $execution = $this->connection->procedure('fx_call.grow', ['io_text' => new InOut\Varchar('a'), 'p_length' => 3]);

    expect($execution->affected())->toBe(0)
        ->and($execution->warning())->toBeNull()
        ->and(fn (): Cursor => $execution->rows())->toThrow(function (UsageException $usageException): void {
            expect($usageException->getMessage())->toStartWith('The statement is not a query')
                ->and($usageException->sql())->toBe('BEGIN fx_call.grow(io_text => :b1, p_length => :b2); END;')
                ->and($usageException->call())->toBe('fx_call.grow');
        })
        ->and($execution->out('io_text')->string())->toBe('aaa');
});

test('the json and vector terminals return the mapped value', function (): void {
    expect($this->connection->function('fx_json_vector.f_json')->json())->toBe(['a' => [1, '2.5']])
        ->and($this->connection->function('fx_json_vector.f_json', [], new Options(values: new ValueMapping(numbers: NumberMode::Float)))->json())->toBe(['a' => [1, 2.5]])
        ->and($this->connection->function('fx_json_vector.f_json', ['p_null' => true])->json())->toBeNull()
        ->and($this->connection->function('fx_json_vector.f_vector')->vector())->toBe([1.5, 2.0])
        ->and($this->connection->function('fx_json_vector.f_vector', ['p_null' => true])->vector())->toBeNull();

    $sparse = $this->connection->function('fx_json_vector.f_sparse')->vector();
    assert($sparse instanceof SparseVector);
    expect([$sparse->dimensions, $sparse->values])->toBe([10, [3 => -1]]);
});

/**
 * @return iterable<string, array{string, string, mixed}>
 */
dataset('scalarTerminals', function () {
    yield 'varchar' => ['varchar', 'fx_call.f_varchar', 'text'];
    yield 'nVarchar' => ['nVarchar', 'fx_call.f_nvarchar', 'Ação 日本'];
    yield 'char' => ['char', 'fx_call.f_char', 'AB   '];
    yield 'nChar' => ['nChar', 'fx_call.f_nchar', 'ç    '];
    yield 'binaryFloat' => ['binaryFloat', 'fx_call.f_binary_float', 0.5];
    yield 'binaryDouble' => ['binaryDouble', 'fx_call.f_binary_double', 0.1];
    yield 'plsInteger' => ['plsInteger', 'fx_call.f_pls_integer', -7];
    yield 'boolean' => ['boolean', 'fx_call.f_boolean', true];
    yield 'raw' => ['raw', 'fx_call.f_raw', "\x00\xff\x10"];
});

test('a scalar terminal returns the mapped value and null for null', function (string $terminal, string $function, mixed $expected): void {
    expect($this->connection->function($function)->{$terminal}())->toBe($expected)
        ->and($this->connection->function($function, ['p_null' => true])->{$terminal}())->toBeNull();
})->with('scalarTerminals');

test('the object and collection terminals return arrays', function (): void {
    expect($this->connection->function('fx_object.make_point', ['p_x' => new In\Number('1.5'), 'p_y' => -2, 'p_label' => 'p'])->struct('fx_point'))->toBe(['X' => '1.5', 'Y' => -2, 'LABEL' => 'p'])
        ->and($this->connection->function('fx_object.make_polygon', ['p_name' => 'tri', 'p_sides' => 1], new Options(keyCase: KeyCase::Lower))->struct('FX_POLYGON'))->toBe(['name' => 'tri', 'vertices' => [['x' => 1, 'y' => 0, 'label' => 'v1']]])
        ->and($this->connection->function('fx_object.split_csv', ['p_csv' => 'a,,b'])->collection('fx_string_varray'))->toBe(['a', null, 'b'])
        ->and($this->connection->function('fx_object.split_csv', ['p_csv' => null])->collection('fx_string_varray'))->toBe([])
        ->and($this->connection->function('fx_kinds.sparse_numbers')->collection('fx_number_list'))->toBe([1, 3, 5])
        ->and($this->connection->function('fx_kinds.echo_numbers', ['p_numbers' => new In\Collection('fx_number_list', null)])->collection('fx_number_list'))->toBeNull()
        ->and($this->connection->function('fx_kinds.echo_map', ['p_map' => new In\Indexed('fx_object.t_number_map', [3 => 6, -1 => 5])])->indexed('fx_object.t_number_map'))->toBe([-1 => 5, 3 => 6]);

    // A PL/SQL Associative array is never NULL: a NULL comes in empty.
    expect($this->connection->function('fx_kinds.echo_map', ['p_map' => new In\Indexed('fx_object.t_number_map', null)])->indexed('fx_object.t_number_map'))->toBe([]);
});

test('an object terminal of the wrong kind fails naming the fix', function (): void {
    expect(fn () => $this->connection->function('fx_object.split_csv', ['p_csv' => 'a'])->struct('fx_string_varray'))->toThrow(function (UsageException $usageException): void {
        expect($usageException->getMessage())->toStartWith('The return value of fx_object.split_csv: ')
            ->toEndWith('.FX_STRING_VARRAY is a Collection type (nested table or VARRAY), not an Object type; use Collection, not Struct.')
            ->and($usageException->call())->toBe('fx_object.split_csv');
    });
});

test('the temporal terminals return the mapped value', function (): void {
    $string = new Options(values: new ValueMapping(temporals: TemporalMode::String));
    $cases = [
        'date' => ['fx_call.f_date', '+2026-03-01 10:00:00.000000 America/Sao_Paulo', '2026-03-01 10:00:00'],
        'timestamp' => ['fx_call.f_timestamp', '+2026-03-01 10:00:00.123456 America/Sao_Paulo', '2026-03-01 10:00:00.123456000'],
        'timestampTz' => ['fx_call.f_timestamp_tz', '+2026-03-01 10:00:00.123456 +05:45', '2026-03-01 10:00:00.123456000 +05:45'],
        'timestampLtz' => ['fx_call.f_timestamp_ltz', '+2026-03-01 10:00:00.000000 America/Sao_Paulo', '2026-03-01 10:00:00.000000000 -03:00'],
        'intervalDs' => ['fx_call.f_interval_ds', '+0Y0M3DT4H5M6S.789000', null],
        'intervalYm' => ['fx_call.f_interval_ym', '+2Y5M0DT0H0M0S.000000', null],
    ];

    Temporals::inZone('America/Sao_Paulo', function () use ($cases, $string): void {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
        try {
            foreach ($cases as $terminal => [$function, $object, $text]) {
                expect(Temporals::shown($connection->function($function)->{$terminal}()))->toBe($object, $terminal)
                    ->and($connection->function($function, ['p_null' => true])->{$terminal}())->toBeNull($terminal);
                if ($text !== null) {
                    expect($connection->function($function, [], $string)->{$terminal}())->toBe($text, $terminal);
                }
            }
        } finally {
            $connection->close();
        }
    });
});

test('the number terminal follows the value mapping', function (): void {
    expect($this->connection->function('fx_call.f_number', ['p_value' => 42])->number())->toBe(42)
        ->and($this->connection->function('fx_call.f_number', ['p_value' => new In\Number('12.5')])->number())->toBe('12.5')
        ->and($this->connection->function(
            'fx_call.f_number',
            ['p_value' => new In\Number('12.5')],
            new Options(values: new ValueMapping(numbers: NumberMode::Float)),
        )->number())->toBe(12.5)
        ->and($this->connection->function('fx_call.f_number', ['p_value' => null])->number())->toBeNull();
});

test('the rowid terminal returns the rowid text', function (): void {
    $rowid = $this->connection->select('select rowidtochar(rowid) from fx_items where id = 1')->value();

    expect($this->connection->function('fx_call.f_rowid')->rowid())->toBe($rowid)
        ->and($this->connection->function('fx_call.f_rowid', ['p_null' => true])->rowid())->toBeNull();
});

test('a call reads varchar2 values of the largest size', function (): void {
    $ascii = $this->connection->procedure('fx_scalar.repeat_out', ['p_char' => 'x', 'p_length' => 32767, 'o_str' => new Out\Varchar()]);
    $multibyte = $this->connection->procedure('fx_scalar.repeat_out', ['p_char' => 'ç', 'p_length' => 16383, 'o_str' => new Out\Varchar()]);

    expect($ascii->out('o_str')->string())->toBe(str_repeat('x', 32767))
        ->and($multibyte->out('o_str')->string())->toBe(str_repeat('ç', 16383));

    foreach ([null, 'a'] as $start) {
        $grown = $this->connection->procedure('fx_call.grow', ['io_text' => new InOut\Varchar($start), 'p_length' => 32767]);
        expect($grown->out('io_text')->string())->toBe(str_repeat('a', 32767));
    }
});

test('a failing call carries its name and generated block', function (): void {
    $calls = [
        'BEGIN fx_call.fail(p_value => :b1); END;' => fn (): Execution => $this->connection->procedure('fx_call.fail', ['p_value' => 'x']),
        'BEGIN :b0 := fx_call.f_fail(p_value => :b1); END;' => fn (): float | int | string | null => $this->connection->function('fx_call.f_fail', ['p_value' => 'x'])->number(),
    ];
    foreach ($calls as $sql => $call) {
        try {
            $call();
            $this->fail("$sql did not fail.");
        } catch (QueryException $e) {
            expect($e->getCode())->toBe(20046)
                ->and($e->getMessage())->toStartWith('ORA-20046: fx_call.')
                ->and($e->sql())->toBe($sql)
                ->and($e->call())->toBe(str_contains($sql, ':b0') ? 'fx_call.f_fail' : 'fx_call.fail');
        }
    }

    expect(fn () => $this->connection->procedure('fx_call.echo', ['p_num' => 1, 'P_Label' => 'x', 'o_doubled' => new Out\Number(), 'o_label' => new Out\Varchar(), 'io_text' => new InOut\Varchar(null)])->out('nothing'))->toThrow(function (UsageException $usageException): void {
        expect($usageException->getMessage())->toBe("No OUT bind is named 'nothing'; they are 'o_doubled', 'o_label', 'io_text'.")
            ->and($usageException->sql())->toBe('BEGIN fx_call.echo(p_num => :b1, P_Label => :b2, o_doubled => :b3, o_label => :b4, io_text => :b5); END;')
            ->and($usageException->call())->toBe('fx_call.echo');
    });
});

test('a call after close fails', function (): void {
    $function = $this->connection->function('fx_call.f_number', ['p_value' => 1]);
    $this->connection->close();

    $calls = [
        'fx_call.echo' => fn (): Execution => $this->connection->procedure('fx_call.echo', ['p_num' => 1, 'o_doubled' => new Out\Number(), 'o_label' => new Out\Varchar(), 'io_text' => new InOut\Varchar(null)]),
        'fx_call.f_number' => $function->number(...),
    ];
    foreach ($calls as $name => $call) {
        expect($call)->toThrow(function (UsageException $usageException) use ($name): void {
            expect($usageException->getMessage())->toBe('The Connection is closed.')
                ->and($usageException->call())->toBe($name);
        });
    }
});

test('a bind error names the parameter', function (): void {
    $calls = [
        fn (): Execution => $this->connection->procedure('fx_call.fail', ['P_Value' => new DateTimeImmutable()]),
        fn (): float | int | string | null => $this->connection->function('fx_call.f_fail', ['P_Value' => new DateTimeImmutable()])->number(),
    ];
    foreach ($calls as $call) {
        try {
            $call();
            $this->fail('A DateTimeImmutable was bound.');
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe("Bind 'P_Value': a DateTimeInterface does not bind by itself; use In\Date or In\Timestamp.")
                ->and((string) $e->call())->toStartWith('fx_call.');
        }
    }
});

test('a bound value never appears in a call error', function (): void {
    $secret = 'zq46secret';
    $calls = [
        'procedure' => fn (): Execution => $this->connection->procedure('fx_call.echo', ['p_num' => $secret, 'o_doubled' => new Out\Number(), 'o_label' => new Out\Varchar(), 'io_text' => new InOut\Varchar($secret)]),
        'function' => fn (): float | int | string | null => $this->connection->function('fx_call.f_number', ['p_value' => $secret])->number(),
        'procedure, usage' => fn (): Execution => $this->connection->procedure('fx_call.fail', ['p_value' => [$secret]]),
        'function, usage' => fn (): float | int | string | null => $this->connection->function('fx_call.f_fail', ['p_value' => [$secret]])->number(),
    ];
    foreach ($calls as $kind => $call) {
        try {
            $call();
            $this->fail("$kind: '$secret' was taken for a number.");
        } catch (OracleException $e) {
            expect($e->getMessage())->not->toContain($secret)
                ->and($e->getTraceAsString())->not->toContain($secret)
                ->and((string) $e)->not->toContain($secret);
        }
    }
});

test('a quoted mixed case name and a schema qualified name work', function (): void {
    $schema = $this->connection->select("select sys_context('userenv', 'current_schema') from dual")->value();
    assert(\is_string($schema));
    expect($this->connection->function('"FX_Mixed_Case"', ['"pValue"' => 21])->number())->toBe(42)
        ->and($this->connection->function("$schema.\"FX_Mixed_Case\"", ['"pValue"' => 4])->number())->toBe(8)
        ->and($this->connection->function("$schema.fx_call.f_number", ['P_VALUE' => 3])->number())->toBe(3)
        ->and($this->connection->function('FX_SCALAR.Greet', ['p_name' => 'you'])->varchar())->toBe('Hello, you!');
});

/**
 * @return iterable<string, array{string, array<mixed>, string}>
 */
dataset('invalidNames', function () {
    $rule = 'an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes.';

    yield 'four parts' => ['a.b.c.d', [], "Call name 'a.b.c.d' has 4 parts; a name has at most 3: schema.package.name."];
    yield 'unterminated quote' => ['fx_call."f_number', [], "Call name 'fx_call.\"f_number' is not valid: it has an unterminated quote."];
    yield 'semicolon' => ['fx_call.echo;fx_call.fail', [], "Call name 'fx_call.echo;fx_call.fail' is not valid: each dot-separated part must be $rule"];
    yield 'whitespace' => ['fx_call. echo', [], "Call name 'fx_call. echo' is not valid"];
    yield 'comment' => ['fx_call.echo--', [], "Call name 'fx_call.echo--' is not valid"];
    yield 'empty' => ['', [], "Call name '' is not valid"];
    yield 'empty part' => ['fx_call..echo', [], "Call name 'fx_call..echo' is not valid"];
    yield 'trailing dot' => ['fx_call.', [], "Call name 'fx_call.' is not valid"];
    yield 'empty quotes' => ['""', [], "Call name '\"\"' is not valid"];
    yield 'leading digit' => ['1fx', [], "Call name '1fx' is not valid"];
    yield 'too long' => [str_repeat('a', 129), [], "is not valid: each dot-separated part must be $rule"];
    yield 'database link' => ['fx_call.echo@remote', [], "Call name 'fx_call.echo@remote' is not valid: each dot-separated part must be $rule Database links (@) are not supported."];
    yield 'parameter with a space' => ['fx_call.echo', ['p num' => 1], "Parameter name 'p num' is not valid: a parameter name must be $rule"];
    yield 'parameter with a colon' => ['fx_call.echo', [':p_num' => 1], "Parameter name ':p_num' is not valid"];
    yield 'parameter with a dot' => ['fx_call.echo', ['fx.p_num' => 1], "Parameter name 'fx.p_num' is not valid: a parameter name must be $rule"];
    yield 'parameter breaking out' => ['fx_call.echo', ['p_num => 1); fx_call.fail(p_value => 1' => 1], "Parameter name 'p_num => 1); fx_call.fail(p_value => 1' is not valid"];
    yield 'parameters by position' => ['fx_call.echo', [1, 2], 'Call parameters are passed by name, but key 0 is an integer (is the array a list?); key every value by its parameter name.'];
    yield 'one parameter twice' => ['fx_call.echo', ['p_num' => 1, 'P_NUM' => 2], "Parameters 'p_num' and 'P_NUM' name the same parameter; keep one of them."];
    yield 'one parameter quoted and not' => ['fx_call.echo', ['p_num' => 1, '"P_NUM"' => 2], "Parameters 'p_num' and '\"P_NUM\"' name the same parameter"];
});

test('an invalid name fails before any round trip', function (string $name, array $binds, string $message): void {
    $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
    assert(\is_string($sid));
    $counter = RoundTripCounter::open();

    try {
        $calls = [
            // @phpstan-ignore argument.type (a list is one of the mistakes under test)
            fn (): Execution => $this->connection->procedure($name, $binds),
            // @phpstan-ignore argument.type (a list is one of the mistakes under test)
            fn (): PendingFunction => $this->connection->function($name, $binds),
        ];
        foreach ($calls as $call) {
            $trips = $counter->during((int) $sid, function () use ($call, $name, $message): void {
                try {
                    $call();
                    $this->fail("'$name' was accepted.");
                } catch (UsageException $usageException) {
                    expect($usageException->getMessage())->toContain($message)
                        ->and($usageException->call())->toBe($name)
                        ->and($usageException->sql())->toBeNull();
                }
            });
            expect($trips)->toBe(0);
        }
    } finally {
        $counter->close();
    }
})->with('invalidNames');
