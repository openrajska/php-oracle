<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\LobTest;

use Closure;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Lob\Lob;
use Rajska\Oracle\Lob\Type;
use Rajska\Oracle\Statement\LobMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\ValueMapping;
use Rajska\Oracle\Subprogram\PendingFunction;
use Rajska\Oracle\Tests\Functional\TestCase;
use RuntimeException;

uses(TestCase::class);

const SMALL = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";
const TEXT = "A\u{e7}\u{e3}o \u{65e5}\u{672c}";

/*
 * lobs: row 1 small, row 2 empty, row 3 NULL, row 4 a 16 MiB CLOB and BLOB
 * (largeText(), largeBinary()). lob_clob, lob_nclob and lob_blob return
 * TEXT (DEADBEEF for the BLOB), NULL when p_null, empty when p_empty.
 */
beforeAll(function (): void {
    schema()->run('create table lobs (id number(10) primary key, label varchar2(50) not null, text_value clob, binary_value blob)');
    schema()->run("insert into lobs values (1, 'small', unistr('Hello, LOB! A\\00e7\\00e3o \\65e5\\672c'), hextoraw('DEADBEEF'))");
    schema()->run("insert into lobs values (2, 'empty', empty_clob(), empty_blob())");
    schema()->run("insert into lobs values (3, 'null', null, null)");
    schema()->run(<<<'SQL'
        declare
            l_text clob;
            l_binary blob;
            l_buffer varchar2(32767);
            l_hex varchar2(32767);
        begin
            insert into lobs values (4, 'large', empty_clob(), empty_blob())
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
    schema()->run(<<<'SQL'
        create function lob_length(p_text clob) return number is
        begin
            return dbms_lob.getlength(p_text);
        end;
        SQL);
    schema()->run(<<<'SQL'
        create function lob_clob(p_null boolean default false, p_empty boolean default false) return clob is
        begin
            if p_null then
                return null;
            end if;
            return case when p_empty then empty_clob() else to_clob(unistr('A\00e7\00e3o \65e5\672c')) end;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create function lob_nclob(p_null boolean default false, p_empty boolean default false) return nclob is
        begin
            if p_null then
                return null;
            end if;
            return case when p_empty then to_nclob(empty_clob()) else to_nclob(unistr('A\00e7\00e3o \65e5\672c')) end;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create function lob_blob(p_null boolean default false, p_empty boolean default false) return blob is
        begin
            if p_null then
                return null;
            end if;
            return case when p_empty then empty_blob() else to_blob(hextoraw('DEADBEEF')) end;
        end;
        SQL);
});

beforeEach(function (): void {
    $this->connection = $this->connect();
});

test('a lob column reads as a lob of its type', function (): void {
    $row = $this->connection->select('select text_value, binary_value from lobs where id = 1')->first();

    assert($row !== null);
    $text = lob($row['TEXT_VALUE']);
    $binary = lob($row['BINARY_VALUE']);
    expect([$text->type(), $text->size(), $text->contents()])->toBe([Type::Clob, 19, SMALL])
        ->and([$binary->type(), $binary->size(), $binary->contents()])->toBe([Type::Blob, 4, "\xde\xad\xbe\xef"]);
});

test('contents and stream go on from where the last read stopped', function (): void {
    $lob = lob($this->connection->select('select text_value from lobs where id = 1')->value());
    $lob->contents();

    $rest = $lob->contents();

    expect($rest)->toBeEmpty()
        ->and(stream_get_contents($lob->stream()))->toBe('');
});

test('stream reads the 16 mib clob line by line exactly', function (): void {
    $stream = lob($this->connection->select('select text_value from lobs where id = 4')->value())->stream();
    $hash = hash_init('md5');
    $lines = 0;

    while (($line = fgets($stream)) !== false) {
        hash_update($hash, $line);
        ++$lines;
    }

    expect(feof($stream))->toBeTrue()
        ->and($lines)->toBe(262_144)
        ->and(hash_final($hash))->toBe(md5(largeText()));
});

test('stream reads the 16 mib blob exactly', function (): void {
    $stream = lob($this->connection->select('select binary_value from lobs where id = 4')->value())->stream();

    $contents = (string) stream_get_contents($stream);

    expect(md5($contents))->toBe(md5(largeBinary()));
});

test('a 100 mib lob streams with flat memory', function (): void {
    $lob = $this->connection->run(<<<'SQL'
        declare
          l blob;
          chunk raw(16384);
        begin
          for k in 0 .. 63 loop
            chunk := utl_raw.concat(chunk, hextoraw(
              '000102030405060708090A0B0C0D0E0F101112131415161718191A1B1C1D1E1F'
              || '202122232425262728292A2B2C2D2E2F303132333435363738393A3B3C3D3E3F'
              || '404142434445464748494A4B4C4D4E4F505152535455565758595A5B5C5D5E5F'
              || '606162636465666768696A6B6C6D6E6F707172737475767778797A7B7C7D7E7F'
              || '808182838485868788898A8B8C8D8E8F909192939495969798999A9B9C9D9E9F'
              || 'A0A1A2A3A4A5A6A7A8A9AAABACADAEAFB0B1B2B3B4B5B6B7B8B9BABBBCBDBEBF'
              || 'C0C1C2C3C4C5C6C7C8C9CACBCCCDCECFD0D1D2D3D4D5D6D7D8D9DADBDCDDDEDF'
              || 'E0E1E2E3E4E5E6E7E8E9EAEBECEDEEEFF0F1F2F3F4F5F6F7F8F9FAFBFCFDFEFF'));
          end loop;
          dbms_lob.createtemporary(l, true);
          for i in 1 .. 6400 loop
            dbms_lob.writeappend(l, 16384, chunk);
          end loop;
          :l := l;
        end;
        SQL, ['l' => new Out\Blob()])->out('l')->lob();
    assert($lob !== null);
    $expected = hash_init('md5');
    $mebibyte = largeBinary(1);
    for ($i = 0; $i < 100; ++$i) {
        hash_update($expected, $mebibyte);
    }
    unset($mebibyte);
    $stream = $lob->stream();
    $hash = hash_init('md5');
    $start = residentSetSize();
    $peak = $start;
    $bytes = 0;

    while (!feof($stream)) {
        $data = (string) fread($stream, 1024 * 1024);
        hash_update($hash, $data);
        $bytes += \strlen($data);
        $peak = max($peak, residentSetSize());
    }

    expect($bytes)->toBe(100 * 1024 * 1024)
        ->and(hash_final($hash))->toBe(hash_final($expected))
        ->and($peak - $start)->toBeLessThan(32 * 1024 * 1024, 'Resident set growth while streaming 100 MiB');
})->group('slow');

test('lob reads each lob out bind', function (): void {
    $execution = $this->connection->run(
        "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102')); :none := null; end;",
        ['c' => new Out\Clob(), 'n' => new Out\NClob(), 'b' => new Out\Blob(), 'none' => new Out\Clob()],
    );

    $national = $execution->out('n')->lob();

    assert($national !== null);
    expect($execution->out('c')->lob()?->contents())->toBe('text')
        ->and([$national->type(), $national->contents()])->toBe([Type::NClob, "\u{65e5}\u{672c}"])
        ->and($execution->out('b')->lob()?->contents())->toBe("\x01\x02")
        ->and($execution->out('none')->lob())->toBeNull();
});

test('string of a lob out value fails naming lob', function (): void {
    $execution = $this->connection->run("begin :c := to_clob('text'); end;", ['c' => new Out\Clob()]);

    $read = static fn (): string | null => $execution->out('c')->string();

    expect($read)->toThrow(UsageException::class, 'not a string. Read it with lob().');
});

test('an in clob bind writes a stream and an in blob bind a string', function (): void {
    $file = tmpfile();
    assert($file !== false);
    fwrite($file, str_repeat(TEXT . "\n", 100_000));
    rewind($file);
    $this->connection->begin();

    $this->connection->run(
        "insert into lobs (id, label, text_value, binary_value) values (100, 'written', :text, :binary)",
        ['text' => new In\Clob($file), 'binary' => new In\Blob("\x00\xff")],
    );

    $row = $this->connection->select('select text_value, binary_value from lobs where id = 100')->first();
    assert($row !== null);
    expect(lob($row['TEXT_VALUE'])->contents())->toBe(str_repeat(TEXT . "\n", 100_000))
        ->and(lob($row['BINARY_VALUE'])->contents())->toBe("\x00\xff");
});

test('an in nclob bind sends a supplemental character as two code units', function (): void {
    $function = $this->connection->function('lob_length', ['p_text' => new In\NClob(TEXT . ' 😀')]);

    $length = $function->number();

    expect($length)->toBe(10);
});

test('an in out clob bind comes back changed', function (): void {
    $execution = $this->connection->run("begin :c := :c || '!'; end;", ['c' => new InOut\Clob(TEXT)]);

    $lob = $execution->out('c')->lob();

    expect($lob?->contents())->toBe(TEXT . '!');
});

test('inline lob columns are strings', function (): void {
    $inline = new Options(values: new ValueMapping(lobs: LobMode::Inline));

    $rows = $this->connection->select('select text_value, binary_value from lobs where id <= 3 order by id', [], $inline)->all();

    expect($rows)->toBe([
        ['TEXT_VALUE' => SMALL, 'BINARY_VALUE' => "\xde\xad\xbe\xef"],
        ['TEXT_VALUE' => '', 'BINARY_VALUE' => ''],
        ['TEXT_VALUE' => null, 'BINARY_VALUE' => null],
    ]);
});

test('an inline lob out value is a string and lob fails naming string', function (): void {
    $inline = new Options(values: new ValueMapping(lobs: LobMode::Inline));

    $execution = $this->connection->run("begin :c := to_clob('text'); end;", ['c' => new Out\Clob()], $inline);

    expect($execution->out('c')->string())->toBe('text')
        ->and(static fn (): Lob | null => $execution->out('c')->lob())
        ->toThrow(UsageException::class, 'Under ValueMapping(lobs: LobMode::Inline), a LOB arrives as a string: read it with string().');
});

/**
 * Each LOB terminal, the function it calls, and what it reads.
 *
 * @return iterable<string, array{Closure(PendingFunction): mixed, string, Type, string}>
 */
dataset('lobTerminals', function () {
    yield 'clob()' => [static fn (PendingFunction $function): mixed => $function->clob(), 'lob_clob', Type::Clob, TEXT];
    yield 'nClob()' => [static fn (PendingFunction $function): mixed => $function->nClob(), 'lob_nclob', Type::NClob, TEXT];
    yield 'blob()' => [static fn (PendingFunction $function): mixed => $function->blob(), 'lob_blob', Type::Blob, "\xde\xad\xbe\xef"];
});

test('a lob terminal reads a functions lob', function (Closure $terminal, string $name, Type $type, string $contents): void {
    $value = $terminal($this->connection->function($name));

    $lob = lob($value);
    expect([$lob->type(), $lob->contents()])->toBe([$type, $contents]);
})->with('lobTerminals');

test('a lob terminal reads an empty lob and null', function (Closure $terminal, string $name): void {
    $empty = lob($terminal($this->connection->function($name, ['p_empty' => true])));

    $null = $terminal($this->connection->function($name, ['p_null' => true]));

    expect($empty->size())->toBe(0)
        ->and($null)->toBeNull();
})->with('lobTerminals');

test('a lob terminal under lob mode inline reads a string', function (Closure $terminal, string $name, Type $type, string $contents): void {
    $inline = new Options(values: new ValueMapping(lobs: LobMode::Inline));

    $value = $terminal($this->connection->function($name, [], $inline));

    expect($value)->toBe($contents)
        ->and($terminal($this->connection->function($name, ['p_empty' => true], $inline)))->toBe('')
        ->and($terminal($this->connection->function($name, ['p_null' => true], $inline)))->toBeNull();
})->with('lobTerminals');

function lob(mixed $value): Lob
{
    assert($value instanceof Lob);

    return $value;
}

/**
 * This process's resident set size, in bytes (Linux).
 */
function residentSetSize(): int
{
    $status = (string) file_get_contents('/proc/self/status');
    if (preg_match('/^VmRSS:\s+(\d+) kB$/m', $status, $match) !== 1) {
        throw new RuntimeException('No VmRSS in /proc/self/status.');
    }

    return (int) $match[1] * 1024;
}

/**
 * Row 4's CLOB: 262,144 lines of 64 characters; line n is the 8-digit n, a
 * space, `ação café 日本`, a space, 41 `x` and LF (71 bytes).
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
 * Bytes where the one at offset k is k mod 256 (row 4's BLOB is 16 MiB of
 * them).
 */
function largeBinary(int $mebibytes = 16): string
{
    return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 4096 * $mebibytes);
}
