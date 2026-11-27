<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\TransactionTest;

use Closure;
use DomainException;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Tests\Functional\TestCase;
use Throwable;
use TypeError;

uses(TestCase::class);

beforeAll(function (): void {
    schema()->run(<<<'SQL'
        create table tx_rows (
            id number(10) primary key,
            qty number(10) default 0 check (qty >= 0) deferrable initially deferred,
            note varchar2(100)
        )
        SQL);
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
    $this->connection = $this->connect();
});

/**
 * Each verb, writing tx_rows row 1.
 *
 * @return iterable<string, array{Closure(Connection): mixed}>
 */
dataset('writes', function () {
    yield 'run() of DML' => [static fn (Connection $c): Execution => $c->run('insert into tx_rows (id) values (1)')];
    yield 'run() of a block' => [static fn (Connection $c): Execution => $c->run('begin add_tx_row(1); end;')];
    yield 'procedure()' => [static fn (Connection $c): Execution => $c->procedure('add_tx_row', ['p_id' => 1])];
    yield 'function()' => [static fn (Connection $c): float | int | string | null => $c->function('add_tx_row_count', ['p_id' => 1])->number()];
});

test('under autocommit each verb commits at once', function (Closure $write): void {
    $write($this->connection);

    expect(committed($this->otherSession()))->toBe([1]);
})->with('writes');

test('inside begin each verb stays in the transaction', function (Closure $write): void {
    $this->connection->begin();

    $write($this->connection);

    expect(committed($this->otherSession()))->toBeEmpty();
})->with('writes');

test('commit makes the work since begin visible to other sessions', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into tx_rows (id) values (1)');

    $this->connection->commit();

    expect(committed($this->otherSession()))->toBe([1]);
});

test('rollback undoes the work since begin', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into tx_rows (id) values (1)');
    $this->connection->procedure('add_tx_row', ['p_id' => 2]);

    $this->connection->rollback();

    expect($this->connection->select('select count(*) from tx_rows')->value())->toBe(0);
});

/**
 * @return iterable<string, array{Closure(Connection): mixed}>
 */
dataset('transactionEnds', function () {
    yield 'commit()' => [static function (Connection $c): void {
        $c->begin();
        $c->commit();
    }];
    yield 'rollback()' => [static function (Connection $c): void {
        $c->begin();
        $c->rollback();
    }];
    yield 'transaction()' => [static fn (Connection $c) => $c->transaction(static fn (): null => null)];
});

test('after a transaction ends the connection is back in autocommit', function (Closure $transaction): void {
    $transaction($this->connection);

    $this->connection->run('insert into tx_rows (id) values (1)');

    expect(committed($this->otherSession()))->toBe([1]);
})->with('transactionEnds');

test('select for update under autocommit keeps no lock', function (): void {
    $this->connection->run('insert into tx_rows (id) values (1)');

    $this->connection->select('select id from tx_rows where id = 1 for update')->all();

    expect($this->locked('tx_rows', 1))->toBeFalse();
});

test('select for update inside begin keeps the lock', function (): void {
    $this->connection->run('insert into tx_rows (id) values (1)');
    $this->connection->begin();

    $this->connection->select('select id from tx_rows where id = 1 for update')->all();

    expect($this->locked('tx_rows', 1))->toBeTrue();
});

test('a function runs in the transaction open when its terminal is called', function (): void {
    $pending = $this->connection->function('add_tx_row_count', ['p_id' => 1]);
    $this->connection->begin();

    $pending->number();

    expect(committed($this->otherSession()))->toBeEmpty();
});

test('begin costs no round trip', function (): void {
    $sid = $this->sid($this->connection);

    $trips = $this->roundTrips($sid, $this->connection->begin(...));

    expect($trips)->toBe(0);
});

test('commit and rollback cost one round trip each', function (string $end): void {
    $sid = $this->sid($this->connection);
    $this->connection->begin();
    $this->connection->run('insert into tx_rows (id) values (1)');

    $trips = $this->roundTrips($sid, $this->connection->$end(...));

    expect($trips)->toBe(1);
})->with(['commit', 'rollback']);

