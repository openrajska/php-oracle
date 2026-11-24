<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\Binds\Color;
use Rajska\Oracle\Tests\Support\Binds\Priority;
use Rajska\Oracle\Tests\Support\Binds\StringableDate;
use Rajska\Oracle\Tests\Support\Binds\Suit;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

/**
 * Conformance catalog, Binds (#22): plain PHP values, named only.
 *
 * The Connections run without a Statement cache: these tests bind one SQL
 * text with values of different types, and a pooled Session reused from an
 * earlier test could serve a cached statement described for other bind
 * types (on one Session, `select :v from dual` bound 'x' and then 2
 * returns '2'). That is a bug of the crate's Statement cache, found with
 * #39, not what these tests cover.
 */
final class BindsTest extends ConformanceTestCase
{
    /**
     * What `dump()` reports for a bind: its Oracle type code first
     * (1 = VARCHAR2, 2 = NUMBER, 252 = BOOLEAN), then its bytes.
     *
     * @return iterable<string, array{mixed, string, mixed}>
     */
    public static function inferred(): iterable
    {
        yield 'int' => [42, 'Typ=2 Len=2: 193,43', 42];
        yield 'negative int' => [-7, 'Typ=2 Len=3: 62,94,102', -7];
        yield 'PHP_INT_MAX' => [\PHP_INT_MAX, 'Typ=2 Len=11: 202,10,23,34,73,4,69,55,78,59,8', \PHP_INT_MAX];
        yield 'float' => [2.5, 'Typ=2 Len=3: 193,3,51', '2.5'];
        yield 'integral float' => [5.0, 'Typ=2 Len=2: 193,6', 5];
        yield 'negative zero' => [-0.0, 'Typ=2 Len=1: 128', 0];
        yield 'string' => ['héllo', 'Typ=1 Len=6: 104,195,169,108,108,111', 'héllo'];
        yield 'numeric string' => ['12.30', 'Typ=1 Len=5: 49,50,46,51,48', '12.30'];
        yield 'int-backed enum' => [Priority::High, 'Typ=2 Len=2: 193,3', 2];
        yield 'string-backed enum' => [Color::Green, 'Typ=1 Len=5: 103,114,101,101,110', 'green'];
        yield 'Stringable' => [new class () implements \Stringable {
            public function __toString(): string
            {
                return 'from __toString';
            }
        }, 'Typ=1 Len=15: 102,114,111,109,32,95,95,116,111,83,116,114,105,110,103', 'from __toString'];
    }

    /**
     * Binds 1: each plain value binds as the type §7 infers and reads back.
     */
    #[Test]
    #[DataProvider('inferred')]
    public function a_plain_value_binds_as_its_inferred_type(mixed $value, string $dump, mixed $readBack): void
    {
        $connection = $this->connect(statementCacheSize: 0);

        self::assertSame($dump, self::value($connection, 'select dump(:v) from dual', ['v' => $value]));
        self::assertSame($readBack, self::value($connection, 'select :v from dual', ['v' => $value]));
    }

    /**
     * Binds 1: a bool binds as BOOLEAN (type 252).
     */
    #[Test]
    public function a_bool_binds_as_boolean(): void
    {
        $connection = $this->connect(statementCacheSize: 0);

        self::assertSame('Typ=252 Len=4: 1,0,0,0', self::value($connection, 'select dump(:v) from dual', ['v' => true]));
        self::assertSame('Typ=252 Len=4: 0,0,0,0', self::value($connection, 'select dump(:v) from dual', ['v' => false]));
        self::assertSame('yes', self::value($connection, "select case when :v then 'yes' else 'no' end from dual", ['v' => true]));
        self::assertSame('no', self::value($connection, "select case when :v then 'yes' else 'no' end from dual", ['v' => false]));
    }

    /**
     * Binds 1: null binds as a NULL VARCHAR2.
     */
    #[Test]
    public function null_binds_as_null(): void
    {
        $connection = $this->connect(statementCacheSize: 0);

        self::assertSame('NULL', self::value($connection, 'select dump(:v) from dual', ['v' => null]));
        self::assertNull(self::value($connection, 'select :v from dual', ['v' => null]));
        self::assertSame(0, self::value($connection, 'select count(*) from fx_items where name = :v', ['v' => null]));
    }

    /**
     * Binds 1.
     */
    #[Test]
    public function the_float_0_1_stores_exactly_0_1(): void
    {
        $connection = $this->connect(statementCacheSize: 0);

        // Bound as BINARY_DOUBLE (C4), 0.1 would become
        // 0.1000000000000000055511151231257827 once cast to NUMBER.
        self::assertSame(1, self::value(
            $connection,
            'select case when cast(:f as number) = 0.1 then 1 else 0 end from dual',
            ['f' => 0.1],
        ));
        self::assertSame('0.1', self::value($connection, 'select :f from dual', ['f' => 0.1]));
    }

