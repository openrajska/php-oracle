<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\Lob\LobBindTest;

use Closure;
use Override;
use Rajska\Oracle\Binds\Bindable;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Driver\TestCase;
use Stringable;
use Throwable;

uses(TestCase::class);

/** The temporary LOBs the current Session holds. */
const TEMPORARY_LOBS = <<<'SQL'
    select nvl(sum(t.cache_lobs + t.nocache_lobs + t.abstract_lobs), 0) as n
      from v$temporary_lobs t
      join v$session s on s.sid = t.sid
     where s.audsid = userenv('sessionid')
    SQL;

beforeEach(function (): void {
    schema()->run(<<<'SQL'
        create table lob_binds (
            id number(10) primary key,
            label varchar2(50) not null,
            text_value clob,
            binary_value blob
        )
        SQL);
    schema()->run("insert into lob_binds values (1, 'small', 'small', hextoraw('DEADBEEF'))");
    schema()->run("insert into lob_binds values (2, 'empty', empty_clob(), empty_blob())");
    schema()->run(<<<'SQL'
        declare
            l_text clob;
            l_binary blob;
            l_buffer varchar2(32767);
            l_hex varchar2(32767);
        begin
            insert into lob_binds values (4, 'large', empty_clob(), empty_blob())
            returning text_value, binary_value into l_text, l_binary;
            for i in 1 .. 262144 loop
                l_buffer := l_buffer || lpad(i, 8, '0') || ' ' || unistr('a\00e7\00e3o caf\00e9 \65e5\672c') || ' ' || rpad('x', 41, 'x') || chr(10);
                if mod(i, 400) = 0 or i = 262144 then
                    dbms_lob.writeappend(l_text, length(l_buffer), l_buffer);
                    l_buffer := null;
                end if;
            end loop;
            for k in 0 .. 8191 loop
                l_hex := l_hex || to_char(mod(k, 256), 'FM0X');
            end loop;
            for i in 1 .. 2048 loop
                dbms_lob.writeappend(l_binary, 8192, hextoraw(l_hex));
            end loop;
        end;
        SQL);
});

/**
 * Each class with its PL/SQL type: the IN bind and what it reads back
 * as; the IN OUT bind, how the block changes it, and the result.
 *
 * @return iterable<string, array{string, Bindable, string, Bindable, string, string}>
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

/**
 * @return iterable<string, array{string, Bindable, Bindable}>
 */
dataset('nulls', function () {
    yield 'Clob' => ['clob', new In\Clob(null), new InOut\Clob(null)];
    yield 'NClob' => ['nclob', new In\NClob(null), new InOut\NClob(null)];
    yield 'Blob' => ['blob', new In\Blob(null), new InOut\Blob(null)];
});

/**
 * @return iterable<string, array{Closure(): array{mixed, mixed, mixed}}>
 */
dataset('largeSources', function () {
    yield 'strings' => [static fn (): array => [largeText(), largeText(), largeBinary()]];
    yield 'streams' => [static fn (): array => [stream(largeText()), stream(largeText()), stream(largeBinary())]];
});

/**
 * Text whose supplemental characters put a 1 MiB chunk end at every offset
 * of a UTF-8 sequence.
 *
 * @return iterable<string, array{string, string, string}>
 */
dataset('chunkBoundaries', function () {
    foreach (['', 'a', 'ab', 'abc'] as $prefix) {
        foreach (['string', 'stream'] as $source) {
            foreach (['Clob', 'NClob'] as $class) {
                yield "prefix '$prefix', $source, $class" => [$prefix, $source, $class];
            }
        }
    }
});

/**
 * @return iterable<string, array{Closure(): Bindable, string}>
 */
dataset('notUtf8', function () {
    yield 'a string' => [static fn (): Bindable => new In\Clob("ok \xff"), "Bind 'v': the string is not valid UTF-8; bind binary data with In\\Raw or In\\Blob."];
    yield 'a stream' => [static fn (): Bindable => new In\NClob(stream(str_repeat('a', 10) . "\xff")), "Bind 'v': the stream is not valid UTF-8 at byte 10; bind binary data with In\\Blob."];
    yield 'a stream ending inside a character' => [static fn (): Bindable => new In\Clob(stream("ends inside \xe6\x97")), "Bind 'v': the stream is not valid UTF-8 at byte 12; bind binary data with In\\Blob."];
});

