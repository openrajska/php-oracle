<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Binds\BindTest;

use DateTime;
use DateTimeImmutable;
use DomainException;
use JsonSerializable;
use Override;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Driver\TestCase;
use stdClass;
use Stringable;
use Throwable;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table bind_lobs (id number(10) primary key, text_value clob)');

    // Not TRUNCATE: re-running the cached LOB query of a pooled Session
    // after it fails with ORA-01002, a bug of ours still open.
    schema()->run('delete from bind_lobs');
});

/**
 * What `dump()` reports for a bind: its Oracle type code first
 * (1 = VARCHAR2, 2 = NUMBER), then its bytes; and what it reads back as.
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
        #[Override]
        public function __toString(): string
        {
            return 'from __toString';
        }
    }, 'Typ=1 Len=15: 102,114,111,109,32,95,95,116,111,83,116,114,105,110,103', 'from __toString'];
    yield 'null' => [null, 'NULL', null];
});

test('a plain value binds as its inferred type', function (mixed $value, string $dump, mixed $readBack): void {
    $connection = $this->connect();

    $dumped = $this->value($connection, 'select dump(:v) from dual', ['v' => $value]);
    $read = $this->value($connection, 'select :v from dual', ['v' => $value]);

    expect($dumped)->toBe($dump)
        ->and($read)->toBe($readBack);
})->with('inferred');

test('a bool binds as boolean', function (bool $value, string $dump, string $truth): void {
    $connection = $this->connect();

    $dumped = $this->value($connection, 'select dump(:v) from dual', ['v' => $value]);
    $tested = $this->value($connection, "select case when :v then 'true' else 'false' end from dual", ['v' => $value]);

    expect($dumped)->toBe($dump)
        ->and($tested)->toBe($truth);
})->with([
    'true' => [true, 'Typ=252 Len=4: 1,0,0,0', 'true'],
    'false' => [false, 'Typ=252 Len=4: 0,0,0,0', 'false'],
]);

test('a string binds as varchar2 up to 32767 bytes and as a clob beyond', function (int $bytes, string $type): void {
    $connection = $this->connect();
    // 4-byte characters, so a cut inside one would show.
    $value = str_repeat('x', $bytes % 4) . str_repeat("\u{1f600}", intdiv($bytes, 4));

    $bound = $connection->execute(
        "declare
               function type_of(v varchar2) return varchar2 is begin return 'VARCHAR2'; end;
               function type_of(v clob) return varchar2 is begin return 'CLOB'; end;
             begin
               :type := type_of(:v);
               insert into bind_lobs (id, text_value) values (1, :v);
             end;",
        ['v' => $value, 'type' => new Out\Varchar()],
        $this->options(),
    )->outValues();
    $stored = readLob($this->rows($connection, 'select text_value from bind_lobs')[0]['TEXT_VALUE']);

    expect($bound)->toBe(['type' => [$type]])
        ->and($stored)->toBe($value);
})->with([
    '4000 bytes' => [4000, 'VARCHAR2'],
    '4001 bytes' => [4001, 'VARCHAR2'],
    '32767 bytes' => [32767, 'VARCHAR2'],
    '32768 bytes' => [32768, 'CLOB'],
    '1 MiB' => [1 << 20, 'CLOB'],
]);

test('a blocks binds match its placeholders in any order and case', function (): void {
    $connection = $this->connect();

    $execution = $connection->execute(
        'begin :Total := :a + :b; :Echo := to_char(:b) || :a; end;',
        ['B' => 2, 'echo' => new Out\Varchar(), 'TOTAL' => new Out\Number(), 'a' => 40],
        $this->options(),
    );

    expect($execution->outValues())->toBe(['echo' => ['240'], 'TOTAL' => [42]]);
});

test('a float binds as the exact number its shortest text shows', function (float $float, string $literal): void {
    $connection = $this->connect();

    $equal = $this->value($connection, "select case when cast(:f as number) = $literal then 1 else 0 end from dual", ['f' => $float]);

    expect($equal)->toBe(1);
})->with([
    // As BINARY_DOUBLE, 0.1 would become 0.1000000000000000055511151231257827.
    '0.1' => [0.1, '0.1'],
    '1e-130' => [1e-130, '1e-130'],
    '-1.5e-7' => [-1.5e-7, '-1.5e-7'],
    '1e30' => [1e30, '1e30'],
    '9.99e125' => [9.99e125, '9.99e125'],
    '0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'],
]);

test('one sql text takes binds of different types with the statement cache on', function (): void {
    $connection = $this->connect(statementCacheSize: 20);
    $sid = $this->sid($connection);
    $binds = [[1, 1], ['x', 'x'], [2, 2], ['y', 'y'], [2.5, '2.5'], [null, null], [3, 3], [Color::Green, 'green'], [Priority::High, 2], ['z', 'z']];

    $read = array_map(fn (array $bind): mixed => $this->value($connection, 'select :v from dual', ['v' => $bind[0]]), $binds);

    expect($read)->toBe(array_column($binds, 1))
        ->and($this->sid($connection))->toBe($sid);
});

test('one sql text takes ever longer text with the statement cache on', function (string $kind): void {
    $sequences = [[1, 10, 100, 2000, 4000, 4001, 16000, 32767], [10, 16000], [100, 16000], [4000, 32767], [1, 32767]];
    $expected = [];
    $lengths = [];

    foreach (['first Session', 'second Session'] as $session) {
        $connection = $this->connect(statementCacheSize: 20, standalone: true);
        foreach ($sequences as $i => $sizes) {
            foreach ($sizes as $bytes) {
                $text = str_repeat('x', $bytes);
                $read = $this->value($connection, "select :v from dual -- sizes $i", ['v' => $kind === 'typed' ? new In\Varchar($text) : $text]);
                $expected[] = "$session, sizes $i: $bytes";
                $lengths[] = "$session, sizes $i: " . (\is_string($read) && $read === $text ? \strlen($read) : 'wrong text');
            }
        }
    }

    expect($lengths)->toBe($expected);
})->with(['inferred', 'typed']);

/**
 * @return iterable<string, array{array<mixed>, string, string}>
 */
