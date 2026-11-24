<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
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
     * Binds 13 (non-cursor parts): IN, OUT and IN OUT in one Call, read back
     * by parameter name; the omitted p_label takes its PL/SQL default.
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
     * Seam and rows 1, the Call: a procedure gives a complete Execution,
     * with no count and no rows.
     */
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

    /**
     * A terminal whose type comes later fails with UnsupportedException
     * before any Round trip.
     */
    #[Test]
    public function a_terminal_of_a_later_type_fails_before_any_round_trip(): void
    {
        $sid = $this->connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);
        $counter = RoundTripCounter::open();
        $function = $this->connection->function('fx_scalar.add_days', ['p_date' => null, 'p_days' => 1]);
        $terminals = [
            'date()' => $function->date(...),
            'timestamp()' => $function->timestamp(...),
            'timestampTz()' => $function->timestampTz(...),
            'timestampLtz()' => $function->timestampLtz(...),
            'intervalDs()' => $function->intervalDs(...),
            'intervalYm()' => $function->intervalYm(...),
            'clob()' => $function->clob(...),
            'nclob()' => $function->nclob(...),
            'blob()' => $function->blob(...),
            'json()' => $function->json(...),
            'vector()' => $function->vector(...),
            'cursor()' => $function->cursor(...),
            'struct()' => fn () => $function->struct('fx_point'),
            'collection()' => fn () => $function->collection('fx_number_list'),
            'indexed()' => fn () => $function->indexed('fx_object.t_number_map'),
        ];

        try {
            foreach ($terminals as $terminal => $call) {
                $trips = $counter->during((int) $sid, static function () use ($call, $terminal): void {
                    try {
                        $call();
                        self::fail("$terminal ran.");
                    } catch (UnsupportedException $e) {
                        self::assertSame("FunctionCall::$terminal is not implemented yet.", $e->getMessage());
                        self::assertSame('fx_scalar.add_days', $e->call());
                    }
                });
                self::assertSame(0, $trips, $terminal);
            }
        } finally {
            $counter->close();
        }
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

    /**
     * Seam and rows 14, Binds 13: each scalar terminal returns the value
     * mapped as an OUT value of its type, and a NULL return gives null.
     */
    #[Test]
    #[DataProvider('scalarTerminals')]
    public function a_scalar_terminal_returns_the_mapped_value_and_null_for_null(string $terminal, string $function, mixed $expected): void
    {
        self::assertSame($expected, $this->connection->function($function)->{$terminal}());
        self::assertNull($this->connection->function($function, ['p_null' => true])->{$terminal}());
    }

    /**
     * Seam and rows 14, Binds 13: NUMBER follows the value mapping.
     */
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

    /**
     * Seam and rows 14: ROWID comes back as its text.
     */
    #[Test]
    public function the_rowid_terminal_returns_the_rowid_text(): void
    {
        $rowid = $this->connection->select('select rowidtochar(rowid) from fx_items where id = 1')->value();

        self::assertSame($rowid, $this->connection->function('fx_call.f_rowid')->rowid());
        self::assertNull($this->connection->function('fx_call.f_rowid', ['p_null' => true])->rowid());
    }

    /**
     * Binds 5, the Call part: an OUT VARCHAR2 takes the largest buffer, and
     * an IN OUT grows from NULL and from 'a' to 32767 bytes.
     */
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

    /**
     * Errors 4: a failing Call has call() = the name and sql() = the
     * generated block, in named notation with synthetic binds.
     */
    #[Test]
    public function a_failing_call_carries_its_name_and_generated_block(): void
    {
        $calls = [
            'BEGIN fx_call.fail(p_value => :b1); END;' => fn () => $this->connection->procedure('fx_call.fail', ['p_value' => 'x']),
            'BEGIN :b0 := fx_call.f_fail(p_value => :b1); END;' => fn () => $this->connection->function('fx_call.f_fail', ['p_value' => 'x'])->number(),
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
     * A library-written message names the parameter the user wrote, never
     * the synthetic bind of the generated block.
     */
    #[Test]
    public function a_bind_error_names_the_parameter(): void
    {
        $calls = [
            fn () => $this->connection->procedure('fx_call.fail', ['P_Value' => new \DateTimeImmutable()]),
            fn () => $this->connection->function('fx_call.f_fail', ['P_Value' => new \DateTimeImmutable()])->number(),
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

    /**
     * Errors 5, for procedure() and function(): a distinctive bound value
     * never appears in the message, the trace string or the exception as a
     * string.
     */
    #[Test]
    public function a_bound_value_never_appears_in_a_call_error(): void
    {
        $secret = 'zq46secret';
        $calls = [
            'procedure' => fn () => $this->connection->procedure('fx_call.echo', ['p_num' => $secret, 'o_doubled' => Number::out(), 'o_label' => Varchar::out(), 'io_text' => Varchar::inOut($secret)]),
            'function' => fn () => $this->connection->function('fx_call.f_number', ['p_value' => $secret])->number(),
            'procedure, usage' => fn () => $this->connection->procedure('fx_call.fail', ['p_value' => [$secret]]),
            'function, usage' => fn () => $this->connection->function('fx_call.f_fail', ['p_value' => [$secret]])->number(),
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

    /**
     * Binds 14: a quoted mixed-case name and parameter work, and a name may
     * have up to three parts.
     */
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
     * Binds 14, Errors 11: an invalid name fails with UsageException before
     * any Round trip, for procedure() and function() alike.
     *
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
                fn () => $this->connection->procedure($name, $binds),
                // @phpstan-ignore argument.type (a list is one of the mistakes under test)
                fn () => $this->connection->function($name, $binds),
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
