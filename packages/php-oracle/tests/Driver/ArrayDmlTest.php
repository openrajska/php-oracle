<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\ArrayDmlTest;

use Closure;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Contracts\Driver\ArrayDml;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\ArrayDmlFailedException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Driver\TestCase;
use RuntimeException;

uses(TestCase::class);

const INSERT = 'insert into batch_rows (id, code, qty) values (:id, :code, :qty)';

beforeEach(function (): void {
    schema()->run('create type dml_point as object (x number, y number, label varchar2(30))');
    schema()->run('create type dml_polygon as object (name varchar2(50))');
    schema()->run('create type dml_tags as varray(10) of varchar2(100)');
    schema()->run('create type dml_numbers as table of number');
    schema()->run('create table dml_shapes (id number(10) primary key, center dml_point, tags dml_tags)');
    schema()->run("insert into dml_shapes values (1, dml_point(1.5, -2, 'center'), dml_tags('red', unistr('caf\\00e9')))");
    schema()->run('insert into dml_shapes values (2, null, null)');
    schema()->run('insert into dml_shapes values (3, dml_point(null, null, null), dml_tags())');
    schema()->run('create table dml_lobs (id number(10) primary key, label varchar2(50) not null, text_value clob, binary_value blob)');
    schema()->run("insert into dml_lobs values (1, 'small', unistr('Hello, LOB! A\\00e7\\00e3o \\65e5\\672c'), hextoraw('DEADBEEF'))");
    schema()->run("insert into dml_lobs values (2, 'empty', empty_clob(), empty_blob())");
    schema()->run("insert into dml_lobs values (3, 'null', null, null)");
    schema()->run('create global temporary table dml_bind_rows (id number(10)) on commit delete rows');
    schema()->run(<<<'SQL'
        create function dml_text_length(p_text clob) return number is
        begin
            return dbms_lob.getlength(p_text);
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package dml_object as
            type t_number_map is table of number index by pls_integer;
            procedure move_point(io_point in out dml_point, p_dx number, p_dy number);
            function sum_numbers(p_numbers dml_numbers) return number;
            function sum_map(p_map t_number_map) return number;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create package body dml_object as
            procedure move_point(io_point in out dml_point, p_dx number, p_dy number) is
            begin
                io_point.x := io_point.x + p_dx;
                io_point.y := io_point.y + p_dy;
            end;

            function sum_numbers(p_numbers dml_numbers) return number is
                l_sum number := 0;
            begin
                for i in 1 .. p_numbers.count loop
                    l_sum := l_sum + p_numbers(i);
                end loop;
                return l_sum;
            end;

            function sum_map(p_map t_number_map) return number is
                l_sum number := 0;
                l_key pls_integer := p_map.first;
            begin
                while l_key is not null loop
                    l_sum := l_sum + p_map(l_key);
                    l_key := p_map.next(l_key);
                end loop;
                return l_sum;
            end;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create table batch_rows (
            id number(10) primary key,
            code varchar2(10) not null unique,
            qty number(10) not null check (qty >= 0)
        )
        SQL);
    schema()->run('create table tx_rows (id number(10) primary key, qty number(10), note varchar2(100))');
});

test('a thousand valid rows take one round trip and commit under autocommit', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $arrayDml = null;

    $trips = roundTrips($sid, function () use ($connection, &$arrayDml): void {
        $arrayDml = many($connection, INSERT, valid(1000));
    });

    assert($arrayDml instanceof ArrayDml);
    expect($trips)->toBe(1)
        ->and($arrayDml->rowCount())->toBe(1000)
        ->and($arrayDml->outValues())->toBeEmpty()
        ->and(committed(otherSession()))->toBe(1000);
});

test('rows bind by name whatever the order and case of their keys', function (): void {
    $connection = $this->connect();

    many($connection, INSERT, [
        ['id' => 1, 'code' => 'one', 'qty' => 10],
        ['QTY' => 20, 'Id' => 2, 'code' => 'two'],
    ]);

    expect(otherSession()->select('select id, code, qty from batch_rows order by id')->all())
        ->toBe([['ID' => 1, 'CODE' => 'one', 'QTY' => 10], ['ID' => 2, 'CODE' => 'two', 'QTY' => 20]]);
});

test('failing rows under autocommit give one array dml exception and apply nothing', function (): void {
    $connection = $this->connect();

    $thrown = failing(static fn (): ArrayDml => many($connection, INSERT, withBadRows()));

    expect(failures($thrown))->toBe([[3, 1], [5, 1400], [7, 2290]])
        ->and($thrown->getCode())->toBe(1)
        ->and($thrown->getMessage())->toStartWith('3 of 10 rows failed; first: row 3: ORA-00001: ')
        ->and($thrown->sql())->toBe(INSERT)
        ->and($this->value($connection, 'select count(*) from batch_rows'))->toBe(0)
        ->and(committed(otherSession()))->toBe(0);
});

