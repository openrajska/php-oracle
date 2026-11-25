<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\TransactionsTest;

use Closure;
use DomainException;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tests\Support\TransactionObserver;
use Throwable;
use TypeError;

beforeEach(function (): void {
    $this->observer = TransactionObserver::open();
    $this->counter = RoundTripCounter::open();
    $this->observer->clear();
    $this->connection = connect();
});
afterEach(function (): void {
    $this->connection->close();
    $this->counter->close();
    $this->observer->close();
});
test('after begin work is invisible until commit', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');

    expect($this->observer->committed())->toBe([]);

    $this->connection->commit();

    expect($this->observer->committed())->toBe([1]);
});
test('after rollback work is gone and the connection is back in autocommit', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    $this->connection->procedure('fx_tx.add_row', ['p_id' => 2]);
    $this->connection->rollback();

    expect($this->connection->select('select count(*) from fx_tx_rows')->value())->toBe(0);

    $this->connection->run('insert into fx_tx_rows (id) values (3)');

    expect($this->observer->committed())->toBe([3]);
});
test('after commit the connection is back in autocommit', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    $this->connection->commit();

    $this->connection->run('insert into fx_tx_rows (id) values (2)');

    expect($this->observer->committed())->toBe([1, 2]);
});
test('under autocommit every verb commits at once', function (): void {
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    expect($this->observer->committed())->toBe([1]);

    $this->connection->procedure('fx_tx.add_row', ['p_id' => 2]);
    expect($this->observer->committed())->toBe([1, 2])
        ->and($this->connection->function('fx_tx.add_row_count', ['p_id' => 3])->number())->toBe(3)
        ->and($this->observer->committed())->toBe([1, 2, 3]);

    $this->connection->run('begin fx_tx.add_row(4); end;');
    expect($this->observer->committed())->toBe([1, 2, 3, 4]);
});
test('select for update under autocommit keeps no lock', function (): void {
    $this->connection->run('insert into fx_tx_rows (id) values (1)');

    expect($this->connection->select('select id from fx_tx_rows where id = 1 for update')->value())->toBe(1)
        ->and($this->observer->locked(1))->toBeFalse();
});
test('inside begin every verb stays in the transaction', function (): void {
    $this->connection->run('insert into fx_tx_rows (id) values (5)');
    $pending = $this->connection->function('fx_tx.add_row_count', ['p_id' => 3]);
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    $this->connection->procedure('fx_tx.add_row', ['p_id' => 2]);

    expect($pending->number())->toBe(4);
    $this->connection->run('begin fx_tx.add_row(4); end;');
    expect($this->connection->select('select id from fx_tx_rows where id = 5 for update')->value())->toBe(5)
        ->and($this->observer->committed())->toBe([5])
        ->and($this->observer->locked(5))->toBeTrue();

    $this->connection->rollback();
    expect($this->observer->locked(5))->toBeFalse()
        ->and($this->observer->committed())->toBe([5]);
});
test('begin costs no round trip and commit and rollback one each', function (): void {
    $sid = sid($this->connection);

    expect($this->counter->during($sid, $this->connection->begin(...)))->toBe(0);
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    expect($this->counter->during($sid, $this->connection->commit(...)))->toBe(1);
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (2)');

    expect($this->counter->during($sid, $this->connection->rollback(...)))->toBe(1)
        ->and($this->observer->committed())->toBe([1]);
});
/**
 * @return iterable<string, array{0: Closure(Connection): void, 1: string, 2?: int}>
 */
