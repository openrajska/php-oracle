<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\LobBindsTest;

use Closure;
use Override;
use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use RuntimeException;
use Stringable;

/**
 * Each class with its PL/SQL type: the IN bind and what it reads back
 * as; the IN OUT bind, how the block changes it, and the result.
 *
 * @return iterable<string, array{string, Bind, string, Bind, string, string}>
 */
dataset('classes', function () {
    $text = "h\u{e9}llo \u{65e5}\u{672c} \u{1f600}";

    yield 'Clob' => ['clob', new In\Clob($text), $text, new InOut\Clob('a'), "io || 'b'", 'ab'];
    yield 'NClob' => ['nclob', new In\NClob($text), $text, new InOut\NClob('é'), "io || n'日'", 'é日'];
    yield 'Blob' => ['blob', new In\Blob("\x00\xff\x80"), "\x00\xff\x80", new InOut\Blob("\x01"), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
    yield 'Clob from a stream' => ['clob', new In\Clob(stream($text)), $text, new InOut\Clob(stream('a')), "io || 'b'", 'ab'];
    yield 'Blob from a stream' => ['blob', new In\Blob(stream("\x00\xff\x80")), "\x00\xff\x80", new InOut\Blob(stream("\x01")), "utl_raw.concat(io, hextoraw('02'))", "\x01\x02"];
    yield 'Clob unchanged' => ['clob', new In\Clob(''), '', new InOut\Clob('kept'), 'io', 'kept'];
    yield 'Blob unchanged' => ['blob', new In\Blob(''), '', new InOut\Blob("\xff"), 'io', "\xff"];
});

test('each lob class binds in out and in out through its plsql type', function (string $plsql, Bind $in, string $out, Bind $inOut, string $change, string $changed): void {
    $outBind = match ($plsql) {
        'clob' => new Out\Clob(),
        'nclob' => new Out\NClob(),
        default => new Out\Blob(),
    };

    $values = $this->connect()->execute(
        "declare
               procedure p(i in $plsql, o out $plsql, io in out $plsql) is
               begin
                 o := i;
                 io := $change;
               end;
             begin
               p(:i, :o, :io);
               :i_null := case when :i is null then 1 else 0 end;
             end;",
        ['i' => $in, 'o' => $outBind, 'io' => $inOut, 'i_null' => new Out\Number()],
        $this->options(),
    )->outValues();

    expect(array_keys($values))->toBe(['o', 'io', 'i_null'])
        ->and(read($values['o'][0]))->toBe($out)
        ->and(read($values['io'][0]))->toBe($changed)
        ->and($values['i_null'])->toBe([0], 'Not NULL');
})->with('classes');

/**
 * @return iterable<string, array{string, Bind, Bind, Bind}>
 */
dataset('nulls', function () {
    yield 'Clob' => ['clob', new In\Clob(null), new Out\Clob(), new InOut\Clob(null)];
    yield 'NClob' => ['nclob', new In\NClob(null), new Out\NClob(), new InOut\NClob(null)];
    yield 'Blob' => ['blob', new In\Blob(null), new Out\Blob(), new InOut\Blob(null)];
});

test('each lob class binds null', function (string $plsql, Bind $in, Bind $out, Bind $inOut): void {
    $values = $this->connect()->execute(
        "declare
               procedure p(i in $plsql, o out $plsql, io in out $plsql) is
               begin
                 o := i;
               end;
             begin
               p(:i, :o, :io);
             end;",
        ['i' => $in, 'o' => $out, 'io' => $inOut],
        $this->options(),
    )->outValues();

    expect($values)->toBe(['o' => [null], 'io' => [null]]);
})->with('nulls');

test('a 16 mib string and stream go through sql and plsql exactly', function (): void {
    $connection = $this->connect();
    $text = largeText();
    $binary = largeBinary();
    $sources = [
        'string' => static fn (): array => [$text, $binary],
        'stream' => static fn (): array => [stream($text), stream($binary)],
    ];

    foreach ($sources as $source => $values) {
        [$t, $b] = $values();
        $connection->execute(
            "insert into fx_lobs (id, label, text_value, binary_value) values (100, 'written', :t, :b)",
            ['t' => new In\Clob($t), 'b' => new In\Blob($b)],
            $this->options(autocommit: false),
        );
        expect($this->rows($connection, <<<'SQL'
                    select dbms_lob.compare(w.text_value, r.text_value) as t,
                           dbms_lob.compare(w.binary_value, r.binary_value) as b,
                           dbms_lob.compare(to_nclob(w.text_value), to_nclob(r.text_value)) as n
                      from fx_lobs w, fx_lobs r
                     where w.id = 100 and r.id = 4
                    SQL, [], $this->options(autocommit: false))[0])->toBe(['T' => 0, 'B' => 0, 'N' => 0], "$source, through SQL");
        [$row] = $this->rows($connection, 'select text_value, binary_value from fx_lobs where id = 100', [], $this->options(autocommit: false));
        expect(md5(read($row['TEXT_VALUE'])))->toBe(md5($text), "$source, read back")
            ->and(md5(read($row['BINARY_VALUE'])))->toBe(md5($binary), "$source, read back");
        $connection->rollback();

        [$t, $b] = $values();
        [$n] = $values();
        $plsql = $connection->execute(
            <<<'SQL'
                    declare
                      procedure check_lobs(p_text in clob, p_national in nclob, p_binary in blob) is
                        l_row fx_lobs%rowtype;
                      begin
                        select * into l_row from fx_lobs where id = 4;
                        :t := dbms_lob.compare(p_text, l_row.text_value);
                        :n := dbms_lob.compare(p_national, to_nclob(l_row.text_value));
                        :b := dbms_lob.compare(p_binary, l_row.binary_value);
                        :t_length := fx_lob.text_length(p_text);
                      end;
                    begin
                      check_lobs(:text, :national, :binary);
                    end;
                    SQL,
            [
                'text' => new In\Clob($t), 'national' => new In\NClob($n), 'binary' => new In\Blob($b),
                't' => new Out\Number(), 'n' => new Out\Number(), 'b' => new Out\Number(), 't_length' => new Out\Number(),
            ],
            $this->options(),
        )->outValues();
        expect($plsql)->toBe(['t' => [0], 'n' => [0], 'b' => [0], 't_length' => [16 * 1024 * 1024]], "$source, into PL/SQL");
    }
});

test('a supplemental character survives at every chunk boundary', function (): void {
    $grin = "\u{1f600}";
    $chunk = 1 << 20;

    $connection = $this->connect();
    foreach (['', 'a', 'ab', 'abc'] as $prefix) {
        $text = $prefix . str_repeat($grin, intdiv($chunk, 4) + 10);
        foreach (['string' => $text, 'stream' => stream($text)] as $source => $value) {
            foreach (['Clob' => new In\Clob($value), 'NClob' => new In\NClob(\is_string($value) ? $value : stream($text))] as $class => $bind) {
                $out = $connection->execute(
                    'begin :o := :i; :units := dbms_lob.getlength(:i); end;',
                    ['i' => $bind, 'o' => $class === 'Clob' ? new Out\Clob() : new Out\NClob(), 'units' => new Out\Number()],
                    $this->options(),
                )->outValues();
                $what = "prefix '$prefix', $source, $class";
                expect($out['units'])->toBe([\strlen($prefix) + 2 * (intdiv($chunk, 4) + 10)], $what)
                    ->and(md5(read($out['o'][0])))->toBe(md5($text), $what);
            }
        }
    }
});

test('a large in out lob round trips', function (): void {
    $chunk = 1 << 20;

    $connection = $this->connect();
    $text = str_repeat("caf\u{e9} \u{65e5}\u{672c} ", 80_000);
    $binary = random_bytes($chunk + 7);

    $values = $connection->execute(
        "begin :changed := :changed || 'end'; :bytes := :bytes; end;",
        ['changed' => new InOut\Clob($text), 'bytes' => new InOut\Blob(stream($binary))],
        $this->options(),
    )->outValues();

    expect(md5(read($values['changed'][0])))->toBe(md5($text . 'end'))
        ->and(md5(read($values['bytes'][0])))->toBe(md5($binary));
});

test('a lob given back from a lob bind stays readable while held', function (): void {
    $connection = $this->connect();
    $out = $connection->execute('begin :o := :i; end;', ['i' => new In\Clob('kept'), 'o' => new Out\Clob()], $this->options())->outValues();
    $cursor = $connection->execute('select :b as b from dual', ['b' => new In\Blob("\x01\x02")], $this->options())->cursor();
    assert($cursor !== null);
    $connection->execute('begin :n := 1; end;', ['n' => new Out\Number()], $this->options());

    $rows = $cursor->fetchBatch();
    $this->value($connection, 'select 1 from dual');

    expect(read($out['o'][0]))->toBe('kept')
        ->and(read($rows[0]['B'] ?? null))->toBe("\x01\x02");
});

test('a stream is read from its position to its end', function (): void {
    $stream = stream('skip:kept');
    fseek($stream, 5);

    $values = $this->connect()->execute(
        'begin :o := :i; :empty := dbms_lob.getlength(:e); end;',
        ['i' => new In\Clob($stream), 'o' => new Out\Clob(), 'e' => new In\Blob(stream('')), 'empty' => new Out\Number()],
        $this->options(),
    )->outValues();

    expect(read($values['o'][0]))->toBe('kept')
        ->and($values['empty'])->toBe([0])
        ->and(feof($stream))->toBeTrue();
});

test('text that is not utf8 fails naming blob', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $counter = RoundTripCounter::open();
    try {
        $trips = $counter->during($sid, function () use ($connection): void {
            assertUsage($connection, ['v' => new In\Clob("ok \xff")], "Bind 'v': the string is not valid UTF-8; bind binary data with In\Raw or In\Blob.");
        });
        expect($trips)->toBe(0, 'Fails before any Round trip');
    } finally {
        $counter->close();
    }

    assertUsage($connection, ['v' => new In\NClob(stream(str_repeat('a', 10) . "\xff"))], "Bind 'v': the stream is not valid UTF-8 at byte 10; bind binary data with In\Blob.");
    assertUsage($connection, ['v' => new In\Clob(stream("ends inside \xe6\x97"))], "Bind 'v': the stream is not valid UTF-8 at byte 12; bind binary data with In\Blob.");
    expect($this->value($connection, 'select 1 from dual'))->toBe(1, 'The Connection still works');
});