dataset('mistakes', function () {
    $one = 'select :v from dual';
    $date = 'use In\Date or In\Timestamp';
    $json = 'use In\Json';

    yield 'DateTimeImmutable' => [['v' => new DateTimeImmutable()], $one, $date];
    yield 'DateTime' => [['v' => new DateTime()], $one, $date];
    yield 'a Stringable date, as Carbon' => [['v' => new StringableDate()], $one, $date];
    yield 'array' => [['v' => [1, 2]], $one, $json];
    yield 'JsonSerializable' => [['v' => new class implements JsonSerializable {
        #[Override]
        public function jsonSerialize(): mixed
        {
            return [];
        }
    }], $one, $json];
    yield 'other object' => [['v' => new stdClass()], $one, 'an object of class stdClass does not bind by itself; use In\Json or a typed bind'];
    yield 'enum without backing value' => [['v' => Suit::Hearts], $one, 'an object of class ' . Suit::class . ' does not bind by itself'];
    yield 'resource' => [['v' => fopen('php://memory', 'r')], $one, 'use In\Clob or In\Blob'];
    yield 'bytes that are not UTF-8' => [['v' => "\xff\xfe"], $one, 'bind binary data with In\Raw or In\Blob'];
    yield 'NaN' => [['v' => \NAN], $one, 'bind it with In\BinaryDouble'];
    yield 'INF' => [['v' => \INF], $one, 'bind it with In\BinaryDouble'];
    yield 'float beyond NUMBER' => [['v' => 1e126], $one, 'bind it with In\BinaryDouble'];
    yield 'float below NUMBER' => [['v' => 1e-131], $one, 'bind it with In\BinaryDouble'];
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
    $sid = $this->sid($connection);
    $thrown = null;

    $trips = roundTrips($sid, function () use ($connection, $sql, $binds, &$thrown): void {
        try {
            // @phpstan-ignore argument.type (the mistake is the point)
            $connection->execute($sql, $binds, $this->options());
        } catch (UsageException $usageException) {
            $thrown = $usageException;
        }
    });

    assert($thrown instanceof UsageException);
    expect($thrown->getMessage())->toContain($fix)
        ->and($thrown->sql())->toBe($sql)
        ->and($trips)->toBe(0);
})->with('mistakes');

test('an exception from to string reaches the caller', function (): void {
    $connection = $this->connect();
    $thrown = new DomainException('from __toString');
    $value = new readonly class ($thrown) implements Stringable {
        public function __construct(private Throwable $thrown)
        {
        }

        #[Override]
        public function __toString(): string
        {
            throw $this->thrown;
        }
    };
    $caught = null;

    try {
        $connection->execute('select :v from dual', ['v' => $value], $this->options());
    } catch (DomainException $domainException) {
        $caught = $domainException;
    }

    expect($caught)->toBe($thrown)
        ->and($this->value($connection, 'select :v from dual', ['v' => 1]))->toBe(1);
});

function readLob(mixed $lob): string
{
    assert($lob instanceof Locator);
    $read = '';
    while (($chunk = $lob->readChunk()) !== '') {
        $read .= $chunk;
    }

    return $read;
}

enum Color: string
{
    case Red = 'red';
    case Green = 'green';
}

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

/**
 * An enum with no backing value: nothing to bind it as.
 */
enum Suit
{
    case Hearts;
    case Spades;
}

/**
 * A date that is also `Stringable`, as Carbon is: it must fail as a
 * DateTimeInterface, never bind as its text.
 */
final class StringableDate extends DateTimeImmutable implements Stringable
{
    #[Override]
    public function __toString(): string
    {
        return $this->format('Y-m-d H:i:s');
    }
}
