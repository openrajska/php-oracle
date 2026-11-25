<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\BindsTest;

use DateTime;
use DateTimeImmutable;
use DomainException;
use JsonSerializable;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\Binds\Color;
use Rajska\Oracle\Tests\Support\Binds\Priority;
use Rajska\Oracle\Tests\Support\Binds\StringableDate;
use Rajska\Oracle\Tests\Support\Binds\Suit;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use stdClass;
use Stringable;
use Throwable;

/**
 * What `dump()` reports for a bind: its Oracle type code first
 * (1 = VARCHAR2, 2 = NUMBER, 252 = BOOLEAN), then its bytes.
 *
 * @return iterable<string, array{mixed, string, mixed}>
 */
dataset('inferred', function () {
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
    yield 'Stringable' => [new class implements Stringable {
        public function __toString(): string
        {
            return 'from __toString';
        }
    }, 'Typ=1 Len=15: 102,114,111,109,32,95,95,116,111,83,116,114,105,110,103', 'from __toString'];
});
test('a plain value binds as its inferred type', function (mixed $value, string $dump, mixed $readBack): void {
    $connection = $this->connect();

    expect(self::value($connection, 'select dump(:v) from dual', ['v' => $value]))->toBe($dump)
        ->and(self::value($connection, 'select :v from dual', ['v' => $value]))->toBe($readBack);
})->with('inferred');
test('a bool binds as boolean', function (): void {
    $connection = $this->connect();

    expect(self::value($connection, 'select dump(:v) from dual', ['v' => true]))->toBe('Typ=252 Len=4: 1,0,0,0')
        ->and(self::value($connection, 'select dump(:v) from dual', ['v' => false]))->toBe('Typ=252 Len=4: 0,0,0,0')
        ->and(self::value($connection, "select case when :v then 'yes' else 'no' end from dual", ['v' => true]))->toBe('yes')
        ->and(self::value($connection, "select case when :v then 'yes' else 'no' end from dual", ['v' => false]))->toBe('no');
});
test('a string over 32767 bytes binds as a clob', function (): void {
    $connection = $this->connect();

    // 4-byte characters, so a cut inside one would show.
    $text = static fn(int $bytes): string => str_repeat('x', $bytes % 4) . str_repeat("\u{1f600}", intdiv($bytes, 4));

    foreach ([4000 => 'VARCHAR2', 4001 => 'VARCHAR2', 32767 => 'VARCHAR2', 32768 => 'CLOB', 1 << 20 => 'CLOB'] as $bytes => $type) {
        $value = $text($bytes);
        $valueBytes = \strlen($value);
        expect($valueBytes)->toBe($bytes);
        $bound = $connection->execute(
            "declare
                   function type_of(v varchar2) return varchar2 is begin return 'VARCHAR2'; end;
                   function type_of(v clob) return varchar2 is begin return 'CLOB'; end;
                 begin
                   :type := type_of(:v);
                   insert into fx_lobs (id, label, text_value) values (100, 'inferred', :v);
                 end;",
            ['v' => $value, 'type' => Varchar::out()],
            self::options(autocommit: false),
        )->outValues();
        expect($bound)->toBe(['type' => [$type]], "$bytes bytes");

        [$row] = self::rows($connection, 'select text_value from fx_lobs where id = 100', [], self::options(autocommit: false));
        $lob = $row['TEXT_VALUE'];
        expect($lob)->toBeInstanceOf(DriverLob::class);
        $read = '';
        while (($chunk = $lob->readChunk()) !== '') {
            $read .= $chunk;
        }
        expect(\strlen($read))->toBe($bytes, "$bytes bytes")
            ->and($read)->toBe($value, "$bytes bytes");
        $connection->rollback();
    }
});
test('null binds as null', function (): void {
    $connection = $this->connect();

    expect(self::value($connection, 'select dump(:v) from dual', ['v' => null]))->toBe('NULL')
        ->and(self::value($connection, 'select :v from dual', ['v' => null]))->toBeNull()
        ->and(self::value($connection, 'select count(*) from fx_items where name = :v', ['v' => null]))->toBe(0);
});
test('a blocks binds match its placeholders in any order and case', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        'begin :Total := :a + :b; :Echo := to_char(:b) || :a; end;',
        ['B' => 2, 'echo' => Varchar::out(), 'TOTAL' => Number::out(), 'a' => 40],
        self::options(),
    );

    expect($execution->outValues())->toBe(['echo' => ['240'], 'TOTAL' => [42]]);
});
test('the float 0 1 stores exactly 0 1', function (): void {
    $connection = $this->connect();

    // Bound as BINARY_DOUBLE (C4), 0.1 would become
    // 0.1000000000000000055511151231257827 once cast to NUMBER.
    expect(self::value(
        $connection,
        'select case when cast(:f as number) = 0.1 then 1 else 0 end from dual',
        ['f' => 0.1],
    ))->toBe(1);
    expect(self::value($connection, 'select :f from dual', ['f' => 0.1]))->toBe('0.1');
});
test('floats bind exactly across number range', function (): void {
    $connection = $this->connect();

    foreach (['1e-130' => 1e-130, '-1.5e-7' => -1.5e-7, '1e30' => 1e30, '9.99e125' => 9.99e125, '0.30000000000000004' => 0.1 + 0.2] as $literal => $float) {
        expect(self::value(
            $connection,
            "select case when cast(:f as number) = $literal then 1 else 0 end from dual",
            ['f' => $float],
        ))->toBe(1, $literal);
    }
});
test('one sql text takes binds of different types with the statement cache on', function (): void {
    $connection = $this->connect(statementCacheSize: 20);
    $sid = self::sid($connection);

    foreach ([[1, 1], ['x', 'x'], [2, 2], ['y', 'y'], [2.5, '2.5'], [null, null], [3, 3], [Color::Green, 'green'], [Priority::High, 2], ['z', 'z']] as $i => [$value, $readBack]) {
        expect(self::value($connection, 'select :v from dual', ['v' => $value]))->toBe($readBack, "bind $i");
    }
    expect(self::sid($connection))->toBe($sid);
});
test('one sql text takes ever longer text with the statement cache on', function (): void {
    foreach (['first Session', 'second Session'] as $session) {
        $connection = $this->connect(statementCacheSize: 20, standalone: true);

        foreach ([[1, 10, 100, 2000, 4000, 4001, 16000, 32767], [10, 16000], [100, 16000], [4000, 32767], [1, 32767]] as $i => $sizes) {
            foreach ($sizes as $bytes) {
                $text = str_repeat('x', $bytes);
                foreach (['inferred' => $text, 'typed' => Varchar::in($text)] as $kind => $bind) {
                    $read = self::value($connection, "select :v from dual -- sizes $i", ['v' => $bind]);
                    expect($read)->toBeString();
                    // The length first: a failure then shows numbers, not 32 KB of text.
                    expect(\strlen($read))->toBe($bytes, "$session, sizes $i, $kind $bytes");
                    expect($read)->toBe($text);
                }
            }
        }
    }
});
/**
 * @return iterable<string, array{array<mixed>, string, string}>
 */
