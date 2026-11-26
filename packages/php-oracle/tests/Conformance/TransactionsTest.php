<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\TransactionsTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Contracts\Driver\Response;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TransactionObserver;

beforeEach(function (): void {
    $this->transactionObserver = TransactionObserver::open();
    $this->transactionObserver->clear();
});

afterEach(function (): void {
    $this->transactionObserver->close();
});

/**
 * Statements that write fx_tx_rows row 1: a DML, the blocks Core
 * generates for procedure() and function(), and a hand-written block.
 *
 * @return iterable<string, array{string, array<string, mixed>}>
 */
dataset('writes', function () {
    yield 'DML' => ['insert into fx_tx_rows (id) values (:id)', ['id' => 1]];
    yield 'procedure block' => ['BEGIN fx_tx.add_row(p_id => :b1); END;', ['b1' => 1]];
    yield 'function block' => ['BEGIN :b0 := fx_tx.add_row_count(p_id => :b1); END;', ['b0' => new Out\Number(), 'b1' => 1]];
    yield 'writing block' => ['begin insert into fx_tx_rows (id) values (:id); end;', ['id' => 1]];
});

test('a write under autocommit is visible to another session at once', function (string $sql, array $binds): void {
    /** @var array<string, mixed> $binds */
    $this->connect()->execute($sql, $binds, $this->options());

    expect($this->transactionObserver->committed())->toBe([1]);
})->with('writes');

test('a failed statement under autocommit leaves nothing applied or locked', function (): void {
    $connection = $this->connect();
    $connection->execute('insert into fx_tx_rows (id) values (1)', [], $this->options());

    try {
        $connection->execute(
            "begin update fx_tx_rows set note = 'failed' where id = 1; insert into fx_tx_rows (id) values (2); raise_application_error(-20043, 'fail'); end;",
            [],
            $this->options(),
        );
        $this->fail('The block did not fail.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(20043);
    }

    expect($this->transactionObserver->select('select id, note from fx_tx_rows'))->toBe([['ID' => '1', 'NOTE' => null]])
        ->and($this->transactionObserver->locked(1))->toBeFalse();
});

test('without autocommit a write is invisible until commit and gone after rollback', function (): void {
    $connection = $this->connect();

    $connection->execute('insert into fx_tx_rows (id) values (1)', [], $this->options(autocommit: false));

    expect($this->transactionObserver->committed())->toBeEmpty();
    $connection->commit();
    expect($this->transactionObserver->committed())->toBe([1]);

    $connection->execute('insert into fx_tx_rows (id) values (2)', [], $this->options(autocommit: false));
    $connection->rollback();

    expect($this->transactionObserver->committed())->toBe([1])
        ->and($this->value($connection, 'select count(*) from fx_tx_rows'))->toBe(1);
});

test('a query under autocommit keeps no lock', function (): void {
    $connection = $this->connect();
    $connection->execute('insert into fx_tx_rows (id) values (1)', [], $this->options());

    $this->rows($connection, 'select id from fx_tx_rows where id = 1 for update');

    expect($this->transactionObserver->locked(1))->toBeFalse();
});

test('a for update query under autocommit fails its next fetch with ora 01002', function (): void {
    $connection = $this->connect();
    $cursor = $connection->execute(
        'select id from fx_items where id <= 5 for update',
        [],
        $this->options(fetchSize: 2),
    )->cursor();
    assert($cursor !== null);
    expect($cursor->fetchBatch())->toHaveCount(2);

    try {
        $cursor->fetchBatch();
        $this->fail('The second fetch did not fail.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(1002);
    } finally {
        $cursor->close();
    }
});

test('autocommit adds no round trip and commit and rollback take one each', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $insert = 'insert into fx_tx_rows (id) values (:id)';

    // Parsed once, so every count below is of a cached statement.
    $connection->execute($insert, ['id' => 1], $this->options());
    $counter = RoundTripCounter::open();

    try {
        expect($counter->during($sid, fn (): Response => $connection->execute($insert, ['id' => 2], $this->options())))->toBe(1)
            ->and($counter->during($sid, fn (): Response => $connection->execute($insert, ['id' => 3], $this->options(autocommit: false))))->toBe(1)
            ->and($counter->during($sid, $connection->commit(...)))->toBe(1);
        $connection->execute($insert, ['id' => 4], $this->options(autocommit: false));
        expect($counter->during($sid, $connection->rollback(...)))->toBe(1);
    } finally {
        $counter->close();
    }
    expect($this->transactionObserver->committed())->toBe([1, 2, 3]);
});

test('a query of up to fetch size rows under autocommit takes one round trip', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $query = 'select id from fx_items where id <= :last';
    $counter = RoundTripCounter::open();

    try {
        foreach ([false, true, true] as $autocommit) {
            foreach ([2, 3] as $last) {
                $rows = [];
                expect($counter->during($sid, function () use ($connection, $query, $last, $autocommit, &$rows): void {
                    $rows = $this->rows($connection, $query, ['last' => $last], $this->options(fetchSize: 3, autocommit: $autocommit));
                }))->toBe(1, "$last rows, autocommit: " . var_export($autocommit, true))
                    ->and($rows)->toHaveCount($last);
            }
        }
    } finally {
        $counter->close();
    }
});