/**
 * Each misuse, its message, and the Round trips it costs: inside
 * transaction(), the closure's rollback is one.
 *
 * @return iterable<string, array{0: Closure(Connection): void, 1: string, 2?: int}>
 */
dataset('misuses', function () {
    yield 'commit() outside a transaction' => [
        static fn (Connection $c) => $c->commit(),
        'There is no transaction to commit',
    ];
    yield 'rollback() outside a transaction' => [
        static fn (Connection $c) => $c->rollback(),
        'There is no transaction to roll back',
    ];
    yield 'begin() inside begin()' => [
        static function (Connection $c): void {
            $c->begin();
            $c->begin();
        },
        'Transactions do not nest',
    ];
    yield 'transaction() inside begin()' => [
        static function (Connection $c): void {
            $c->begin();
            $c->transaction(static fn (): null => null);
        },
        'Transactions do not nest',
    ];
    yield 'begin() inside transaction()' => [
        static fn (Connection $c) => $c->transaction(static fn (Connection $c) => $c->begin()),
        'Transactions do not nest',
        1,
    ];
    yield 'transaction() inside transaction()' => [
        static fn (Connection $c) => $c->transaction(static fn (Connection $c) => $c->transaction(static fn (): null => null)),
        'Transactions do not nest',
        1,
    ];
    yield 'commit() inside transaction()' => [
        static fn (Connection $c) => $c->transaction(static fn (Connection $c) => $c->commit()),
        'commit() inside transaction()',
        1,
    ];
    yield 'rollback() inside transaction()' => [
        static fn (Connection $c) => $c->transaction(static fn (Connection $c) => $c->rollback()),
        'rollback() inside transaction()',
        1,
    ];
});

test('a misused transaction verb throws usage exception before any round trip', function (Closure $misuse, string $message, int $expectedTrips = 0): void {
    $sid = $this->sid($this->connection);
    $thrown = null;

    $trips = $this->roundTrips($sid, function () use ($misuse, &$thrown): void {
        try {
            $misuse($this->connection);
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }
    });

    assert($thrown instanceof UsageException);
    expect($thrown->getMessage())->toContain($message)
        ->and($trips)->toBe($expectedTrips);
})->with('misuses');

test('a misused commit leaves the connection in autocommit', function (): void {
    expect(fn () => $this->connection->commit())->toThrow(UsageException::class);

    $this->connection->run('insert into tx_rows (id) values (1)');

    expect(committed($this->otherSession()))->toBe([1]);
});

test('a misused begin leaves the transaction open', function (): void {
    $this->connection->begin();
    expect(fn () => $this->connection->begin())->toThrow(UsageException::class);

    $this->connection->run('insert into tx_rows (id) values (1)');

    expect(committed($this->otherSession()))->toBeEmpty();
});