/**
 * @return iterable<string, array{Closure(): Bind, non-empty-string}>
 */
dataset('wrongValues', function () {
    // @phpstan-ignore argument.type (the mistake is the point)
    yield 'an int' => [static fn (): In\Clob => new In\Clob(5), 'A LOB bind takes a string, an open stream resource or null, but was given int.'];
    // @phpstan-ignore argument.type (the mistake is the point)
    yield 'an array' => [static fn (): InOut\NClob => new InOut\NClob([]), 'A LOB bind takes a string, an open stream resource or null, but was given array.'];
    yield 'a closed stream' => [static function (): Bind {
        $stream = stream('x');
        fclose($stream);

        return new In\Blob($stream);
    }, 'A LOB bind takes a string, an open stream resource or null, but was given resource (closed).'];
    yield 'a Stringable' => [static fn (): In\Clob => new In\Clob(new class implements Stringable { // @phpstan-ignore argument.type (the mistake is the point)
        #[Override]
        public function __toString(): string
        {
            return 'x';
        }
    }), 'A LOB bind takes a string, an open stream resource or null, but was given Stringable@anonymous.'];
});

test('a value that is not a string or a stream fails when the bind is built', function (Closure $bind, string $message): void {
    expect(function () use ($bind): void {
        $bind();
        $this->fail('The value was accepted.');
    })->toThrow(UsageException::class, $message);
})->with('wrongValues');

