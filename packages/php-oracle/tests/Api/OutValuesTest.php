<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Boolean;
use Rajska\Oracle\Bind\Char;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;

/**
 * Typed binds go IN, OUT and IN OUT; `out()` reads what came back, and its
 * getters narrow without converting.
 */
final class OutValuesTest extends TestCase
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

    #[Test]
    public function a_block_reads_its_out_and_in_out_binds(): void
    {
        $execution = $this->connection->run(
            'begin :total := :a + :b; :greeting := :greeting || \', world\'; :ok := :total > 2; end;',
            ['a' => 1, 'b' => Number::in('2.5'), 'total' => Number::out(), 'greeting' => Varchar::inOut('hello'), 'ok' => Boolean::out()],
        );

        self::assertSame('3.5', $execution->out('total')->string());
        self::assertSame('hello, world', $execution->out('greeting')->string());
        self::assertTrue($execution->out('ok')->bool());
        self::assertSame(['3.5'], $execution->out('total')->list());
        self::assertSame('3.5', $execution->out('total')->value());
    }

    /**
     * Seam and rows 16.
     */
    #[Test]
    public function getters_narrow_and_never_convert(): void
    {
        $execution = $this->connection->run(
            'begin :fraction := 12.5; :integral := 5; :nothing := null; :text := \'5\'; end;',
            ['fraction' => Number::out(), 'integral' => Number::out(), 'nothing' => Number::out(), 'text' => Varchar::out()],
        );

        self::assertSame(5, $execution->out('integral')->int());
        self::assertNull($execution->out('nothing')->int());
        self::assertNull($execution->out('nothing')->string());
        self::assertNull($execution->out('nothing')->bool());
        self::assertNull($execution->out('nothing')->value());

        self::assertFails(fn () => $execution->out('fraction')->int(), "OUT bind 'fraction' holds string, not an int. A NUMBER that is not an integer, or beyond 64 bits, arrives as its exact string: read it with string(), or map it to a float with ValueMapping(numbers: NumberMode::Float).");
        self::assertFails(fn () => $execution->out('integral')->float(), "OUT bind 'integral' holds int, not a float. Read it with int().");
        self::assertFails(fn () => $execution->out('integral')->string(), "OUT bind 'integral' holds int, not a string. Read it with int().");
        self::assertFails(fn () => $execution->out('text')->int(), "OUT bind 'text' holds string, not an int.");
        self::assertFails(fn () => $execution->out('text')->bool(), "OUT bind 'text' holds string, not a bool.");
    }

    /**
     * Seam and rows 16, Values 16: the ValueMapping of the Execution decides
     * the PHP type, and the getter follows it.
     */
    #[Test]
    public function the_value_mapping_decides_what_a_getter_finds(): void
    {
        $execution = $this->connection->run(
            'begin :fraction := 12.5; end;',
            ['fraction' => Number::out()],
            new ExecuteOptions(values: new ValueMapping(numbers: NumberMode::Float)),
        );

        self::assertSame(12.5, $execution->out('fraction')->float());
    }

    /**
     * Seam and rows 17, Scope 4.
     */
    #[Test]
    public function returning_into_gives_one_value_per_row(): void
    {
        $one = $this->connection->run(
            'insert into fx_bind_rows (id, code) values (:id, :code) returning id, code into :new_id, :new_code',
            ['id' => 42, 'code' => Char::in('AB'), 'new_id' => Number::out(), 'new_code' => Char::out()],
        );
        self::assertSame(1, $one->affected());
        self::assertSame(42, $one->out('new_id')->int());
        self::assertSame('AB   ', $one->out('new_code')->string());
        self::assertSame([42], $one->out('new_id')->list());

        $two = $this->connection->run('update fx_items set price = price where id in (3, 4) returning id into :id', ['id' => Number::out()]);
        $none = $this->connection->run('update fx_items set price = price where id < 0 returning id into :id', ['id' => Number::out()]);

        self::assertEqualsCanonicalizing([3, 4], $two->out('id')->list());
        self::assertSame([], $none->out('id')->list());
        self::assertFails(fn () => $two->out('id')->int(), "OUT bind 'id' returned 2 rows, and int() needs exactly one; read every returned row with list().");
        self::assertFails(fn () => $none->out('id')->int(), "OUT bind 'id' returned 0 rows, and int() needs exactly one; read every returned row with list().");
        self::assertFails(fn () => $none->out('id')->value(), "OUT bind 'id' returned 0 rows, and value() needs exactly one");
    }

    /**
     * Seam and rows 15.
     */
    #[Test]
    public function out_with_an_unknown_or_in_only_name_fails(): void
    {
        $sql = 'begin :total := :a; :Label := \'x\'; end;';
        $execution = $this->connection->run($sql, ['a' => 1, 'total' => Number::out(), 'Label' => Varchar::inOut(null)]);

        self::assertFails(fn () => $execution->out('missing'), "No OUT bind is named 'missing'; they are 'total', 'Label'.", $sql);
        self::assertFails(fn () => $execution->out('a'), "Bind 'a' is IN only, so it has no OUT value; bind it with out() or inOut() of a typed bind to read it back.", $sql);
        self::assertFails(
            fn () => $this->connection->run('begin null; end;')->out('x'),
            "No OUT bind is named 'x'; the statement has none.",
        );
        self::assertFails(
            fn () => $this->connection->run('select :v from dual', ['v' => Varchar::in('y')])->out('v'),
            "Bind 'v' is IN only",
        );
    }

    /**
     * Binds 15: the name is matched ignoring case, as placeholders are.
     */
    #[Test]
    public function out_matches_the_name_ignoring_case(): void
    {
        $execution = $this->connection->run('begin :TOTAL := 7; end;', ['Total' => Number::out()]);

        self::assertSame(7, $execution->out('Total')->int());
        self::assertSame(7, $execution->out('total')->int());
    }

    /**
     * Binds 9: an invalid decimal string fails when the bind is built,
     * before any Round trip.
     */
    #[Test]
    public function a_number_string_must_be_a_decimal_literal(): void
    {
        foreach (['1,5', '1e5', '', ' 1', 'abc', '1.2.3', '0x1A'] as $text) {
            self::assertFails(fn () => Number::in($text), "Number::in() takes a decimal literal with '.' as the separator, such as '12.30'");
            self::assertFails(fn () => Number::inOut($text), "Number::in() takes a decimal literal");
        }
        self::assertSame('-.5', Number::in('-.5')->value);
        self::assertSame('12.', Number::in('12.')->value);
    }

    private static function assertFails(\Closure $call, string $message, ?string $sql = null): void
    {
        try {
            $call();
            self::fail("Expected a UsageException: $message");
        } catch (UsageException $e) {
            self::assertStringContainsString($message, $e->getMessage());
            if ($sql !== null) {
                self::assertSame($sql, $e->sql());
            }
        }
    }
}
