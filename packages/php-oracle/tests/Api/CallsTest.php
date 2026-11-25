<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Collection;
use Rajska\Oracle\Bind\Indexed;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Execution;
use Rajska\Oracle\FunctionCall;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\SparseVector;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;

/**
 * Calls: procedures and functions called by name, with Core generating the
 * block in named notation (fixtures/sql/10_call.sql).
 */
final class CallsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * The omitted p_label takes its PL/SQL default.
     */
    #[Test]
    public function a_procedure_reads_its_out_and_in_out_parameters_by_name(): void
    {
        $execution = $this->connection->procedure('fx_call.echo', [
            'p_num' => 21,
            'o_doubled' => Number::out(),
            'o_label' => Varchar::out(),
            'io_text' => Varchar::inOut('hi'),
        ]);

        self::assertSame(42, $execution->out('o_doubled')->int());
        self::assertSame('default', $execution->out('o_label')->string());
        self::assertSame('hi!', $execution->out('IO_TEXT')->string());
    }

    /**
     * Calls of one shape (name and parameter names) share their validation:
     * each still binds its own values, and a name whose text only runs into
     * that shape's parameter names is still refused.
     */
    #[Test]
    public function a_repeated_call_binds_its_own_values_and_a_colliding_name_is_still_refused(): void
    {
        foreach ([21 => 42, 5 => 10] as $number => $doubled) {
            $execution = $this->connection->procedure('fx_call.echo', [
                'p_num' => $number,
                'o_doubled' => Number::out(),
                'o_label' => Varchar::out(),
                'io_text' => Varchar::inOut('hi'),
            ]);
            self::assertSame($doubled, $execution->out('o_doubled')->int());
        }

        try {
            $this->connection->procedure("fx_call.echo\0p_num", [
                'o_doubled' => Number::out(),
                'o_label' => Varchar::out(),
                'io_text' => Varchar::inOut('hi'),
            ]);
            self::fail('A name with a NUL byte ran.');
        } catch (UsageException $e) {
            self::assertStringStartsWith("Call name 'fx_call.echo\0p_num' is not valid", $e->getMessage());
        }
    }

    #[Test]
    public function a_procedure_gives_a_complete_execution(): void
    {
        $execution = $this->connection->procedure('fx_call.grow', ['io_text' => Varchar::inOut('a'), 'p_length' => 3]);

        self::assertSame(0, $execution->affected());
        self::assertNull($execution->warning());
        try {
            $execution->rows();
            self::fail('A procedure gave rows.');
        } catch (UsageException $e) {
            self::assertStringStartsWith('The statement is not a query', $e->getMessage());
            self::assertSame('BEGIN fx_call.grow(io_text => :b1, p_length => :b2); END;', $e->sql());
            self::assertSame('fx_call.grow', $e->call());
        }
        self::assertSame('aaa', $execution->out('io_text')->string());
    }

    #[Test]
    public function the_json_and_vector_terminals_return_the_mapped_value(): void
    {
        self::assertSame(['a' => [1, '2.5']], $this->connection->function('fx_json_vector.f_json')->json());
        self::assertSame(
            ['a' => [1, 2.5]],
            $this->connection->function('fx_json_vector.f_json', [], new ExecuteOptions(values: new ValueMapping(numbers: NumberMode::Float)))->json(),
        );
        self::assertNull($this->connection->function('fx_json_vector.f_json', ['p_null' => true])->json());

        self::assertSame([1.5, 2.0], $this->connection->function('fx_json_vector.f_vector')->vector());
        self::assertNull($this->connection->function('fx_json_vector.f_vector', ['p_null' => true])->vector());
        $sparse = $this->connection->function('fx_json_vector.f_sparse')->vector();
        self::assertInstanceOf(SparseVector::class, $sparse);
        self::assertSame([10, [3 => -1]], [$sparse->dimensions, $sparse->values]);
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function scalarTerminals(): iterable
    {
        yield 'varchar' => ['varchar', 'fx_call.f_varchar', 'text'];
        yield 'nvarchar' => ['nvarchar', 'fx_call.f_nvarchar', 'Ação 日本'];
        yield 'char' => ['char', 'fx_call.f_char', 'AB   '];
        yield 'nchar' => ['nchar', 'fx_call.f_nchar', 'ç    '];
        yield 'binaryFloat' => ['binaryFloat', 'fx_call.f_binary_float', 0.5];
        yield 'binaryDouble' => ['binaryDouble', 'fx_call.f_binary_double', 0.1];
        yield 'plsInteger' => ['plsInteger', 'fx_call.f_pls_integer', -7];
        yield 'boolean' => ['boolean', 'fx_call.f_boolean', true];
        yield 'raw' => ['raw', 'fx_call.f_raw', "\x00\xff\x10"];
    }

    #[Test]
    #[DataProvider('scalarTerminals')]
    public function a_scalar_terminal_returns_the_mapped_value_and_null_for_null(string $terminal, string $function, mixed $expected): void
    {
        self::assertSame($expected, $this->connection->function($function)->{$terminal}());
        self::assertNull($this->connection->function($function, ['p_null' => true])->{$terminal}());
    }

    #[Test]
    public function the_object_and_collection_terminals_return_arrays(): void
    {
        self::assertSame(
            ['X' => '1.5', 'Y' => -2, 'LABEL' => 'p'],
            $this->connection->function('fx_object.make_point', ['p_x' => Number::in('1.5'), 'p_y' => -2, 'p_label' => 'p'])->struct('fx_point'),
        );
        self::assertSame(
            ['name' => 'tri', 'vertices' => [['x' => 1, 'y' => 0, 'label' => 'v1']]],
            $this->connection->function('fx_object.make_polygon', ['p_name' => 'tri', 'p_sides' => 1], new ExecuteOptions(keyCase: KeyCase::Lower))->struct('FX_POLYGON'),
        );
        self::assertSame(['a', null, 'b'], $this->connection->function('fx_object.split_csv', ['p_csv' => 'a,,b'])->collection('fx_string_varray'));
        self::assertSame([], $this->connection->function('fx_object.split_csv', ['p_csv' => null])->collection('fx_string_varray'));
        self::assertSame([1, 3, 5], $this->connection->function('fx_kinds.sparse_numbers')->collection('fx_number_list'));
        self::assertNull($this->connection->function('fx_kinds.echo_numbers', ['p_numbers' => Collection::in('fx_number_list', null)])->collection('fx_number_list'));
        self::assertSame(
            [-1 => 5, 3 => 6],
            $this->connection->function('fx_kinds.echo_map', ['p_map' => Indexed::in('fx_object.t_number_map', [3 => 6, -1 => 5])])->indexed('fx_object.t_number_map'),
        );
        // A PL/SQL Associative array is never NULL: a NULL comes in empty.
        self::assertSame([], $this->connection->function('fx_kinds.echo_map', ['p_map' => Indexed::in('fx_object.t_number_map', null)])->indexed('fx_object.t_number_map'));
    }

    #[Test]
    public function an_object_terminal_of_the_wrong_kind_fails_naming_the_fix(): void
    {
        try {
            $this->connection->function('fx_object.split_csv', ['p_csv' => 'a'])->struct('fx_string_varray');
            self::fail('struct() read a Collection.');
        } catch (UsageException $e) {
            self::assertStringStartsWith('The return value of fx_object.split_csv: ', $e->getMessage());
            self::assertStringEndsWith('.FX_STRING_VARRAY is a Collection type (nested table or VARRAY), not an Object type; use Collection, not Struct.', $e->getMessage());
            self::assertSame('fx_object.split_csv', $e->call());
        }
    }

    #[Test]
    public function the_temporal_terminals_return_the_mapped_value(): void
    {
        $string = new ExecuteOptions(values: new ValueMapping(temporals: TemporalMode::String));
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
                    self::assertSame($object, Temporals::shown($connection->function($function)->{$terminal}()), $terminal);
                    self::assertNull($connection->function($function, ['p_null' => true])->{$terminal}(), $terminal);
                    if ($text !== null) {
                        self::assertSame($text, $connection->function($function, [], $string)->{$terminal}(), $terminal);
                    }
                }
            } finally {
                $connection->close();
            }
        });
    }

    #[Test]
    public function the_number_terminal_follows_the_value_mapping(): void
    {
        self::assertSame(42, $this->connection->function('fx_call.f_number', ['p_value' => 42])->number());
        self::assertSame('12.5', $this->connection->function('fx_call.f_number', ['p_value' => Number::in('12.5')])->number());
        self::assertSame(12.5, $this->connection->function(
            'fx_call.f_number',
            ['p_value' => Number::in('12.5')],
            new ExecuteOptions(values: new ValueMapping(numbers: NumberMode::Float)),
        )->number());
        self::assertNull($this->connection->function('fx_call.f_number', ['p_value' => null])->number());
    }

    #[Test]
    public function the_rowid_terminal_returns_the_rowid_text(): void
    {
        $rowid = $this->connection->select('select rowidtochar(rowid) from fx_items where id = 1')->value();

        self::assertSame($rowid, $this->connection->function('fx_call.f_rowid')->rowid());
        self::assertNull($this->connection->function('fx_call.f_rowid', ['p_null' => true])->rowid());
    }

    #[Test]
    public function a_call_reads_varchar2_values_of_the_largest_size(): void
    {
        $ascii = $this->connection->procedure('fx_scalar.repeat_out', ['p_char' => 'x', 'p_length' => 32767, 'o_str' => Varchar::out()]);
        $multibyte = $this->connection->procedure('fx_scalar.repeat_out', ['p_char' => 'ç', 'p_length' => 16383, 'o_str' => Varchar::out()]);

        self::assertSame(str_repeat('x', 32767), $ascii->out('o_str')->string());
        self::assertSame(str_repeat('ç', 16383), $multibyte->out('o_str')->string());

        foreach ([null, 'a'] as $start) {
            $grown = $this->connection->procedure('fx_call.grow', ['io_text' => Varchar::inOut($start), 'p_length' => 32767]);
            self::assertSame(str_repeat('a', 32767), $grown->out('io_text')->string());
        }
    }

    #[Test]
    public function a_failing_call_carries_its_name_and_generated_block(): void
    {
        $calls = [
            'BEGIN fx_call.fail(p_value => :b1); END;' => fn(): Execution => $this->connection->procedure('fx_call.fail', ['p_value' => 'x']),
            'BEGIN :b0 := fx_call.f_fail(p_value => :b1); END;' => fn(): int|float|string|null => $this->connection->function('fx_call.f_fail', ['p_value' => 'x'])->number(),
        ];
        foreach ($calls as $sql => $call) {
            try {
                $call();
                self::fail("$sql did not fail.");
            } catch (QueryException $e) {
                self::assertSame(20046, $e->getCode());
                self::assertStringStartsWith('ORA-20046: fx_call.', $e->getMessage());
                self::assertSame($sql, $e->sql());
                self::assertSame(str_contains($sql, ':b0') ? 'fx_call.f_fail' : 'fx_call.fail', $e->call());
            }
        }

        try {
            $this->connection->procedure('fx_call.echo', ['p_num' => 1, 'P_Label' => 'x', 'o_doubled' => Number::out(), 'o_label' => Varchar::out(), 'io_text' => Varchar::inOut(null)])->out('nothing');
            self::fail('out() found a parameter that is not there.');
        } catch (UsageException $e) {
            self::assertSame("No OUT bind is named 'nothing'; they are 'o_doubled', 'o_label', 'io_text'.", $e->getMessage());
            self::assertSame('BEGIN fx_call.echo(p_num => :b1, P_Label => :b2, o_doubled => :b3, o_label => :b4, io_text => :b5); END;', $e->sql());
            self::assertSame('fx_call.echo', $e->call());
        }
    }

    /**
     * A FunctionCall made before `close()` fails when its terminal runs.
     */
    #[Test]
    public function a_call_after_close_fails(): void
    {
        $function = $this->connection->function('fx_call.f_number', ['p_value' => 1]);
        $this->connection->close();

        $calls = [
            'fx_call.echo' => fn(): Execution => $this->connection->procedure('fx_call.echo', ['p_num' => 1, 'o_doubled' => Number::out(), 'o_label' => Varchar::out(), 'io_text' => Varchar::inOut(null)]),
            'fx_call.f_number' => $function->number(...),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("$name after close() succeeded.");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage());
                self::assertSame($name, $e->call());
            }
        }
    }

    /**
     * A library-written message names the parameter the user wrote, never
     * the synthetic bind of the generated block.
     */
    #[Test]
    public function a_bind_error_names_the_parameter(): void
    {
        $calls = [
            fn(): Execution => $this->connection->procedure('fx_call.fail', ['P_Value' => new DateTimeImmutable()]),
            fn(): int|float|string|null => $this->connection->function('fx_call.f_fail', ['P_Value' => new DateTimeImmutable()])->number(),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('A DateTimeImmutable was bound.');
            } catch (UsageException $e) {
                self::assertSame("Bind 'P_Value': a DateTimeInterface does not bind by itself; use Date::in() or Timestamp::in().", $e->getMessage());
                self::assertStringStartsWith('fx_call.', (string) $e->call());
            }
        }
    }

    #[Test]
    public function a_bound_value_never_appears_in_a_call_error(): void
    {
        $secret = 'zq46secret';
        $calls = [
            'procedure' => fn(): Execution => $this->connection->procedure('fx_call.echo', ['p_num' => $secret, 'o_doubled' => Number::out(), 'o_label' => Varchar::out(), 'io_text' => Varchar::inOut($secret)]),
            'function' => fn(): int|float|string|null => $this->connection->function('fx_call.f_number', ['p_value' => $secret])->number(),
            'procedure, usage' => fn(): Execution => $this->connection->procedure('fx_call.fail', ['p_value' => [$secret]]),
            'function, usage' => fn(): int|float|string|null => $this->connection->function('fx_call.f_fail', ['p_value' => [$secret]])->number(),
        ];
        foreach ($calls as $kind => $call) {
            try {
                $call();
                self::fail("$kind: '$secret' was taken for a number.");
            } catch (OracleException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage(), $kind);
                self::assertStringNotContainsString($secret, $e->getTraceAsString(), $kind);
                self::assertStringNotContainsString($secret, (string) $e, $kind);
            }
        }
    }

    #[Test]
    public function a_quoted_mixed_case_name_and_a_schema_qualified_name_work(): void
    {
        $schema = $this->connection->select("select sys_context('userenv', 'current_schema') from dual")->value();
        self::assertIsString($schema);

        self::assertSame(42, $this->connection->function('"FX_Mixed_Case"', ['"pValue"' => 21])->number());
        self::assertSame(8, $this->connection->function("$schema.\"FX_Mixed_Case\"", ['"pValue"' => 4])->number());
        self::assertSame(3, $this->connection->function("$schema.fx_call.f_number", ['P_VALUE' => 3])->number());
        self::assertSame('Hello, you!', $this->connection->function('FX_SCALAR.Greet', ['p_name' => 'you'])->varchar());
    }

    /**
     * @return iterable<string, array{string, array<mixed>, string}>
     */
    public static function invalidNames(): iterable
    {
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
    }

    /**
     * @param array<mixed> $binds
     */
    #[Test]
    #[DataProvider('invalidNames')]
    public function an_invalid_name_fails_before_any_round_trip(string $name, array $binds, string $message): void
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);
        $counter = RoundTripCounter::open();

        try {
            $calls = [
                // @phpstan-ignore argument.type (a list is one of the mistakes under test)
                fn(): Execution => $this->connection->procedure($name, $binds),
                // @phpstan-ignore argument.type (a list is one of the mistakes under test)
                fn(): FunctionCall => $this->connection->function($name, $binds),
            ];
            foreach ($calls as $call) {
                $trips = $counter->during((int) $sid, static function () use ($call, $name, $message): void {
                    try {
                        $call();
                        self::fail("'$name' was accepted.");
                    } catch (UsageException $e) {
                        self::assertStringContainsString($message, $e->getMessage());
                        self::assertSame($name, $e->call());
                        self::assertNull($e->sql());
                    }
                });
                self::assertSame(0, $trips);
            }
        } finally {
            $counter->close();
        }
    }
}
