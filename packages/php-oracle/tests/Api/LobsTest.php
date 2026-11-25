<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\LobsTest;

use Rajska\Oracle\Bind\Blob;
use Rajska\Oracle\Bind\Clob;
use Rajska\Oracle\Bind\NClob;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Lob;
use Rajska\Oracle\LobMode;
use Rajska\Oracle\LobType;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\ValueMapping;
use RuntimeException;

beforeEach(function (): void {
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});

afterEach(function (): void {
    $this->connection->close();
});

test('a lob column reads its contents', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $row = $this->connection->select('select id, text_value, binary_value from fx_lobs where id = 1')->first();
    assert($row !== null);
    expect($row['ID'])->toBe(1);


    $text = lob($row['TEXT_VALUE']);
    expect($text->type())->toBe(LobType::Clob)
        ->and($text->size())->toBe(19)
        ->and($text->contents())->toBe($small);

    $binary = lob($row['BINARY_VALUE']);
    expect($binary->type())->toBe(LobType::Blob)
        ->and($binary->contents())->toBe("\xde\xad\xbe\xef");
});

test('contents and stream go on from where the last read stopped', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $lob = lob($this->connection->select('select text_value from fx_lobs where id = 1')->value());

    expect($lob->contents())->toBe($small)
        ->and($lob->contents())->toBeEmpty()
        ->and(stream_get_contents($lob->stream()))->toBe('');
});

test('stream reads the 16 mib clob and blob exactly', function (): void {
    $row = $this->connection->select('select text_value, binary_value from fx_lobs where id = 4')->first();
    assert($row !== null);

    $text = lob($row['TEXT_VALUE'])->stream();
    $hash = hash_init('md5');
    $lines = 0;
    while (($line = fgets($text)) !== false) {
        hash_update($hash, $line);
        ++$lines;
    }
    expect(feof($text))->toBeTrue();
    fclose($text);
    expect($lines)->toBe(262_144)
        ->and(hash_final($hash))->toBe(md5(largeText()));

    $binary = lob($row['BINARY_VALUE'])->stream();
    expect(md5((string) stream_get_contents($binary)))->toBe(md5(largeBinary()));
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
        SQL, ['l' => Blob::out()])->out('l')->lob();
    assert($lob !== null);
    expect($lob->size())->toBe(100 * 1024 * 1024);

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
    fclose($stream);

    expect($bytes)->toBe(100 * 1024 * 1024)
        ->and(hash_final($hash))->toBe(hash_final($expected))
        ->and($peak - $start)->toBeLessThan(32 * 1024 * 1024, 'Resident set growth while streaming 100 MiB');
})->group('slow');

test('an empty lob is a lob of size 0 and null is null', function (): void {
    $rows = $this->connection->select('select text_value, binary_value from fx_lobs where id in (2, 3) order by id')->all();

    foreach (['TEXT_VALUE', 'BINARY_VALUE'] as $column) {
        $lob = lob($rows[0][$column]);
        expect($lob->size())->toBe(0)
            ->and($lob->contents())->toBeEmpty()
            ->and(stream_get_contents(lob($rows[0][$column])->stream()))->toBe('')
            ->and($rows[1][$column])->toBeNull();
    }
});

test('after close a lob and its stream fail', function (): void {
    $row = $this->connection->select('select text_value, binary_value from fx_lobs where id = 4')->first();
    assert($row !== null);
    $lob = lob($row['TEXT_VALUE']);
    $stream = lob($row['BINARY_VALUE'])->stream();
    $readBytes = \strlen((string) fread($stream, 8192));
    expect($readBytes)->toBe(8192);
    $this->connection->close();

    foreach ([
        'type()' => $lob->type(...),
        'size()' => $lob->size(...),
        'contents()' => $lob->contents(...),
        'stream()' => $lob->stream(...),
        'reading the stream' => static fn(): false | string => stream_get_contents($stream),
    ] as $call => $read) {
        try {
            $read();
            $this->fail("$call after close() worked.");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Connection is closed.', $call);
        }
    }
});