/**
 * @return iterable<string, array{Closure(): Bindable, non-empty-string}>
 */
dataset('wrongValues', function () {
    // @phpstan-ignore argument.type (the mistake is the point)
    yield 'an int' => [static fn (): In\Clob => new In\Clob(5), 'A LOB bind takes a string, an open stream resource or null, but was given int.'];
    // @phpstan-ignore argument.type (the mistake is the point)
    yield 'an array' => [static fn (): InOut\NClob => new InOut\NClob([]), 'A LOB bind takes a string, an open stream resource or null, but was given array.'];
    yield 'a closed stream' => [static function (): Bindable {
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

describe('lob binds', function (): void {
    it('binds each lob class in, out and in out through its plsql type', function (string $plsql, Bindable $in, string $out, Bindable $inOut, string $change, string $changed): void {
        $connection = $this->connect();

        $values = $connection->execute(
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
            ['i' => $in, 'o' => outBind($plsql), 'io' => $inOut, 'i_null' => new Out\Number()],
            $this->options(),
        )->outValues();

        expect(array_keys($values))->toBe(['o', 'io', 'i_null'])
            ->and(read($values['o'][0] ?? null))->toBe($out)
            ->and(read($values['io'][0] ?? null))->toBe($changed)
            ->and($values['i_null'])->toBe([0]);
    })->with('classes');

    it('binds null for each lob class', function (string $plsql, Bindable $in, Bindable $inOut): void {
        $connection = $this->connect();

        $values = $connection->execute(
            "declare
                   procedure p(i in $plsql, o out $plsql, io in out $plsql) is
                   begin
                     o := i;
                   end;
                 begin
                   p(:i, :o, :io);
                 end;",
            ['i' => $in, 'o' => outBind($plsql), 'io' => $inOut],
            $this->options(),
        )->outValues();

        expect($values)->toBe(['o' => [null], 'io' => [null]]);
    })->with('nulls');

    it('round trips a large in out lob', function (): void {
        $text = str_repeat("caf\u{e9} \u{65e5}\u{672c} ", 80_000);
        $binary = random_bytes((1 << 20) + 7);
        $connection = $this->connect();

        $values = $connection->execute(
            "begin :changed := :changed || 'end'; :bytes := :bytes; end;",
            ['changed' => new InOut\Clob($text), 'bytes' => new InOut\Blob(stream($binary))],
            $this->options(),
        )->outValues();

        expect(md5(read($values['changed'][0] ?? null)))->toBe(md5($text . 'end'))
            ->and(md5(read($values['bytes'][0] ?? null)))->toBe(md5($binary));
    });

    it('keeps a lob given back from a lob bind readable while it is held', function (): void {
        $connection = $this->connect();
        $out = $connection->execute('begin :o := :i; end;', ['i' => new In\Clob('kept'), 'o' => new Out\Clob()], $this->options())->outValues();
        $cursor = $connection->execute('select :b as b from dual', ['b' => new In\Blob("\x01\x02")], $this->options())->cursor();
        assert($cursor !== null);

        $connection->execute('begin :n := 1; end;', ['n' => new Out\Number()], $this->options());
        $rows = $cursor->fetchBatch();
        $this->value($connection, 'select 1 from dual');

        expect(read($out['o'][0] ?? null))->toBe('kept')
            ->and(read($rows[0]['B'] ?? null))->toBe("\x01\x02");
    });
});

describe('large lobs', function (): void {
    it('writes a 16 mib lob exactly through sql', function (Closure $values): void {
        /** @var array{resource|string, resource|string, resource|string} $lobs */
        $lobs = $values();
        [$text, , $binary] = $lobs;
        $connection = $this->connect();

        $connection->execute(
            "insert into lob_binds (id, label, text_value, binary_value) values (100, 'written', :t, :b)",
            ['t' => new In\Clob($text), 'b' => new In\Blob($binary)],
            $this->options(autocommit: false),
        );

        $compared = $this->rows($connection, <<<'SQL'
            select dbms_lob.compare(w.text_value, r.text_value) as t,
                   dbms_lob.compare(w.binary_value, r.binary_value) as b
              from lob_binds w, lob_binds r
             where w.id = 100 and r.id = 4
            SQL, [], $this->options(autocommit: false));
        $connection->rollback();
        expect($compared)->toBe([['T' => 0, 'B' => 0]]);
    })->with('largeSources');

    it('brings a 16 mib lob exactly into plsql', function (Closure $values): void {
        /** @var array{resource|string, resource|string, resource|string} $lobs */
        $lobs = $values();
        [$text, $national, $binary] = $lobs;
        $connection = $this->connect();

        $compared = $connection->execute(
            <<<'SQL'
                declare
                  l_row lob_binds%rowtype;
                begin
                  select * into l_row from lob_binds where id = 4;
                  :t := dbms_lob.compare(:text, l_row.text_value);
                  :n := dbms_lob.compare(:national, to_nclob(l_row.text_value));
                  :b := dbms_lob.compare(:binary, l_row.binary_value);
                  :t_length := dbms_lob.getlength(:text);
                end;
                SQL,
            [
                'text' => new In\Clob($text), 'national' => new In\NClob($national), 'binary' => new In\Blob($binary),
                't' => new Out\Number(), 'n' => new Out\Number(), 'b' => new Out\Number(), 't_length' => new Out\Number(),
            ],
            $this->options(),
        )->outValues();

        expect($compared)->toBe(['t' => [0], 'n' => [0], 'b' => [0], 't_length' => [16 * 1024 * 1024]]);
    })->with('largeSources');

    it('keeps a supplemental character at every chunk boundary', function (string $prefix, string $source, string $class): void {
        $characters = intdiv(1 << 20, 4) + 10;
        $text = $prefix . str_repeat("\u{1f600}", $characters);
        $value = $source === 'string' ? $text : stream($text);
        $connection = $this->connect();

        $values = $connection->execute(
            'begin :o := :i; :units := dbms_lob.getlength(:i); end;',
            [
                'i' => $class === 'Clob' ? new In\Clob($value) : new In\NClob($value),
                'o' => $class === 'Clob' ? new Out\Clob() : new Out\NClob(),
                'units' => new Out\Number(),
            ],
            $this->options(),
        )->outValues();

        expect($values['units'])->toBe([\strlen($prefix) + 2 * $characters])
            ->and(md5(read($values['o'][0] ?? null)))->toBe(md5($text));
    })->with('chunkBoundaries');
});

describe('streams', function (): void {
    it('binds a stream from its position to its end', function (): void {
        $stream = stream('skip:kept');
        fseek($stream, 5);
        $connection = $this->connect();

        $values = $connection->execute('begin :o := :i; end;', ['i' => new In\Clob($stream), 'o' => new Out\Clob()], $this->options())->outValues();

        expect(read($values['o'][0] ?? null))->toBe('kept')
            ->and(feof($stream))->toBeTrue();
    });

    it('binds an empty stream as an empty lob', function (): void {
        $connection = $this->connect();

        $values = $connection->execute('begin :length := dbms_lob.getlength(:e); end;', ['e' => new In\Blob(stream('')), 'length' => new Out\Number()], $this->options())->outValues();

        expect($values['length'])->toBe([0]);
    });
});

describe('invalid values', function (): void {
    it('refuses text that is not utf8 naming blob', function (Closure $bind, string $message): void {
        $connection = $this->connect();
        $thrown = null;

        try {
            $connection->execute('begin :v := :v; end;', ['v' => $bind()], $this->options());
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }

        expect($thrown)->toBeInstanceOf(UsageException::class)
            ->and($thrown?->getMessage())->toBe($message)
            ->and($this->value($connection, 'select 1 from dual'))->toBe(1);
    })->with('notUtf8');

    it('refuses a string that is not utf8 before any round trip', function (): void {
        $connection = $this->connect();
        $sid = $this->sid($connection);

        $trips = roundTrips($sid, function () use ($connection): void {
            try {
                $connection->execute('begin :v := :v; end;', ['v' => new In\Clob("ok \xff")], $this->options());
            } catch (UsageException) {
            }
        });

        expect($trips)->toBe(0);
    });

    it('refuses a value that is neither a string nor a stream when the bind is built', function (Closure $bind, string $message): void {
        expect($bind)->toThrow(UsageException::class, $message);
    })->with('wrongValues');
});

describe('returning into', function (): void {
    it('brings back a lob written by sql', function (): void {
        $connection = $this->connect();

        $values = $connection->execute(
            'update lob_binds set text_value = :v, binary_value = :w where id = 1 returning text_value, binary_value into :t, :b',
            ['v' => new In\Clob(str_repeat('x', 100)), 'w' => new In\Blob("\x00\xFF"), 't' => new Out\Clob(), 'b' => new Out\Blob()],
            $this->options(autocommit: false),
        )->outValues();

        expect(array_map(read(...), $values['t']))->toBe([str_repeat('x', 100)])
            ->and(array_map(read(...), $values['b']))->toBe(["\x00\xFF"]);
    });

    it('brings back the lobs written by array dml as one list per row', function (): void {
        $connection = $this->connect();
        $sql = 'update lob_binds set text_value = :v, binary_value = :w where id = :id returning text_value, binary_value into :t, :b';

        $values = $connection->executeMany($sql, [
            ['v' => new In\Clob('one'), 'w' => new In\Blob("\x01"), 'id' => 1, 't' => new Out\Clob(), 'b' => new Out\Blob()],
            ['v' => new In\Clob('two'), 'w' => new In\Blob("\x02"), 'id' => 2, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ], $this->options(autocommit: false))->outValues();

        $contents = static fn (array $lists): array => array_map(static fn (mixed $list): array => array_map(read(...), (array) $list), $lists);
        expect($contents($values['t']))->toBe([['one'], ['two']])
            ->and($contents($values['b']))->toBe([["\x01"], ["\x02"]]);
    });
});

describe('temporary lobs', function (): void {
    it('does not let temporary lobs of binds pile up on the session', function (): void {
        $connection = $this->connect(...$this->ownPool());

        for ($i = 0; $i < 200; ++$i) {
            $connection->execute(
                'begin :n := dbms_lob.getlength(:c) + dbms_lob.getlength(:b) + length(:long); end;',
                ['c' => new In\Clob("text $i"), 'b' => new In\Blob(stream("bytes $i")), 'long' => str_repeat('x', 40_000), 'n' => new Out\Number()],
                $this->options(),
            );
        }

        expect($this->value($connection, TEMPORARY_LOBS))->toBeLessThanOrEqual(3);
    });

    it('keeps temporary lobs held at release from the next owner', function (): void {
        $pool = ['maxConnections' => 1] + $this->ownPool();
        $connection = $this->connect(...$pool);
        $held = $connection->execute('begin :o := :i; end;', ['i' => new In\Clob('held'), 'o' => new Out\Clob()], $this->options())->outValues();
        $connection->close();

        $next = $this->connect(...$pool);

        expect($held['o'])->toHaveCount(1)
            ->and($this->value($next, TEMPORARY_LOBS))->toBe(0);
    });
});

function outBind(string $plsql): Bindable
{
    return match ($plsql) {
        'clob' => new Out\Clob(),
        'nclob' => new Out\NClob(),
        default => new Out\Blob(),
    };
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
 * lob_binds row 4's CLOB: 262,144 lines of 64 characters; line n is the
 * 8-digit n, a space, `ação café 日本`, a space, 41 `x` and LF (71 bytes).
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
 * lob_binds row 4's BLOB: the byte at offset k is k mod 256.
 */
function largeBinary(): string
{
    return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 65_536);
}
