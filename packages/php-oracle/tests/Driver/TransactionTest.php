<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\TransactionTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Tests\Driver\TestCase;

uses(TestCase::class);

beforeAll(function (): void {
    schema()->run('create table tx_rows (id number(10) primary key, note varchar2(100))');
    schema()->run(<<<'SQL'
        create procedure add_tx_row(p_id number) is
        begin
            insert into tx_rows (id) values (p_id);
        end;
        SQL);
    schema()->run(<<<'SQL'
        create function add_tx_row_count(p_id number) return number is
            l_count number;
        begin
            insert into tx_rows (id) values (p_id);
            select count(*) into l_count from tx_rows;
            return l_count;
        end;
        SQL);
});

beforeEach(function (): void {
    schema()->run('truncate table tx_rows');
});

/**
 * Statements that write tx_rows row 1: a DML, the blocks Core generates for
 * procedure() and function(), and a hand-written block.
 *
 * @return iterable<string, array{string, array<string, mixed>}>
 */
dataset('writes', function () {
    yield 'DML' => ['insert into tx_rows (id) values (:id)', ['id' => 1]];
    yield 'procedure block' => ['BEGIN add_tx_row(p_id => :b1); END;', ['b1' => 1]];
    yield 'function block' => ['BEGIN :b0 := add_tx_row_count(p_id => :b1); END;', ['b0' => new Out\Number(), 'b1' => 1]];
    yield 'writing block' => ['begin insert into tx_rows (id) values (:id); end;', ['id' => 1]];
});

test('a write under autocommit is visible to another session at once', function (string $sql, array $binds): void {
    $connection = $this->connect();

    /** @var array<string, mixed> $binds */
    $connection->execute($sql, $binds, $this->options());

    expect(committed($this->otherSession()))->toBe([1]);
})->with('writes');

test('a failed statement under autocommit leaves nothing applied or locked', function (): void {
    $connection = $this->connect();
    $connection->execute('insert into tx_rows (id) values (1)', [], $this->options());

    $thrown = null;

    try {
        $connection->execute(
            "begin update tx_rows set note = 'failed' where id = 1; insert into tx_rows (id) values (2); raise_application_error(-20043, 'fail'); end;",
            [],
            $this->options(),
        );
    } catch (QueryException $queryException) {
        $thrown = $queryException;
    }

    expect($thrown?->getCode())->toBe(20043)
        ->and($this->otherSession()->select('select id, note from tx_rows')->all())->toBe([['ID' => 1, 'NOTE' => null]])
        ->and($this->locked('tx_rows', 1))->toBeFalse();
});

test('without autocommit a write is invisible until commit', function (): void {
    $connection = $this->connect();
    $connection->execute('insert into tx_rows (id) values (1)', [], $this->options(autocommit: false));

    $beforeCommit = committed($this->otherSession());

    $connection->commit();

    expect($beforeCommit)->toBeEmpty()
        ->and(committed($this->otherSession()))->toBe([1]);
});

test('without autocommit a rollback undoes the write', function (): void {
    $connection = $this->connect();
    $connection->execute('insert into tx_rows (id) values (1)', [], $this->options(autocommit: false));

    $connection->rollback();

    expect($this->value($connection, 'select count(*) from tx_rows'))->toBe(0);
});

test('autocommit adds no round trip to a statement', function (bool $autocommit): void {
    $insert = 'insert into tx_rows (id) values (:id)';
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $connection->execute($insert, ['id' => 1], $this->options());

    $trips = $this->roundTrips($sid, fn (): mixed => $connection->execute($insert, ['id' => 2], $this->options(autocommit: $autocommit)));

    expect($trips)->toBe(1);
})->with(['with autocommit' => [true], 'without autocommit' => [false]]);

test('commit and rollback take one round trip each', function (string $end): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $connection->execute('insert into tx_rows (id) values (1)', [], $this->options(autocommit: false));

    $trips = $this->roundTrips($sid, $connection->$end(...));

    expect($trips)->toBe(1);
})->with(['commit', 'rollback']);

/**
 * The committed ids in tx_rows, as another Session sees them.
 *
 * @return list<mixed>
 */
function committed(Connection $otherSession): array
{
    return array_column($otherSession->select('select id from tx_rows order by id')->all(), 'ID');
}