dataset('misuses', function () {
    yield 'commit() outside a transaction' => [
        static fn(Connection $c) => $c->commit(),
        'There is no transaction to commit',
    ];
    yield 'rollback() outside a transaction' => [
        static fn(Connection $c) => $c->rollback(),
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
            $c->transaction(static fn(): null => null);
        },
        'Transactions do not nest',
    ];
    yield 'begin() inside transaction()' => [
        static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->begin()),
        'Transactions do not nest',
        1,
    ];
    yield 'transaction() inside transaction()' => [
        static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->transaction(static fn(): null => null)),
        'Transactions do not nest',
        1,
    ];
    yield 'commit() inside transaction()' => [
        static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->commit()),
        'commit() inside transaction()',
        1,
    ];
    yield 'rollback() inside transaction()' => [
        static fn(Connection $c) => $c->transaction(static fn(Connection $c) => $c->rollback()),
        'rollback() inside transaction()',
        1,
    ];
});
test('misusing a transaction verb throws usage exception before any round trip', function (Closure $misuse, string $message, int $trips = 0): void {
    $sid = sid($this->connection);

    $thrown = null;
    $made = $this->counter->during($sid, function () use ($misuse, &$thrown): void {
        try {
            $misuse($this->connection);
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }
    });

    expect($thrown)->toBeInstanceOf(UsageException::class)
        ->and($thrown->getMessage())->toContain($message);

    // Inside transaction(), the misuse reaches the caller through the
    // closure's rollback: that one Round trip is the transaction's.
    expect($made)->toBe($trips);
})->with('misuses');
test('a misuse leaves the transaction state as it was', function (): void {
    expect(fn() => $this->connection->commit())->toThrow(UsageException::class);
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    expect($this->observer->committed())->toBe([1]);

    $this->connection->begin();
    expect(fn() => $this->connection->begin())->toThrow(UsageException::class);
    $this->connection->run('insert into fx_tx_rows (id) values (2)');
    expect($this->observer->committed())->toBe([1]);
    $this->connection->commit();
    expect($this->observer->committed())->toBe([1, 2]);
});
test('transaction returns the closures value and commits', function (): void {
    $result = $this->connection->transaction(function (Connection $connection): string {
        expect($connection)->toBe($this->connection);
        $connection->run('insert into fx_tx_rows (id) values (1)');
        $connection->procedure('fx_tx.add_row', ['p_id' => 2]);

        expect($this->observer->committed())->toBe([]);

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($this->observer->committed())->toBe([1, 2]);

    $this->connection->run('insert into fx_tx_rows (id) values (3)');
    expect($this->observer->committed())->toBe([1, 2, 3]);
});
test('transaction rolls back and rethrows the same instance', function (): void {
    $thrown = new DomainException('the closure failed');
    $caught = null;

    try {
        $this->connection->transaction(static function (Connection $connection) use ($thrown): never {
            $connection->run('insert into fx_tx_rows (id) values (1)');

            throw $thrown;
        });
    } catch (DomainException $domainException) {
        $caught = $domainException;
    }

    expect($caught)->toBe($thrown)
        ->and($thrown->getPrevious())->toBeNull()
        ->and($this->connection->select('select count(*) from fx_tx_rows')->value())->toBe(0);

    $this->connection->run('insert into fx_tx_rows (id) values (2)');
    expect($this->observer->committed())->toBe([2]);
});
test('transaction rolls back on an error too', function (): void {
    $caught = 'nothing';

    try {
        $this->connection->transaction(static function (Connection $connection): never {
            $connection->run('insert into fx_tx_rows (id) values (1)');

            throw new TypeError('not an exception');
        });
    } catch (TypeError $typeError) {
        $caught = $typeError->getMessage();
    }

    expect($caught)->toBe('not an exception')
        ->and($this->connection->select('select count(*) from fx_tx_rows')->value())->toBe(0);
});
test('a failed rollback becomes previous of the rethrown exception', function (): void {
    $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(standalone: true));
    $thrown = new DomainException('the closure failed');
    $caught = null;

    try {
        $connection->transaction(function (Connection $connection) use ($thrown): void {
            $connection->run('insert into fx_tx_rows (id) values (1)');
            kill($connection, $this->observer);
            try {
                $connection->run('select 1 from dual');
            } catch (ConnectionLostException) {
            }

            throw $thrown;
        });
    } catch (DomainException $domainException) {
        $caught = $domainException;
    } finally {
        $connection->close();
    }

    expect($caught)->toBe($thrown)
        ->and($thrown->getPrevious())->toBeInstanceOf(ConnectionLostException::class)
        ->and($this->observer->committed())->toBe([]);
});
test('a closure that closes the connection rethrows with nothing chained', function (): void {
    $thrown = new DomainException('the closure failed');
    $caught = null;

    try {
        $this->connection->transaction(static function (Connection $connection) use ($thrown): never {
            $connection->run('insert into fx_tx_rows (id) values (1)');
            $connection->close();

            throw $thrown;
        });
    } catch (DomainException $domainException) {
        $caught = $domainException;
    }

    expect($caught)->toBe($thrown)
        ->and($thrown->getPrevious())->toBeNull()
        ->and($this->observer->committed())->toBe([]);
});
test('a failed commit throws and the connection is back in autocommit', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    $this->connection->run('insert into fx_tx_rows (id, qty) values (2, -1)');

    try {
        $this->connection->commit();
        self::fail('The commit did not fail.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(2091);
    }

    expect($this->observer->committed())->toBe([]);
    $this->connection->run('insert into fx_tx_rows (id) values (3)');
    expect($this->observer->committed())->toBe([3]);
});
test('a failed commit in transaction propagates and the connection is back in autocommit', function (): void {
    try {
        $this->connection->transaction(static function (Connection $connection): void {
            $connection->run('insert into fx_tx_rows (id) values (1)');
            $connection->run('insert into fx_tx_rows (id, qty) values (2, -1)');
        });
        self::fail('The commit did not fail.');
    } catch (QueryException $queryException) {
        expect($queryException->getCode())->toBe(2091);
    }

    expect($this->observer->committed())->toBe([]);
    $this->connection->run('insert into fx_tx_rows (id) values (3)');
    expect($this->observer->committed())->toBe([3]);
});
test('rollback to a savepoint through run undoes only the later work', function (): void {
    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');
    $this->connection->run('savepoint before_two');
    $this->connection->run('insert into fx_tx_rows (id) values (2)');
    $this->connection->run('rollback to savepoint before_two');
    $this->connection->run('insert into fx_tx_rows (id) values (3)');

    expect($this->observer->committed())->toBe([]);

    $this->connection->commit();

    expect($this->observer->committed())->toBe([1, 3]);
});
test('destroying or closing the connection inside begin rolls back', function (): void {
    $this->connection->run("insert into fx_tx_rows (id, note) values (1, 'committed')");

    $destroyed = connect();
    $destroyed->begin();
    $destroyed->run("update fx_tx_rows set note = 'destroyed' where id = 1");
    $destroyed->run('insert into fx_tx_rows (id) values (2)');

    expect($this->observer->locked(1))->toBeTrue();
    unset($destroyed);

    expect($this->observer->locked(1))->toBeFalse()
        ->and($this->observer->select('select id, note from fx_tx_rows'))->toBe([['ID' => '1', 'NOTE' => 'committed']]);

    $closed = connect();
    $closed->begin();
    $closed->run("update fx_tx_rows set note = 'closed' where id = 1");
    $closed->close();

    expect($this->observer->locked(1))->toBeFalse()
        ->and($this->observer->select('select id, note from fx_tx_rows'))->toBe([['ID' => '1', 'NOTE' => 'committed']]);
});
test('the transaction verbs fail after close', function (): void {
    $this->connection->close();

    foreach (['begin', 'commit', 'rollback'] as $verb) {
        try {
            $this->connection->$verb();
            self::fail("$verb() after close() did not fail.");
        } catch (UsageException $e) {
            expect($e->getMessage())->toBe('The Connection is closed.', $verb);
        }
    }
    expect(fn() => $this->connection->transaction(static fn(): never => self::fail('The closure ran.')))->toThrow(UsageException::class);
});
function kill(Connection $connection, TransactionObserver $observer): void
{
    $sid = sid($connection);
    $serial = $observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $sid])[0]['SERIAL'];
    $observer->execute("alter system kill session '$sid,$serial' immediate");
}
function sid(Connection $connection): int
{
    $sid = $connection->select("select sys_context('userenv', 'sid') from dual")->value();
    expect($sid)->toBeString();

    return (int) $sid;
}
function connect(): Connection
{
    return Oracle::connect(TestDatabase::fromEnvironment()->config());
}
