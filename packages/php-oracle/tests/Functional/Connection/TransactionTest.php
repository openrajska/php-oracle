<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Connection\TransactionTest;

use Closure;
use DomainException;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Tests\Functional\TestCase;
use RuntimeException;
use Throwable;
use TypeError;

uses(TestCase::class);

beforeEach(function (): void {
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

describe('autocommit', function (): void {
    it('commits each verb at once', function (Closure $write): void {
        $write($this->connection);

        expect(committed(otherSession()))->toBe([1]);
    })->with('writes');

    it('keeps no lock after a select for update', function (): void {
        $this->connection->run('insert into tx_rows (id) values (1)');

        $this->connection->select('select id from tx_rows where id = 1 for update')->all();

        expect(locked('tx_rows', 1))->toBeFalse();
    });
});

describe('begin, commit and rollback', function (): void {
    it('keeps every verb inside the transaction', function (Closure $write): void {
        $this->connection->begin();

        $write($this->connection);

        expect(committed(otherSession()))->toBeEmpty();
    })->with('writes');

    it('shows the work to other sessions once it commits', function (): void {
        $this->connection->begin();
        $this->connection->run('insert into tx_rows (id) values (1)');

        $this->connection->commit();

        expect(committed(otherSession()))->toBe([1]);
    });

    it('undoes the work when it rolls back', function (): void {
        $this->connection->begin();
        $this->connection->run('insert into tx_rows (id) values (1)');
        $this->connection->procedure('add_tx_row', ['p_id' => 2]);

        $this->connection->rollback();

        expect($this->connection->select('select count(*) from tx_rows')->value())->toBe(0);
    });

    it('goes back to autocommit when the transaction ends', function (Closure $transaction): void {
        $transaction($this->connection);

        $this->connection->run('insert into tx_rows (id) values (1)');

        expect(committed(otherSession()))->toBe([1]);
    })->with('transactionEnds');

    it('keeps the lock of a select for update', function (): void {
        $this->connection->run('insert into tx_rows (id) values (1)');
        $this->connection->begin();

        $this->connection->select('select id from tx_rows where id = 1 for update')->all();

        expect(locked('tx_rows', 1))->toBeTrue();
    });

    it('runs a function in the transaction open when its result is read', function (): void {
        $pending = $this->connection->function('add_tx_row_count', ['p_id' => 1]);
        $this->connection->begin();

        $pending->number();

        expect(committed(otherSession()))->toBeEmpty();
    });

    it('undoes only the work after a savepoint', function (): void {
        $this->connection->begin();
        $this->connection->run('insert into tx_rows (id) values (1)');
        $this->connection->run('savepoint before_two');
        $this->connection->run('insert into tx_rows (id) values (2)');

        $this->connection->run('rollback to savepoint before_two');
        $this->connection->commit();

        expect(committed(otherSession()))->toBe([1]);
    });
});

describe('round trips', function (): void {
    it('costs nothing to begin', function (): void {
        $sid = $this->sid($this->connection);

        $trips = roundTrips($sid, $this->connection->begin(...));

        expect($trips)->toBe(0);
    });

    it('costs one round trip to commit or roll back', function (string $end): void {
        $sid = $this->sid($this->connection);
        $this->connection->begin();
        $this->connection->run('insert into tx_rows (id) values (1)');

        $trips = roundTrips($sid, $this->connection->$end(...));

        expect($trips)->toBe(1);
    })->with(['commit', 'rollback']);
});

describe('misuse', function (): void {
    it('throws usage exception before any round trip', function (Closure $misuse, string $message, int $expectedTrips = 0): void {
        $sid = $this->sid($this->connection);
        $thrown = null;

        $trips = roundTrips($sid, function () use ($misuse, &$thrown): void {
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

    it('leaves the connection in autocommit after a misused commit', function (): void {
        expect(fn () => $this->connection->commit())->toThrow(UsageException::class);

        $this->connection->run('insert into tx_rows (id) values (1)');

        expect(committed(otherSession()))->toBe([1]);
    });

    it('leaves the transaction open after a misused begin', function (): void {
        $this->connection->begin();
        expect(fn () => $this->connection->begin())->toThrow(UsageException::class);

        $this->connection->run('insert into tx_rows (id) values (1)');

        expect(committed(otherSession()))->toBeEmpty();
    });
});

describe('transactions in a closure', function (): void {
    it('commits when the closure returns and gives back its value', function (): void {
        $inside = null;

        $result = $this->connection->transaction(function (Connection $connection) use (&$inside): string {
            $connection->run('insert into tx_rows (id) values (1)');
            $inside = committed(otherSession());

            return 'done';
        });

        expect($result)->toBe('done')
            ->and($inside)->toBeEmpty()
            ->and(committed(otherSession()))->toBe([1]);
    });

    it('rolls back and rethrows whatever the closure throws', function (Throwable $thrown): void {
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

    it('chains a failed rollback as previous of the rethrown exception', function (): void {
        $connection = $this->connect(standalone: true);
        $thrown = new DomainException('the closure failed');
        $caught = null;

        try {
            $connection->transaction(function (Connection $connection) use ($thrown): never {
                $connection->run('insert into tx_rows (id) values (1)');
                kill($this->sid($connection));

                throw $thrown;
            });
        } catch (DomainException $domainException) {
            $caught = $domainException;
        }

        expect($caught)->toBe($thrown)
            ->and($thrown->getPrevious())->toBeInstanceOf(ConnectionLostException::class)
            ->and(committed(otherSession()))->toBeEmpty();
    });

    it('chains a failed rollback at the end of an existing previous chain', function (): void {
        $connection = $this->connect();
        $cause = new RuntimeException('the cause');
        $thrown = new DomainException('the closure failed', 0, $cause);

        $transaction = fn () => $connection->transaction(function (Connection $connection) use ($thrown): never {
            $connection->run('insert into tx_rows (id) values (1)');
            kill($this->sid($connection));

            throw $thrown;
        });

        expect($transaction)->toThrow(function (DomainException $exception) use ($thrown, $cause): void {
            expect($exception)->toBe($thrown)
                ->and($exception->getPrevious())->toBe($cause)
                ->and($cause->getPrevious())->toBeInstanceOf(ConnectionLostException::class);
        });
    });

    it('rethrows with nothing chained when the closure closes the connection', function (): void {
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
            ->and(committed(otherSession()))->toBeEmpty();
    });
});

describe('refused commits', function (): void {
    it('throws and commits nothing', function (Closure $transaction): void {
        $thrown = null;

        try {
            $transaction($this->connection);
        } catch (QueryException $queryException) {
            $thrown = $queryException;
        }

        expect($thrown?->getCode())->toBe(2091)
            ->and(committed(otherSession()))->toBeEmpty();
    })->with('refusedCommits');

    it('goes back to autocommit', function (Closure $transaction): void {
        expect(fn () => $transaction($this->connection))->toThrow(QueryException::class);

        $this->connection->run('insert into tx_rows (id) values (3)');

        expect(committed(otherSession()))->toBe([3]);
    })->with('refusedCommits');
});

describe('closing the connection', function (): void {
    it('rolls back an open transaction', function (): void {
        $this->connection->run("insert into tx_rows (id, note) values (1, 'committed')");
        $connection = Oracle::connect($this->config());
        $connection->begin();
        $connection->run("update tx_rows set note = 'changed' where id = 1");

        $connection->close();

        expect(locked('tx_rows', 1))->toBeFalse()
            ->and(otherSession()->select('select note from tx_rows')->value())->toBe('committed');
    });

    it('rolls back an open transaction when the connection is destroyed', function (): void {
        $this->connection->run("insert into tx_rows (id, note) values (1, 'committed')");
        $connection = Oracle::connect($this->config());
        $connection->begin();
        $connection->run("update tx_rows set note = 'changed' where id = 1");

        unset($connection);

        expect(locked('tx_rows', 1))->toBeFalse()
            ->and(otherSession()->select('select note from tx_rows')->value())->toBe('committed');
    });
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