test('out value lob reads a lob out bind', function (): void {
    $execution = $this->connection->run(
        "begin :c := to_clob('text'); :n := to_nclob(unistr('\\65e5\\672c')); :b := to_blob(hextoraw('0102')); :none := null; end;",
        ['c' => Clob::out(), 'n' => NClob::out(), 'b' => Blob::out(), 'none' => Clob::out()],
    );

    expect($execution->out('c')->lob()?->contents())->toBe('text');
    $national = $execution->out('n')->lob();
    assert($national !== null);
    expect([$national->type(), $national->contents()])->toBe([LobType::NClob, "\u{65e5}\u{672c}"])
        ->and($execution->out('b')->lob()?->contents())->toBe("\x01\x02")
        ->and($execution->out('none')->lob())->toBeNull();

    try {
        $execution->out('c')->string();
        $this->fail('string() read a Lob.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toEndWith('not a string. Read it with lob().');
    }
});

test('lob binds write strings and streams', function (): void {
    $text = "A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $file = tmpfile();
    expect($file)->toBeResource();
    fwrite($file, str_repeat($text . "\n", 100_000));
    rewind($file);
    $this->connection->begin();

    $this->connection->run(
        "insert into fx_lobs (id, label, text_value, binary_value) values (100, 'written', :text, :binary)",
        ['text' => Clob::in($file), 'binary' => Blob::in("\x00\xff")],
    );
    $row = $this->connection->select('select text_value, binary_value from fx_lobs where id = 100')->first();
    assert($row !== null);
    expect(lob($row['TEXT_VALUE'])->contents())->toBe(str_repeat($text . "\n", 100_000))
        ->and(lob($row['BINARY_VALUE'])->contents())->toBe("\x00\xff");

    $this->connection->rollback();

    expect($this->connection->function('fx_lob.text_length', ['p_text' => NClob::in($text . ' 😀')])->number())->toBe(10)
        ->and($this->connection->run("begin :c := :c || '!'; end;", ['c' => Clob::inOut($text)])->out('c')->lob()?->contents())->toBe($text . '!');
});

test('a string over 32767 bytes binds as a clob', function (): void {
    $long = str_repeat('ç', 20_000);

    expect($this->connection->function('fx_lob.text_length', ['p_text' => $long])->number())->toBe(20_000)
        ->and($this->connection->run('begin :out := :v; end;', ['v' => $long, 'out' => Clob::out()])->out('out')->lob()?->contents())->toBe($long);
});

test('inline lobs are strings', function (): void {
    $small = "Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $inline = new ExecuteOptions(values: new ValueMapping(lobs: LobMode::Inline));

    expect($this->connection->select('select text_value, binary_value from fx_lobs where id <= 3 order by id', [], $inline)->all())->toBe([
        ['TEXT_VALUE' => $small, 'BINARY_VALUE' => "\xde\xad\xbe\xef"],
        ['TEXT_VALUE' => '', 'BINARY_VALUE' => ''],
        ['TEXT_VALUE' => null, 'BINARY_VALUE' => null],
    ]);

    $execution = $this->connection->run("begin :c := to_clob('text'); end;", ['c' => Clob::out()], $inline);
    expect($execution->out('c')->string())->toBe('text');
    try {
        $execution->out('c')->lob();
        $this->fail('lob() read a string.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toEndWith('Under ValueMapping(lobs: LobMode::Inline), a LOB arrives as a string: read it with string().');
    }
});

test('the lob terminals of a function call', function (): void {
    $text = "A\u{e7}\u{e3}o \u{65e5}\u{672c}";

    $clob = $this->connection->function('fx_call.f_clob')->clob();
    assert($clob instanceof Lob);
    expect($clob->contents())->toBe($text);

    $nclob = $this->connection->function('fx_call.f_nclob')->nclob();
    assert($nclob instanceof Lob);
    expect([$nclob->type(), $nclob->contents()])->toBe([LobType::NClob, $text]);

    $blob = $this->connection->function('fx_call.f_blob')->blob();
    assert($blob instanceof Lob);
    expect($blob->contents())->toBe("\xde\xad\xbe\xef")
        ->and($this->connection->function('fx_call.f_clob', ['p_null' => true])->clob())->toBeNull();

    $empty = $this->connection->function('fx_call.f_blob', ['p_empty' => true])->blob();
    assert($empty instanceof Lob);
    expect($empty->size())->toBe(0);

    $inline = new ExecuteOptions(values: new ValueMapping(lobs: LobMode::Inline));
    expect($this->connection->function('fx_call.f_clob', [], $inline)->clob())->toBe($text)
        ->and($this->connection->function('fx_call.f_nclob', [], $inline)->nclob())->toBe($text)
        ->and($this->connection->function('fx_call.f_blob', [], $inline)->blob())->toBe("\xde\xad\xbe\xef")
        ->and($this->connection->function('fx_call.f_nclob', ['p_empty' => true], $inline)->nclob())->toBe('')
        ->and($this->connection->function('fx_call.f_blob', ['p_null' => true], $inline)->blob())->toBeNull();
});

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
 * Bytes where the one at offset k is k mod 256 (fx_lobs row 4's BLOB is
 * 16 MiB of them).
 */
function largeBinary(int $mebibytes = 16): string
{
    return str_repeat(implode('', array_map(\chr(...), range(0, 255))), 4096 * $mebibytes);
}