test('failing rows inside a transaction keep the earlier work uncommitted', function (): void {
    $connection = $this->connect();
    $connection->execute('insert into tx_rows (id) values (1)', [], $this->options(autocommit: false));

    $thrown = failing(static fn (): ArrayDml => many($connection, INSERT, withBadRows(), autocommit: false));

    expect(failures($thrown))->toBe([[3, 1], [5, 1400], [7, 2290]])
        ->and($this->value($connection, 'select count(*) from batch_rows'))->toBe(0)
        ->and($this->value($connection, 'select count(*) from tx_rows'))->toBe(1)
        ->and(otherSession()->select('select count(*) from tx_rows')->value())->toBe(0);
});

test('only duplicates still give an array dml exception', function (): void {
    $connection = $this->connect();
    $rows = valid(4);
    $rows[1]['id'] = 1;
    $rows[3]['code'] = 'c1';

    $thrown = failing(static fn (): ArrayDml => many($connection, INSERT, $rows));

    expect($thrown::class)->toBe(ArrayDmlFailedException::class)
        ->and(failures($thrown))->toBe([[1, 1], [3, 1]])
        ->and(committed(otherSession()))->toBe(0);
});

test('a block failing at a row leaves nothing applied', function (bool $autocommit): void {
    $connection = $this->connect();
    $thrown = null;

    try {
        many($connection, 'begin insert into tx_rows (id) values (:id); end;', [['id' => 1], ['id' => 2], ['id' => 1], ['id' => 3]], $autocommit);
    } catch (UniqueViolationException $uniqueViolationException) {
        $thrown = $uniqueViolationException;
    }

    expect($thrown?->getMessage())->toStartWith('ORA-00001: ')
        ->and($this->value($connection, 'select count(*) from tx_rows'))->toBe(0);
})->with(['under autocommit' => [true], 'inside a transaction' => [false]]);

test('more failing rows than the server lists fail the call and apply nothing', function (): void {
    $connection = $this->connect();
    // One shared array for every failing row: little memory.
    $rows = [['id' => 1, 'code' => 'c1', 'qty' => 1], ...array_fill(0, 65536, ['id' => 2, 'code' => 'c2', 'qty' => -1])];
    $thrown = null;

    try {
        many($connection, INSERT, $rows);
    } catch (QueryException $queryException) {
        $thrown = $queryException;
    }

    expect($thrown)->not->toBeInstanceOf(ArrayDmlFailedException::class)
        ->and($thrown?->getMessage())->toBe('65536 of 65537 rows failed, more than the 65535 the server lists; none was applied.')
        ->and($this->value($connection, 'select count(*) from batch_rows'))->toBe(0);
});

test('returning gives one list per row in order', function (): void {
    $connection = $this->connect();
    many($connection, INSERT, valid(4));

    $inserted = many($connection, 'insert into dml_bind_rows (id) values (:id) returning id * 10 into :tenfold', [
        ['id' => 1, 'tenfold' => new Out\Number()],
        ['id' => 2, 'tenfold' => new Out\Number()],
        ['id' => 3, 'tenfold' => new Out\Number()],
    ]);
    $updated = many($connection, 'update batch_rows set qty = qty + 1 where id >= :low returning id into :ids', [
        ['low' => 3, 'ids' => new Out\Number()],
        ['low' => 9, 'ids' => new Out\Number()],
        ['low' => 1, 'ids' => new Out\Number()],
    ]);

    expect($inserted->rowCount())->toBe(3)
        ->and($inserted->outValues())->toBe(['tenfold' => [[10], [20], [30]]])
        ->and($updated->rowCount())->toBe(6)
        ->and(array_map(static function (array $ids): array {
            sort($ids);

            return $ids;
        }, $updated->outValues()['ids'] ?? []))->toBe([[3, 4], [], [1, 2, 3, 4]]);
});

