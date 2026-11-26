<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ArrayDmlTest;

use Closure;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\ArrayDml;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\ArrayDmlFailedException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use RuntimeException;

beforeEach(function (): void {
    $this->independentSession = IndependentSession::open();
    $this->counter = RoundTripCounter::open();
    clear($this->independentSession);
});

afterEach(function (): void {
    clear($this->independentSession);
    $this->counter->close();
    $this->independentSession->close();
});

test('a thousand valid rows take one round trip', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    $sid = $this->sid($connection);
    $rows = valid(1000);

    $arrayDml = null;
    $trips = $this->counter->during($sid, function () use ($connection, $rows, $insert, &$arrayDml): void {
        $arrayDml = $connection->executeMany($insert, $rows, $this->options());
    });

    assert($arrayDml instanceof ArrayDml);
    expect($trips)->toBe(1)
        ->and($arrayDml->rowCount())->toBe(1000)
        ->and($arrayDml->outValues())->toBeEmpty()
        ->and(committed($this->independentSession))->toBe(1000);
});

test('rows bind by name whatever the order and case of their keys', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();

    $arrayDml = many($connection, $insert, [
        ['id' => 1, 'code' => 'one', 'qty' => 10],
        ['QTY' => 20, 'Id' => 2, 'code' => 'two'],
        ['id' => 3, 'code' => 'three', 'qty' => 30],
    ]);

    expect($arrayDml->rowCount())->toBe(3)
        ->and($this->independentSession->select('select id, code, qty from fx_batch_rows order by id'))->toBe([['ID' => '1', 'CODE' => 'one', 'QTY' => '10'], ['ID' => '2', 'CODE' => 'two', 'QTY' => '20'], ['ID' => '3', 'CODE' => 'three', 'QTY' => '30']]);
});

test('failing rows under autocommit give one array dml exception and apply nothing', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();

    $e = failing(static fn (): ArrayDml => many($connection, $insert, withBadRows()));

    expect(failures($e))->toBe([[3, 1], [5, 1400], [7, 2290]])
        ->and($e->getCode())->toBe(1)
        ->and($e->getMessage())->toStartWith('3 of 10 rows failed; first: row 3: ORA-00001: ')
        ->and($e->sql())->toBe($insert)
        ->and(committed($this->independentSession))->toBe(0)
        ->and($this->value($connection, 'select count(*) from fx_batch_rows'))->toBe(0);
});

test('failing rows inside a transaction keep the earlier work uncommitted', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    $connection->execute('insert into fx_tx_rows (id) values (1)', [], $this->options(autocommit: false));

    $e = failing(static fn (): ArrayDml => many($connection, $insert, withBadRows(), autocommit: false));

    expect(failures($e))->toBe([[3, 1], [5, 1400], [7, 2290]])
        ->and($this->value($connection, 'select count(*) from fx_batch_rows'))->toBe(0)
        ->and($this->value($connection, 'select count(*) from fx_tx_rows'))->toBe(1)
        ->and($this->independentSession->select('select count(*) as n from fx_tx_rows')[0]['N'])->toBe('0');

    $connection->commit();
    expect($this->independentSession->select('select count(*) as n from fx_tx_rows')[0]['N'])->toBe('1')
        ->and(committed($this->independentSession))->toBe(0);
});

test('only duplicates still give an array dml exception', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    $rows = valid(4);
    $rows[1]['id'] = 1;
    $rows[3]['code'] = 'c1';

    $e = failing(static fn (): ArrayDml => many($connection, $insert, $rows));

    expect($e::class)->toBe(ArrayDmlFailedException::class)
        ->and(failures($e))->toBe([[1, 1], [3, 1]])
        ->and(committed($this->independentSession))->toBe(0);
});

test('returning gives one list per row in order', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    many($connection, $insert, valid(4));

    $inserted = many($connection, 'insert into fx_bind_rows (id) values (:id) returning id * 10 into :tenfold', [
        ['id' => 1, 'tenfold' => new Out\Number()],
        ['id' => 2, 'tenfold' => new Out\Number()],
        ['id' => 3, 'tenfold' => new Out\Number()],
    ]);
    $updated = many($connection, 'update fx_batch_rows set qty = qty + 1 where id >= :low returning id into :ids', [
        ['low' => 3, 'ids' => new Out\Number()],
        ['low' => 9, 'ids' => new Out\Number()],
        ['low' => 1, 'ids' => new Out\Number()],
    ]);

    expect($inserted->rowCount())->toBe(3)
        ->and($inserted->outValues())->toBe(['tenfold' => [[10], [20], [30]]])
        ->and($updated->rowCount())->toBe(6);

    $ids = $updated->outValues()['ids'];
    expect($ids)->toHaveCount(3)
        ->and(array_map(static function (array $ids): array {
            sort($ids);

            return $ids;
        }, $ids))->toBe([[3, 4], [], [1, 2, 3, 4]]);
});