dataset('mistakes', function () {
    $one = 'select :v from dual';
    $date = 'use Date::in() or Timestamp::in()';
    $json = 'use Json::in()';

    yield 'DateTimeImmutable' => [['v' => new DateTimeImmutable()], $one, $date];
    yield 'DateTime' => [['v' => new DateTime()], $one, $date];
    yield 'a Stringable date, as Carbon' => [['v' => new StringableDate()], $one, $date];
    yield 'array' => [['v' => [1, 2]], $one, $json];
    yield 'JsonSerializable' => [['v' => new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return [];
        }
    }], $one, $json];
    yield 'other object' => [['v' => new stdClass()], $one, 'an object of class stdClass does not bind by itself; use Json::in() or a typed bind'];
    yield 'enum without backing value' => [['v' => Suit::Hearts], $one, 'an object of class ' . Suit::class . ' does not bind by itself'];
    yield 'resource' => [['v' => fopen('php://memory', 'r')], $one, 'use Clob::in() or Blob::in()'];
    yield 'bytes that are not UTF-8' => [['v' => "\xff\xfe"], $one, 'bind binary data with Raw::in() or Blob::in()'];
    yield 'NaN' => [['v' => \NAN], $one, 'bind it with BinaryDouble::in()'];
    yield 'INF' => [['v' => \INF], $one, 'bind it with BinaryDouble::in()'];
    yield 'float beyond NUMBER' => [['v' => 1e126], $one, 'bind it with BinaryDouble::in()'];
    yield 'float below NUMBER' => [['v' => 1e-131], $one, 'bind it with BinaryDouble::in()'];
    yield 'list' => [[1], $one, 'key 0 is an integer (is the array a list?)'];
    yield 'a string over 32767 bytes beside an extra bind' => [['v' => 1, 'long' => str_repeat('x', 40_000)], $one, "Bind 'long' matches no placeholder"];
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
});
test('a bind mistake fails before any round trip naming the fix', function (array $binds, string $sql, string $fix): void {
    $connection = $this->connect();
    $sid = self::sid($connection);
    $counter = RoundTripCounter::open();

    try {
        $roundTrips = $counter->during($sid, function () use ($connection, $sql, $binds, $fix): void {
            try {
                // @phpstan-ignore argument.type (the mistake is the point)
                $connection->execute($sql, $binds, self::options());
                self::fail('The bind mistake ran.');
            } catch (UsageException $usageException) {
                expect($usageException->getMessage())->toContain($fix)
                    ->and($usageException->sql())->toBe($sql);
            }
        });
    } finally {
        $counter->close();
    }

    expect($roundTrips)->toBe(0);
})->with('mistakes');
test('an exception from to string reaches the caller', function (): void {
    $connection = $this->connect();
    $thrown = new DomainException('from __toString');

    try {
        $connection->execute('select :v from dual', ['v' => new readonly class ($thrown) implements Stringable {
            public function __construct(private Throwable $thrown)
            {
            }

            public function __toString(): string
            {
                throw $this->thrown;
            }
        }], self::options());
        self::fail('The bind ran.');
    } catch (DomainException $domainException) {
        expect($domainException)->toBe($thrown);
    }

    expect(self::value($connection, 'select :v from dual', ['v' => 1]))->toBe(1);
});
