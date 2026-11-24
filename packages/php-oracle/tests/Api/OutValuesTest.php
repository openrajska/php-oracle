<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Boolean;
use Rajska\Oracle\Bind\Char;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\Collection;
use Rajska\Oracle\Bind\Date;
use Rajska\Oracle\Bind\Indexed;
use Rajska\Oracle\Bind\IntervalDs;
use Rajska\Oracle\Bind\IntervalYm;
use Rajska\Oracle\Bind\Json;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Struct;
use Rajska\Oracle\Bind\TimestampTz;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Bind\Vector;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\SparseVector;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\Tests\Support\Temporals;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;
use Rajska\Oracle\VectorFormat;

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
     * `dateTime()` and `interval()` read temporal OUT values, NULL included,
     * and narrow like every getter.
     */
    #[Test]
    public function date_time_and_interval_read_temporal_out_values(): void
    {
        $sql = "begin
                  :d := date '2026-03-01'; :tz := timestamp '2026-03-01 10:00:00 +05:45';
                  :ds := interval '1 02:00:00' day to second; :nothing := null;
                end;";
        $binds = ['d' => Date::out(), 'tz' => TimestampTz::out(), 'ds' => IntervalDs::out(), 'nothing' => IntervalYm::out()];
        $execution = Temporals::inZone('UTC', fn () => $this->connection->run($sql, $binds));

        self::assertSame('+2026-03-01 00:00:00.000000 UTC', Temporals::shown($execution->out('d')->dateTime()));
        self::assertSame('+2026-03-01 10:00:00.000000 +05:45', Temporals::shown($execution->out('tz')->dateTime()));
        self::assertSame('+0Y0M1DT2H0M0S.000000', Temporals::shown($execution->out('ds')->interval()));
        self::assertNull($execution->out('nothing')->interval());
        self::assertNull($execution->out('nothing')->dateTime());

        self::assertFails(fn () => $execution->out('d')->interval(), "OUT bind 'd' holds DateTimeImmutable, not a DateInterval. Read it with dateTime().");
        self::assertFails(fn () => $execution->out('ds')->dateTime(), "OUT bind 'ds' holds DateInterval, not a DateTimeImmutable. Read it with interval().");
        self::assertFails(fn () => $execution->out('d')->string(), "OUT bind 'd' holds DateTimeImmutable, not a string. Read it with dateTime().");

        $string = $this->connection->run($sql, $binds, new ExecuteOptions(values: new ValueMapping(temporals: TemporalMode::String)));
        self::assertSame('2026-03-01 00:00:00', $string->out('d')->string());
        self::assertFails(fn () => $string->out('d')->dateTime(), "OUT bind 'd' holds string, not a DateTimeImmutable. Under ValueMapping(temporals: TemporalMode::String), a temporal value arrives as ISO text: read it with string().");
    }

    /**
     * Objects 7: `array()` reads an Object, a Collection or an Associative
     * array, NULL included; it fails on a scalar, and a scalar getter on an
     * array names `array()`.
     */
    #[Test]
    public function array_reads_objects_and_collections_and_fails_on_a_scalar(): void
    {
        $execution = $this->connection->run(
            'begin :point := fx_point(1, 2, :label); :tags := :tags; :map := :map; :none := null; :n := 1; end;',
            [
                'label' => 'p',
                'point' => Struct::out('fx_point'),
                'tags' => Collection::inOut('fx_string_varray', ['a', null]),
                'map' => Indexed::inOut('fx_object.t_number_map', [-1 => 1]),
                'none' => Struct::out('fx_point'),
                'n' => Number::out(),
            ],
        );

        self::assertSame(['X' => 1, 'Y' => 2, 'LABEL' => 'p'], $execution->out('point')->array());
        self::assertSame(['a', null], $execution->out('tags')->array());
        self::assertSame([-1 => 1], $execution->out('map')->array());
        self::assertNull($execution->out('none')->array());
        self::assertFails(fn () => $execution->out('n')->array(), "OUT bind 'n' holds int, not an array. Read it with int().");
        self::assertFails(fn () => $execution->out('point')->string(), "OUT bind 'point' holds array, not a string. Read it with array().");
    }

    /**
     * A JSON object or array is read with `array()`, a JSON scalar with its
     * own getter; a VECTOR is a list or a `SparseVector`, read with
     * `value()`.
     */
    #[Test]
    public function json_and_vector_out_values_have_their_mapped_types(): void
    {
        $execution = $this->connection->run(
            "begin :doc := :doc; :scalar := json('5'); :dense := :dense; :sparse := :sparse; end;",
            [
                'doc' => Json::inOut(['a' => [1, 0.5]]),
                'scalar' => Json::out(),
                'dense' => Vector::inOut([1, 2], VectorFormat::Int8),
                'sparse' => Vector::inOut(new SparseVector(4, [2 => 0.5])),
            ],
        );

        self::assertSame(['a' => [1, '0.5']], $execution->out('doc')->array());
        self::assertSame(5, $execution->out('scalar')->int());
        self::assertSame([1, 2], $execution->out('dense')->array());
        $sparse = $execution->out('sparse')->value();
        self::assertInstanceOf(SparseVector::class, $sparse);
        self::assertSame([4, [2 => 0.5]], [$sparse->dimensions, $sparse->values]);
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
     * Scope 4 (#73): a LOB, an Object and a Collection come back from SQL
     * `RETURNING … INTO` like any other type.
     */
    #[Test]
    public function returning_into_a_lob_an_object_or_a_collection_gives_one_value_per_row(): void
    {
        $lob = $this->connection->run(
            'update fx_lobs set label = label where id = 1 returning text_value into :t',
            ['t' => Clob::out()],
        );
        self::assertSame("Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}", $lob->out('t')->lob()?->contents());

        $shapes = $this->connection->run(
            'update fx_shapes set id = id where id in (1, 2) returning id, center, tags into :id, :center, :tags',
            ['id' => Number::out(), 'center' => Struct::out('fx_point'), 'tags' => Collection::out('fx_string_varray')],
        );
        $rows = array_map(null, $shapes->out('id')->list(), $shapes->out('center')->list(), $shapes->out('tags')->list());
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame([
            [1, ['X' => '1.5', 'Y' => -2, 'LABEL' => 'center'], ['red', "caf\u{e9}"]],
            [2, null, null],
        ], $rows);
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