    /**
     * Binds 1: NUMBER's whole range takes a float, each stored as its
     * shortest round-trip text.
     */
    #[Test]
    public function floats_bind_exactly_across_number_range(): void
    {
        $connection = $this->connect(statementCacheSize: 0);

        foreach (['1e-130' => 1e-130, '-1.5e-7' => -1.5e-7, '1e30' => 1e30, '9.99e125' => 9.99e125, '0.30000000000000004' => 0.1 + 0.2] as $literal => $float) {
            self::assertSame(1, self::value(
                $connection,
                "select case when cast(:f as number) = $literal then 1 else 0 end from dual",
                ['f' => $float],
            ), $literal);
        }
    }

    /**
     * Binds 2, 15; Errors 11: each mistake names the fix, and the
     * statement never reaches the server.
     *
     * @return iterable<string, array{array<mixed>, string, string}>
     */
    public static function mistakes(): iterable
    {
        $one = 'select :v from dual';
        $date = 'use Date::in() or Timestamp::in()';
        $json = 'use Json::in()';

        yield 'DateTimeImmutable' => [['v' => new \DateTimeImmutable()], $one, $date];
        yield 'DateTime' => [['v' => new \DateTime()], $one, $date];
        yield 'a Stringable date, as Carbon' => [['v' => new StringableDate()], $one, $date];
        yield 'array' => [['v' => [1, 2]], $one, $json];
        yield 'JsonSerializable' => [['v' => new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return [];
            }
        }], $one, $json];
        yield 'other object' => [['v' => new \stdClass()], $one, 'an object of class stdClass does not bind by itself; use Json::in() or a typed bind'];
        yield 'enum without backing value' => [['v' => Suit::Hearts], $one, 'an object of class '.Suit::class.' does not bind by itself'];
        yield 'resource' => [['v' => fopen('php://memory', 'r')], $one, 'use Clob::in() or Blob::in()'];
        yield 'bytes that are not UTF-8' => [['v' => "\xff\xfe"], $one, 'bind binary data with Raw::in() or Blob::in()'];
        yield 'NaN' => [['v' => \NAN], $one, 'bind it with BinaryDouble::in()'];
        yield 'INF' => [['v' => \INF], $one, 'bind it with BinaryDouble::in()'];
        yield 'float beyond NUMBER' => [['v' => 1e126], $one, 'bind it with BinaryDouble::in()'];
        yield 'float below NUMBER' => [['v' => 1e-131], $one, 'bind it with BinaryDouble::in()'];
        yield 'list' => [[1], $one, 'key 0 is an integer (is the array a list?)'];
        yield 'int key' => [[5 => 1], $one, 'key 5 is an integer'];
        yield 'numeric string key' => [['7' => 1], $one, 'key 7 is an integer'];
        yield 'key starting with :' => [[':v' => 1], $one, "write the key without ':', as 'v'"];
        yield 'keys differing only in case' => [['v' => 1, 'V' => 2], $one, "Binds 'v' and 'V' differ only in case"];
        yield 'extra bind' => [['v' => 1, 'w' => 2], $one, "Bind 'w' matches no placeholder in the SQL; its placeholders are :V"];
        yield 'missing bind' => [['v' => 1], 'select :v, :w from dual', "Placeholder :W has no bind; add it to the binds, keyed 'w'"];
        yield 'no bind at all' => [[], $one, 'Placeholder :V has no bind'];
        yield 'a bind and no placeholder' => [['v' => 1], 'select 1 from dual', "Bind 'v' matches no placeholder: the SQL has none"];
        yield '? with a list' => [[1], 'select ? from dual', "Positional and '?' placeholders are not accepted"];
        yield '? with a name' => [['v' => 1], 'select ? from dual', "'?' is not accepted"];
    }

    /**
     * @param array<mixed> $binds
     */
    #[Test]
    #[DataProvider('mistakes')]
    public function a_bind_mistake_fails_before_any_round_trip_naming_the_fix(array $binds, string $sql, string $fix): void
    {
        $connection = $this->connect(statementCacheSize: 0);
        $sid = self::sid($connection);
        $counter = RoundTripCounter::open();

        try {
            $roundTrips = $counter->during($sid, function () use ($connection, $sql, $binds, $fix): void {
                try {
                    // @phpstan-ignore argument.type (the mistake is the point)
                    $connection->execute($sql, $binds, self::options());
                    self::fail('The bind mistake ran.');
                } catch (UsageException $e) {
                    self::assertStringContainsString($fix, $e->getMessage());
                    self::assertSame($sql, $e->sql());
                }
            });
        } finally {
            $counter->close();
        }

        self::assertSame(0, $roundTrips);
    }

    /**
     * A Stringable whose __toString() throws: its own exception reaches the
     * caller, and the Connection still works.
     */
    #[Test]
    public function an_exception_from_to_string_reaches_the_caller(): void
    {
        $connection = $this->connect(statementCacheSize: 0);
        $thrown = new \DomainException('from __toString');

        try {
            $connection->execute('select :v from dual', ['v' => new class ($thrown) implements \Stringable {
                public function __construct(private readonly \Throwable $thrown)
                {
                }

                public function __toString(): string
                {
                    throw $this->thrown;
                }
            }], self::options());
            self::fail('The bind ran.');
        } catch (\DomainException $e) {
            self::assertSame($thrown, $e);
        }

        self::assertSame(1, self::value($connection, 'select :v from dual', ['v' => 1]));
    }
}