test('returning lobs objects and collections gives one list per row', function (): void {
    $connection = $this->connect();

    $lobs = many($connection, 'update fx_lobs set label = label where id = :id returning text_value, binary_value into :t, :b', [
        ['id' => 1, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['id' => 3, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['id' => 0, 't' => new Out\Clob(), 'b' => new Out\Blob()],
        ['id' => 2, 't' => new Out\Clob(), 'b' => new Out\Blob()],
    ], autocommit: false);
    expect($lobs->rowCount())->toBe(3)
        ->and(contents($lobs->outValues()['t']))->toBe([["Hello, LOB! A\u{e7}\u{e3}o \u{65e5}\u{672c}"], [null], [], ['']])
        ->and(contents($lobs->outValues()['b']))->toBe([["\xDE\xAD\xBE\xEF"], [null], [], ['']]);

    $connection->rollback();

    $shapes = many($connection, 'update fx_shapes set id = id where id = :id returning center, tags into :center, :tags', [
        ['id' => 1, 'center' => new Out\Struct('fx_point'), 'tags' => new Out\Collection('fx_string_varray')],
        ['id' => 2, 'center' => new Out\Struct('fx_point'), 'tags' => new Out\Collection('fx_string_varray')],
        ['id' => 0, 'center' => new Out\Struct('fx_point'), 'tags' => new Out\Collection('fx_string_varray')],
        ['id' => 3, 'center' => new Out\Struct('fx_point'), 'tags' => new Out\Collection('fx_string_varray')],
    ], autocommit: false);
    expect($shapes->rowCount())->toBe(3)
        ->and($shapes->outValues())->toBe([
            'center' => [[['X' => '1.5', 'Y' => -2, 'LABEL' => 'center']], [null], [], [['X' => null, 'Y' => null, 'LABEL' => null]]],
            'tags' => [[['red', "caf\u{e9}"]], [null], [], [[]]],
        ]);
    $connection->rollback();
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

test('no rows make no round trip', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    $sid = $this->sid($connection);

    $arrayDml = null;
    $trips = $this->counter->during($sid, static function () use ($connection, $insert, &$arrayDml): void {
        $arrayDml = many($connection, $insert, []);
    });

    assert($arrayDml instanceof ArrayDml);
    expect($trips)->toBe(0)
        ->and($arrayDml->rowCount())->toBe(0)
        ->and($arrayDml->outValues())->toBeEmpty();
});

/**
 * @return iterable<string, array{string, list<array<string, mixed>>, string}>
 */
dataset('refusals', function () {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $row = static fn (mixed $id, mixed $code = 'a'): array => ['id' => $id, 'code' => $code, 'qty' => 1];
    yield 'different keys' => [$insert, [$row(1), ['id' => 2, 'code' => 'b']], "Row 1 has no bind 'qty', which row 0 has; every row needs the same keys."];
    yield 'an extra key' => [$insert, [$row(1), [...$row(2), 'note' => 'x']], "Row 1 has a bind 'note', which row 0 has not; every row needs the same keys."];
    yield 'an int and a string' => [$insert, [$row(1), $row('2')], "Row 1, bind 'id': a string, but row 0 made the column a NUMBER (int or float); a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a string and a bool' => [$insert, [$row(1, null), $row(2, 'b'), $row(3, true)], "Row 2, bind 'code': a bool, but row 1 made the column a string; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a typed class changing' => [$insert, [$row(new In\Number(1)), $row(new In\Varchar('2'))], "Row 1, bind 'id': In\Varchar, but row 0 made the column In\Number; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a typed direction changing' => ['begin :x := :x; end;', [['x' => new InOut\Number(1)], ['x' => new In\Number(2)]], "Row 1, bind 'x': In\Number, but row 0 made the column InOut\Number; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a plain value in a typed column' => [$insert, [$row(new In\Number(1)), $row(2)], "Row 1, bind 'id': an int, but row 0 made the column In\Number; a column takes one type (an int and a float mix as NUMBER)."];
    yield 'a plain null in a typed column' => [$insert, [$row(null), $row(new In\Number(2))], "Row 0, bind 'id': a plain null in a column of In\Number; bind the NULL with that typed bind too."];
    yield 'Out\Cursor' => ['begin open :c for select 1 from dual; end;', [['c' => new Out\Cursor()]], "Row 0, bind 'c': runMany() cannot bring back a REF CURSOR; run the block with run() for each row."];
    yield 'a query' => ['select :id from dual', [['id' => 1]], 'runMany() runs DML or PL/SQL, and this statement is a query; read rows with select().'];
    yield 'a bind error' => [$insert, [$row(1), $row([2])], "Row 1: Bind 'id': an array does not bind by itself; use In\Json or a typed bind."];
    yield 'a missing placeholder' => [$insert, [['id' => 1, 'code' => 'a']], "Placeholder :QTY has no bind; add it to the binds, keyed 'qty'."];
});

test('a refused array dml fails before any round trip', function (string $sql, array $rows, string $message): void {
    /** @var list<array<string, mixed>> $rows */
    $connection = $this->connect();
    $sid = $this->sid($connection);

    $trips = $this->counter->during($sid, function () use ($connection, $sql, $rows, $message): void {
        try {
            many($connection, $sql, $rows);
            $this->fail('The Array DML ran.');
        } catch (UsageException $usageException) {
            expect($usageException->getMessage())->toBe($message);
        }
    });

    expect($trips)->toBe(0);
})->with('refusals');

test('a row that is not an array is refused', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    // @phpstan-ignore argument.type (what a caller without static analysis may pass)
    expect(fn () => $connection->executeMany($insert, [['id' => 1, 'code' => 'a', 'qty' => 1], 2], $this->options()))->toThrow(UsageException::class, 'Row 1 is not an array; each row is an array of binds keyed by placeholder name.');
});

test('implicit results are refused once the block ran', function (): void {
    $connection = $this->connect();
    $block = 'declare c sys_refcursor; begin insert into fx_tx_rows (id) values (:id); open c for select :id from dual; dbms_sql.return_result(c); end;';
    $refused = 'runMany() cannot return Implicit results (DBMS_SQL.RETURN_RESULT); run the block with run() for each row.';

    foreach ([false, true] as $autocommit) {
        expect(function () use ($connection, $block, $autocommit): void {
            many($connection, $block, [['id' => 1], ['id' => 2]], $autocommit);
            $this->fail('The Implicit results came back.');
        })->toThrow(UsageException::class, $refused . " The block's rows are undone.")
            ->and($this->value($connection, 'select count(*) from fx_tx_rows'))->toBe(0);
    }
    expect($this->independentSession->select('select count(*) as n from fx_tx_rows')[0]['N'])->toBe('0', 'nothing committed');
});

test('under autocommit a plsql block takes one more round trip for its commit', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    $sid = $this->sid($connection);
    $block = 'begin insert into fx_tx_rows (id) values (:id); end;';
    $ids = static fn (int $from): array => array_map(static fn (int $id): array => ['id' => $id], range($from, $from + 99));
    many($connection, $block, $ids(1));

    $dml = $this->counter->during($sid, static fn (): ArrayDml => many($connection, $insert, valid(100)));
    $plsql = $this->counter->during($sid, static fn (): ArrayDml => many($connection, $block, $ids(101)));

    expect($dml)->toBe(1)
        ->and($plsql)->toBe(2)
        ->and(committed($this->independentSession))->toBe(100)
        ->and($this->independentSession->select('select count(*) as n from fx_tx_rows')[0]['N'])->toBe('200');
});

test('a long string makes its column clob', function (): void {
    $connection = $this->connect();

    $arrayDml = many($connection, 'begin :length := fx_lob.text_length(:text); end;', [
        ['text' => 'short', 'length' => new Out\Number()],
        ['text' => str_repeat('é', 20000), 'length' => new Out\Number()],
        ['text' => '', 'length' => new Out\Number()],
        ['text' => null, 'length' => new Out\Number()],
    ]);

    expect($arrayDml->outValues())->toBe(['length' => [[5], [20000], [null], [null]]]);
});

test('nulls take their columns type and ints and floats bind as an exact number', function (): void {
    $connection = $this->connect();

    many($connection, 'insert into fx_tx_rows (id, qty, note) values (:id, :qty, :note)', [
        ['id' => 1, 'qty' => null, 'note' => null],
        ['id' => 2.0, 'qty' => 5, 'note' => null],
        ['id' => 3, 'qty' => null, 'note' => null],
    ]);
    $exact = many($connection, 'begin :copy := :n; end;', [
        ['n' => 1, 'copy' => new Out\Number()],
        ['n' => 0.1, 'copy' => new Out\Number()],
        ['n' => null, 'copy' => new Out\Number()],
    ]);

    expect($this->independentSession->select('select id, qty, note from fx_tx_rows order by id'))->toBe([['ID' => '1', 'QTY' => null, 'NOTE' => null], ['ID' => '2', 'QTY' => '5', 'NOTE' => null], ['ID' => '3', 'QTY' => null, 'NOTE' => null]])
        ->and($exact->outValues())->toBe(['copy' => [[1], ['0.1'], [null]]]);
});

test('objects collections and associative arrays bind per row', function (): void {
    $connection = $this->connect();
    $block = 'begin fx_object.move_point(:point, 1, 2); :sum := fx_object.sum_numbers(:numbers) + fx_object.sum_map(:map); end;';
    $row = static fn (int $n): array => [
        'point' => new InOut\Struct('fx_point', ['x' => $n, 'y' => $n, 'label' => "p$n"]),
        'numbers' => new In\Collection('fx_number_list', range(1, $n)),
        'map' => new In\Indexed('fx_object.t_number_map', [-$n => $n]),
        'sum' => new Out\Number(),
    ];

    $arrayDml = many($connection, $block, [$row(1), $row(2), $row(3)]);

    expect($arrayDml->outValues())->toBe([
        'point' => [[['X' => 2, 'Y' => 3, 'LABEL' => 'p1']], [['X' => 3, 'Y' => 4, 'LABEL' => 'p2']], [['X' => 4, 'Y' => 5, 'LABEL' => 'p3']]],
        'sum' => [[2], [5], [9]],
    ]);

    $changed = $row(2);
    $changed['point'] = new InOut\Struct('fx_polygon', null);
    try {
        many($connection, $block, [$row(1), $changed]);
        $this->fail('The Object type changed down the column.');
    } catch (UsageException $usageException) {
        expect($usageException->getMessage())->toStartWith("Row 1, bind 'point': InOut\Struct('fx_polygon'), but row 0 made the column InOut\Struct('fx_point');");
    }
});

test('a block failing at a row leaves nothing applied', function (): void {
    $connection = $this->connect();
    $block = 'begin insert into fx_tx_rows (id) values (:id); end;';
    foreach ([true, false] as $autocommit) {
        if (!$autocommit) {
            $connection->execute('insert into fx_batch_rows (id, code, qty) values (1, :code, 1)', ['code' => 'a'], $this->options(autocommit: false));
        }
        try {
            many($connection, $block, [['id' => 1], ['id' => 2], ['id' => 1], ['id' => 3]], $autocommit);
            $this->fail('The duplicate was inserted.');
        } catch (UniqueViolationException $e) {
            expect($e->getMessage())->toStartWith('ORA-00001: ');
        }
        expect($this->value($connection, 'select count(*) from fx_tx_rows'))->toBe(0);
    }

    expect($this->value($connection, 'select count(*) from fx_batch_rows'))->toBe(1, 'the earlier work')
        ->and(committed($this->independentSession))->toBe(0)
        ->and($this->independentSession->select('select count(*) as n from fx_tx_rows')[0]['N'])->toBe('0');
});

test('more failing rows than the server lists fail the call and apply nothing', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();

    // One shared array for every failing row: little memory, which a
    // later test of memory_limit needs.
    $rows = [['id' => 1, 'code' => 'c1', 'qty' => 1], ...array_fill(0, 65536, ['id' => 2, 'code' => 'c2', 'qty' => -1])];

    try {
        many($connection, $insert, $rows);
        $this->fail('The rows were inserted.');
    } catch (QueryException $queryException) {
        expect($queryException)->not->toBeInstanceOf(ArrayDmlFailedException::class)
            ->and($queryException->getMessage())->toBe('65536 of 65537 rows failed, more than the 65535 the server lists; none was applied.');
    }

    expect($this->value($connection, 'select count(*) from fx_batch_rows'))->toBe(0)
        ->and(committed($this->independentSession))->toBe(0);
});

test('inside a transaction an array dml takes one more round trip for its savepoint', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $connection = $this->connect();
    $sid = $this->sid($connection);

    $trips = $this->counter->during($sid, static fn (): ArrayDml => many($connection, $insert, valid(100), autocommit: false));

    expect($trips)->toBe(2)
        ->and($this->value($connection, 'select count(*) from fx_batch_rows'))->toBe(100)
        ->and(committed($this->independentSession))->toBe(0);
});

test('a placeholder written twice is refused before any round trip', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);

    $trips = $this->counter->during($sid, function () use ($connection): void {
        try {
            many($connection, 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :id)', [['id' => 1, 'code' => 'a']]);
            $this->fail('The Array DML ran.');
        } catch (UnsupportedException $unsupportedException) {
            expect($unsupportedException->getMessage())->toBe('runMany() cannot bind a placeholder written more than once in the SQL yet; give each occurrence a name of its own.');
        }
    });

    expect($trips)->toBe(0);
});

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
function valid(int $count): array
{
    return array_map(static fn (int $id): array => ['id' => $id, 'code' => "c$id", 'qty' => $id], range(1, $count));
}

/**
 * How many fx_batch_rows rows another Session sees: committed ones only.
 */
function committed(IndependentSession $observer): int
{
    return (int) $observer->select('select count(*) as n from fx_batch_rows')[0]['N'];
}

function clear(IndependentSession $observer): void
{
    $observer->execute('delete from fx_batch_rows');
    $observer->execute('delete from fx_tx_rows');
    $observer->commit();
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
    return $connection->executeMany($sql, $rows, test()->options(autocommit: $autocommit));
}