test('transaction commits when the closure returns and gives back its value', function (): void {
    $inside = null;

    $result = $this->connection->transaction(function (Connection $connection) use (&$inside): string {
        $connection->run('insert into tx_rows (id) values (1)');
        $inside = committed($this->otherSession());

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($inside)->toBeEmpty()
        ->and(committed($this->otherSession()))->toBe([1]);
});

test('transaction rolls back and rethrows whatever the closure throws', function (Throwable $thrown): void {
    $caught = null;

    try {
        $this->connection->transaction(static function (Connection $connection) use ($thrown): never {
            $connection->run('insert into tx_rows (id) values (1)');

            throw $thrown;
        });
    } catch (Throwable $throwable) {
        $caught = $throwable;
    }

    expect($caught)->toBe($thrown)
        ->and($thrown->getPrevious())->toBeNull()
        ->and($this->connection->select('select count(*) from tx_rows')->value())->toBe(0);
})->with([
    'an exception' => [new DomainException('the closure failed')],
    'an error' => [new TypeError('the closure failed')],
]);

test('a failed rollback becomes previous of the rethrown exception', function (): void {
    $connection = $this->connect(standalone: true);
    $thrown = new DomainException('the closure failed');
    $caught = null;

    try {
        $connection->transaction(function (Connection $connection) use ($thrown): never {
            $connection->run('insert into tx_rows (id) values (1)');
            $this->kill($this->sid($connection));

            throw $thrown;
        });
    } catch (DomainException $domainException) {
        $caught = $domainException;
    }

    expect($caught)->toBe($thrown)
        ->and($thrown->getPrevious())->toBeInstanceOf(ConnectionLostException::class)
        ->and(committed($this->otherSession()))->toBeEmpty();
});

test('a closure that closes the connection rethrows with nothing chained', function (): void {
    $thrown = new DomainException('the closure failed');
    $caught = null;

    try {
        $this->connection->transaction(static function (Connection $connection) use ($thrown): never {
            $connection->run('insert into tx_rows (id) values (1)');
            $connection->close();

            throw $thrown;
        });
    } catch (DomainException $domainException) {
        $caught = $domainException;
    }

    expect($caught)->toBe($thrown)
        ->and($thrown->getPrevious())->toBeNull()
        ->and(committed($this->otherSession()))->toBeEmpty();
});

/**
 * A transaction whose commit the server refuses: the deferred CHECK on qty
 * fails at COMMIT.
 *
 * @return iterable<string, array{Closure(Connection): mixed}>
 */
dataset('refusedCommits', function () {
    $work = static function (Connection $c): void {
        $c->run('insert into tx_rows (id) values (1)');
        $c->run('insert into tx_rows (id, qty) values (2, -1)');
    };
    yield 'commit()' => [static function (Connection $c) use ($work): void {
        $c->begin();
        $work($c);
        $c->commit();
    }];
    yield 'transaction()' => [static fn (Connection $c) => $c->transaction($work)];
});

test('a commit the server refuses throws and commits nothing', function (Closure $transaction): void {
    $thrown = null;

    try {
        $transaction($this->connection);
    } catch (QueryException $queryException) {
        $thrown = $queryException;
    }

    expect($thrown?->getCode())->toBe(2091)
        ->and(committed($this->otherSession()))->toBeEmpty();
})->with('refusedCommits');

test('after a refused commit the connection is back in autocommit', function (Closure $transaction): void {
    expect(fn () => $transaction($this->connection))->toThrow(QueryException::class);

    $this->connection->run('insert into tx_rows (id) values (3)');

    expect(committed($this->otherSession()))->toBe([3]);
})->with('refusedCommits');

test('rollback to a savepoint through run undoes only the later work', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into tx_rows (id) values (1)');
    $this->connection->run('savepoint before_two');
    $this->connection->run('insert into tx_rows (id) values (2)');

    $this->connection->run('rollback to savepoint before_two');
    $this->connection->commit();

    expect(committed($this->otherSession()))->toBe([1]);
});

test('closing the connection inside begin rolls back', function (): void {
    $this->connection->run("insert into tx_rows (id, note) values (1, 'committed')");
    $connection = Oracle::connect($this->config());
    $connection->begin();
    $connection->run("update tx_rows set note = 'changed' where id = 1");

    $connection->close();

    expect($this->locked('tx_rows', 1))->toBeFalse()
        ->and($this->otherSession()->select('select note from tx_rows')->value())->toBe('committed');
});

test('destroying the connection inside begin rolls back', function (): void {
    $this->connection->run("insert into tx_rows (id, note) values (1, 'committed')");
    $connection = Oracle::connect($this->config());
    $connection->begin();
    $connection->run("update tx_rows set note = 'changed' where id = 1");

    unset($connection);

    expect($this->locked('tx_rows', 1))->toBeFalse()
        ->and($this->otherSession()->select('select note from tx_rows')->value())->toBe('committed');
});

/**
 * The committed ids in tx_rows, as another Session sees them.
 *
 * @return list<mixed>
 */
function committed(Connection $otherSession): array
{
    return array_column($otherSession->select('select id from tx_rows order by id')->all(), 'ID');
}