test('a lob written by sql comes back from returning', function (): void {
    $connection = $this->connect();
    $options = $this->options(autocommit: false);
    $sql = 'update fx_lobs set text_value = :v, binary_value = :w where id = :id returning text_value, binary_value into :t, :b';

    $one = $connection->execute(
        $sql,
        ['v' => new In\Clob(str_repeat('x', 100)), 'w' => new In\Blob("\x00\xFF"), 'id' => 1, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        $options,
    )->outValues();
    expect(array_map(read(...), $one['t']))->toBe([str_repeat('x', 100)])
        ->and(array_map(read(...), $one['b']))->toBe(["\x00\xFF"]);

    $many = $connection->executeMany($sql, [
        ['v' => new In\Clob('one'), 'w' => new In\Blob("\x01"), 'id' => 1, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['v' => new In\Clob('two'), 'w' => new In\Blob("\x02"), 'id' => 2, 't' => new Out\Clob(), 'b' => new Out\Blob()],
    ], $options)->outValues();
    expect(array_map(static fn (array $list): array => array_map(read(...), $list), $many['t']))->toBe([['one'], ['two']])
        ->and(array_map(static fn (array $list): array => array_map(read(...), $list), $many['b']))->toBe([["\x01"], ["\x02"]]);

    $connection->rollback();
});

test('temporary lobs of binds do not pile up or reach the next owner', function (): void {
    $pool = ['maxConnections' => 1] + $this->ownPool();
    $count = 'select nvl(sum(t.cache_lobs + t.nocache_lobs + t.abstract_lobs), 0) as n from v$temporary_lobs t join v$session s on s.sid = t.sid where s.audsid = userenv(\'sessionid\')';
    $connection = $this->connect(...$pool);
    for ($i = 0; $i < 200; ++$i) {
        $connection->execute(
            'begin :n := dbms_lob.getlength(:c) + dbms_lob.getlength(:b) + length(:long); end;',
            ['c' => new In\Clob("text $i"), 'b' => new In\Blob(stream("bytes $i")), 'long' => str_repeat('x', 40_000), 'n' => new Out\Number()],
            $this->options(),
        );
    }
    expect($this->value($connection, $count))->toBeLessThanOrEqual(3, 'Temporary LOBs left on the Session');
    $held = $connection->execute('begin :o := :i; end;', ['i' => new In\Clob('held'), 'o' => new Out\Clob()], $this->options())->outValues();
    $connection->close();
    expect($held['o'])->toHaveCount(1);

    $next = $this->connect(...$pool);
    expect($this->value($next, $count))->toBe(0, 'Temporary LOBs the next owner finds');
});

/**
 * @param array<string, mixed> $binds
 */
function assertUsage(Session $connection, array $binds, string $message): void
{
    try {
        $connection->execute('begin :v := :v; end;', $binds, test()->options());
        throw new RuntimeException('The bind was accepted.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toBe($message);
    }
}

/**
 * @return resource
 */
function stream(string $contents)
{
    $stream = fopen('php://memory', 'w+b');
    assert(\is_resource($stream));
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function read(mixed $lob): string
{
    assert($lob instanceof Locator);
    $contents = '';
    while (($chunk = $lob->readChunk()) !== '') {
        $contents .= $chunk;
    }

    return $contents;
}

/**
 * fx_lobs row 4's CLOB: 262,144 lines of 64 characters (README).
 */
function largeText(): string
{
    $tail = " a\u{e7}\u{e3}o caf\u{e9} \u{65e5}\u{672c} " . str_repeat('x', 41) . "\n";
    $text = '';
    for ($line = 1; $line <= 262_144; ++$line) {
        $text .= \sprintf('%08d', $line) . $tail;
    }

    return $text;
}

/**
 * fx_lobs row 4's BLOB: the byte at offset k is k mod 256.
 */
function largeBinary(): string
{
    return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 65_536);
}