test('returning lobs gives one list per row', function (): void {
    $connection = $this->connect();

    $lobs = many($connection, 'update dml_lobs set label = label where id = :id returning text_value, binary_value into :t, :b', [
        ['id' => 1, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['id' => 3, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['id' => 0, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['id' => 2, 't' => new Out\Clob(), 'b' => new Out\Blob()],
    ], autocommit: false);

    expect($lobs->rowCount())->toBe(3)
        ->and(contents($lobs->outValues()['t'] ?? []))->toBe([["Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}"], [null], [], ['']])
        ->and(contents($lobs->outValues()['b'] ?? []))->toBe([["\xDE\xAD\xBE\xEF"], [null], [], ['']]);
});

test('returning objects and collections gives one list per row', function (): void {
    $connection = $this->connect();

    $shapes = many($connection, 'update dml_shapes set id = id where id = :id returning center, tags into :center, :tags', [
        ['id' => 1, 'center' => new Out\Struct('dml_point'), 'tags' => new Out\Collection('dml_tags')],
        ['id' => 2, 'center' => new Out\Struct('dml_point'), 'tags' => new Out\Collection('dml_tags')],
        ['id' => 0, 'center' => new Out\Struct('dml_point'), 'tags' => new Out\Collection('dml_tags')],
        ['id' => 3, 'center' => new Out\Struct('dml_point'), 'tags' => new Out\Collection('dml_tags')],
    ], autocommit: false);

    expect($shapes->rowCount())->toBe(3)
        ->and($shapes->outValues())->toBe([
            'center' => [[['X' => '1.5', 'Y' => -2, 'LABEL' => 'center']], [null], [], [['X' => null, 'Y' => null, 'LABEL' => null]]],
            'tags' => [[['red', "caf\u{e9}"]], [null], [], [[]]],
        ]);
});

test('a plsql out bind gives one value per row in order', function (): void {
    $connection = $this->connect();

    $arrayDml = many($connection, 'begin :doubled := :n * 2; :name := :prefix || :n; end;', [
        ['n' => 1, 'doubled' => new Out\Number(), 'prefix' => 'a', 'name' => new Out\Varchar()],
        ['n' => 2, 'doubled' => new Out\Number(), 'prefix' => 'b', 'name' => new Out\Varchar()],
        ['n' => 3, 'doubled' => new Out\Number(), 'prefix' => 'c', 'name' => new Out\Varchar()],
    ]);

    expect($arrayDml->rowCount())->toBe(0)
        ->and($arrayDml->outValues())->toBe(['doubled' => [[2], [4], [6]], 'name' => [['a1'], ['b2'], ['c3']]]);
});

test('a long string makes its column clob', function (): void {
    $connection = $this->connect();

    $arrayDml = many($connection, 'begin :length := dml_text_length(:text); end;', [
        ['text' => 'short', 'length' => new Out\Number()],
        ['text' => str_repeat('é', 20000), 'length' => new Out\Number()],
        ['text' => '', 'length' => new Out\Number()],
        ['text' => null, 'length' => new Out\Number()],
    ]);

    expect($arrayDml->outValues())->toBe(['length' => [[5], [20000], [null], [null]]]);
});

test('nulls take their column type', function (): void {
    $connection = $this->connect();

    many($connection, 'insert into tx_rows (id, qty, note) values (:id, :qty, :note)', [
        ['id' => 1, 'qty' => null, 'note' => null],
        ['id' => 2.0, 'qty' => 5, 'note' => null],
        ['id' => 3, 'qty' => null, 'note' => null],
    ]);

    expect(otherSession()->select('select id, qty, note from tx_rows order by id')->all())
        ->toBe([['ID' => 1, 'QTY' => null, 'NOTE' => null], ['ID' => 2, 'QTY' => 5, 'NOTE' => null], ['ID' => 3, 'QTY' => null, 'NOTE' => null]]);
});

test('ints and floats in one column bind as an exact number', function (): void {
    $connection = $this->connect();

    $exact = many($connection, 'begin :copy := :n; end;', [
        ['n' => 1, 'copy' => new Out\Number()],
        ['n' => 0.1, 'copy' => new Out\Number()],
        ['n' => null, 'copy' => new Out\Number()],
    ]);

    expect($exact->outValues())->toBe(['copy' => [[1], ['0.1'], [null]]]);
});

test('objects collections and associative arrays bind per row', function (): void {
    $connection = $this->connect();

    $arrayDml = many($connection, MOVE_AND_SUM, [shapeRow(1), shapeRow(2), shapeRow(3)]);

    expect($arrayDml->outValues())->toBe([
        'point' => [[['X' => 2, 'Y' => 3, 'LABEL' => 'p1']], [['X' => 3, 'Y' => 4, 'LABEL' => 'p2']], [['X' => 4, 'Y' => 5, 'LABEL' => 'p3']]],
        'sum' => [[2], [5], [9]],
    ]);
});

test('an object type changing down a column is refused', function (): void {
    $connection = $this->connect();
    $changed = shapeRow(2);
    $changed['point'] = new InOut\Struct('dml_polygon', null);

    expect(fn (): ArrayDml => many($connection, MOVE_AND_SUM, [shapeRow(1), $changed]))
        ->toThrow(UsageException::class, "Row 1, bind 'point': InOut\\Struct('dml_polygon'), but row 0 made the column InOut\\Struct('dml_point');");
});

const MOVE_AND_SUM = 'begin dml_object.move_point(:point, 1, 2); :sum := dml_object.sum_numbers(:numbers) + dml_object.sum_map(:map); end;';

/**
 * @return iterable<string, array{string, list<mixed>, string}>
 */
dataset('refusals', function () {
    $row = static fn (mixed $id, mixed $code = 'a'): array => ['id' => $id, 'code' => $code, 'qty' => 1];
    yield 'different keys' => [INSERT, [$row(1), ['id' => 2, 'code' => 'b']], "Row 1 has no bind 'qty', which row 0 has; every row needs the same keys."];
    yield 'an extra key' => [INSERT, [$row(1), [...$row(2), 'note' => 'x']], "Row 1 has a bind 'note', which row 0 has not; every row needs the same keys."];
    yield 'an int and a string' => [INSERT, [$row(1), $row('2')], "Row 1, bind 'id': a string, but row 0 made the column a NUMBER (int or float); a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a string and a bool' => [INSERT, [$row(1, null), $row(2, 'b'), $row(3, true)], "Row 2, bind 'code': a bool, but row 1 made the column a string; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a typed class changing' => [INSERT, [$row(new In\Number(1)), $row(new In\Varchar('2'))], "Row 1, bind 'id': In\Varchar, but row 0 made the column In\Number; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a typed direction changing' => ['begin :x := :x; end;', [['x' => new InOut\Number(1)], ['x' => new In\Number(2)]], "Row 1, bind 'x': In\Number, but row 0 made the column InOut\Number; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a plain value in a typed column' => [INSERT, [$row(new In\Number(1)), $row(2)], "Row 1, bind 'id': an int, but row 0 made the column In\Number; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a plain null in a typed column' => [INSERT, [$row(null), $row(new In\Number(2))], "Row 0, bind 'id': a plain null in a column of In\Number; bind the NULL with that typed bind too."];
    yield 'Out\Cursor' => ['begin open :c for select 1 from dual; end;', [['c' => new Out\Cursor()]], "Row 0, bind 'c': runMany() cannot bring back a REF CURSOR; run the block with run() for each row."];
    yield 'a query' => ['select :id from dual', [['id' => 1]], 'runMany() runs DML or PL/SQL, and this statement is a query; read rows with select().'];
    yield 'a bind error' => [INSERT, [$row(1), $row([2])], "Row 1: Bind 'id': an array does not bind by itself; use In\Json or a typed bind."];
    yield 'a missing placeholder' => [INSERT, [['id' => 1, 'code' => 'a']], "Placeholder :QTY has no bind; add it to the binds, keyed 'qty'."];
    yield 'a row that is not an array' => [INSERT, [$row(1), 2], 'Row 1 is not an array; each row is an array of binds keyed by placeholder name.'];
});

test('a refused array dml fails before any round trip', function (string $sql, array $rows, string $message): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $thrown = null;

    $trips = roundTrips($sid, static function () use ($connection, $sql, $rows, &$thrown): void {
        try {
            // @phpstan-ignore argument.type (what a caller without static analysis may pass)
            many($connection, $sql, $rows);
        } catch (UsageException $usageException) {
            $thrown = $usageException;
        }
    });

    expect($thrown?->getMessage())->toBe($message)
        ->and($trips)->toBe(0);
})->with('refusals');

test('a placeholder written twice is refused before any round trip', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $thrown = null;

    $trips = roundTrips($sid, static function () use ($connection, &$thrown): void {
        try {
            many($connection, 'insert into batch_rows (id, code, qty) values (:id, :code, :id)', [['id' => 1, 'code' => 'a']]);
        } catch (UnsupportedException $unsupportedException) {
            $thrown = $unsupportedException;
        }
    });

    expect($thrown?->getMessage())->toBe('runMany() cannot bind a placeholder written more than once in the SQL yet; give each occurrence a name of its own.')
        ->and($trips)->toBe(0);
});

test('no rows make no round trip', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $arrayDml = null;

    $trips = roundTrips($sid, static function () use ($connection, &$arrayDml): void {
        $arrayDml = many($connection, INSERT, []);
    });

    assert($arrayDml instanceof ArrayDml);
    expect($trips)->toBe(0)
        ->and($arrayDml->rowCount())->toBe(0)
        ->and($arrayDml->outValues())->toBeEmpty();
});

test('implicit results are refused once the block ran and its rows are undone', function (bool $autocommit): void {
    $connection = $this->connect();
    $block = 'declare c sys_refcursor; begin insert into tx_rows (id) values (:id); open c for select :id from dual; dbms_sql.return_result(c); end;';
    $thrown = null;

    try {
        many($connection, $block, [['id' => 1], ['id' => 2]], $autocommit);
    } catch (UsageException $usageException) {
        $thrown = $usageException;
    }

    expect($thrown?->getMessage())->toBe("runMany() cannot return Implicit results (DBMS_SQL.RETURN_RESULT); run the block with run() for each row. The block's rows are undone.")
        ->and($this->value($connection, 'select count(*) from tx_rows'))->toBe(0)
        ->and(otherSession()->select('select count(*) from tx_rows')->value())->toBe(0);
})->with(['under autocommit' => [true], 'inside a transaction' => [false]]);

test('under autocommit a plsql block takes one more round trip for its commit', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $block = 'begin insert into tx_rows (id) values (:id); end;';
    $ids = array_map(static fn (int $id): array => ['id' => $id], range(1, 100));
    many($connection, INSERT, valid(1));
    many($connection, $block, [['id' => 1000]]);

    $dml = roundTrips($sid, static fn (): ArrayDml => many($connection, INSERT, valid(100, from: 2)));
    $plsql = roundTrips($sid, static fn (): ArrayDml => many($connection, $block, $ids));

    expect($dml)->toBe(1)
        ->and($plsql)->toBe(2)
        ->and(otherSession()->select('select count(*) from tx_rows')->value())->toBe(101);
});

test('inside a transaction an array dml takes one more round trip for its savepoint', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);

    $trips = roundTrips($sid, static fn (): ArrayDml => many($connection, INSERT, valid(100), autocommit: false));

    expect($trips)->toBe(2)
        ->and($this->value($connection, 'select count(*) from batch_rows'))->toBe(100)
        ->and(committed(otherSession()))->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function shapeRow(int $n): array
{
    return [
        'point' => new InOut\Struct('dml_point', ['x' => $n, 'y' => $n, 'label' => "p$n"]),
        'numbers' => new In\Collection('dml_numbers', range(1, $n)),
        'map' => new In\Indexed('dml_object.t_number_map', [-$n => $n]),
        'sum' => new Out\Number(),
    ];
}

/**
 * Ten valid rows but 3 (a duplicate id: ORA-00001), 5 (a NULL code:
 * ORA-01400) and 7 (a negative qty: ORA-02290).
 *
 * @return list<array<string, mixed>>
 */
function withBadRows(): array
{
    $rows = valid(10);
    $rows[3]['id'] = 1;
    $rows[5]['code'] = null;
    $rows[7]['qty'] = -1;

    return $rows;
}

/**
 * @param Closure(): mixed $call
 */
function failing(Closure $call): ArrayDmlFailedException
{
    try {
        $call();
    } catch (ArrayDmlFailedException $arrayDmlFailedException) {
        return $arrayDmlFailedException;
    }

    throw new RuntimeException('No ArrayDmlFailedException.');
}

/**
 * @return list<array{int, int}>
 */
function failures(ArrayDmlFailedException $e): array
{
    return array_map(static function (RowFailure $row): array {
        expect($row->message)->toStartWith(\sprintf('ORA-%05d: ', $row->code));

        return [$row->index, $row->code];
    }, $e->rows());
}

/**
 * @return list<array{id: int, code: string, qty: int}>
 */
function valid(int $count, int $from = 1): array
{
    return array_map(static fn (int $id): array => ['id' => $id, 'code' => "c$id", 'qty' => $id], range($from, $from + $count - 1));
}

/**
 * How many batch_rows rows another Session sees: committed ones only.
 */
function committed(Connection $otherSession): mixed
{
    return $otherSession->select('select count(*) from batch_rows')->value();
}

/**
 * @param list<list<mixed>> $lists
 *
 * @return list<list<string|null>>
 */
function contents(array $lists): array
{
    return array_map(static fn (array $list): array => array_map(static function (mixed $lob): string | null {
        if ($lob === null) {
            return null;
        }
        assert($lob instanceof Locator);
        $contents = '';
        while (($chunk = $lob->readChunk()) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }, $list), $lists);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function many(Session $connection, string $sql, array $rows, bool $autocommit = true): ArrayDml
{
    return $connection->executeMany($sql, $rows, TestCase::options(autocommit: $autocommit));
}
