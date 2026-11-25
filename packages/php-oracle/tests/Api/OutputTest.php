<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\OutputTest;

use Closure;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tests\Support\TransactionObserver;

beforeEach(function (): void {
    $this->connections = [];
});
afterEach(function (): void {
    foreach ($this->connections as $connection) {
        $connection->close();
    }
});
test('output gives 2500 lines in order then nothing', function (): void {
    $connection = connect();
    $connection->enableOutput();

    expect($connection->output())->toBeEmpty();
    $connection->run("begin for i in 1 .. 2500 loop dbms_output.put_line('line ' || i); end loop; end;");
    $counter = RoundTripCounter::open();

    try {
        $lines = [];
        expect($counter->during(sid($connection), static function () use ($connection, &$lines): void {
            $lines = $connection->output();
        }))->toBe(3);
    } finally {
        $counter->close();
    }

    expect($lines)->toBe(array_map(static fn(int $i): string => "line $i", range(1, 2500)))
        ->and($connection->output())->toBeEmpty();
});
test('lines come back unchanged', function (): void {
    $connection = connect();
    $connection->enableOutput();
    $connection->run(<<<'SQL'
            begin
              dbms_output.put_line(rpad('x', 32767, 'x'));
              dbms_output.put_line('');
              dbms_output.put_line('  padded  ');
              dbms_output.put_line('ação €');
              dbms_output.put('in ');
              dbms_output.put('parts');
              dbms_output.new_line;
            end;
            SQL);

    expect($connection->output())->toBe([str_repeat('x', 32767), '', '  padded  ', 'ação €', 'in parts']);
});
test('without enable output output is empty', function (): void {
    $connection = connect();
    $connection->run("begin dbms_output.put_line('lost'); end;");

    expect($connection->output())->toBeEmpty();
});
test('output enabled through raw sql is read', function (): void {
    $connection = connect();
    $connection->run("begin dbms_output.enable(null); dbms_output.put_line('raw'); end;");

    expect($connection->output())->toBe(['raw']);
});
test('enable output starts from an empty buffer', function (): void {
    $connection = connect();
    $connection->run("begin dbms_output.enable(null); dbms_output.put_line('before'); end;");

    $connection->enableOutput();
    $connection->run("begin dbms_output.put_line('after'); end;");

    expect($connection->output())->toBe(['after']);
});
test('after disable output output is empty', function (): void {
    $connection = connect();
    $connection->enableOutput();
    $connection->run("begin dbms_output.put_line('unread'); end;");

    $connection->disableOutput();
    $connection->run("begin dbms_output.put_line('lost'); end;");

    expect($connection->output())->toBeEmpty();
});
/**
 * @return iterable<string, array{bool, bool}>
 */
dataset('releasesAndNextUsers', function () {
    yield 'close(), next user enables' => [true, true];
    yield 'close(), next user does not enable' => [true, false];
    yield 'dropped, next user enables' => [false, true];
    yield 'dropped, next user does not enable' => [false, false];
});
test('unread output enabled through the api is purged on release', function (bool $close, bool $enable): void {
    $first = fresh();
    $session = session($first);
    $first->enableOutput();
    $first->run("begin dbms_output.put_line('secret of A'); end;");
    if ($close) {
        $first->close();
    }
    unset($first);

    $second = next();
    expect(session($second))->toBe($session, "B has A's Session.");
    if ($enable) {
        $second->enableOutput();
    }
    $second->run("begin dbms_output.put_line('of B'); end;");

    expect($second->output())->toBe($enable ? ['of B'] : []);
})->with('releasesAndNextUsers');
test('output is purged when a connection is dropped before its cursor', function (): void {
    $first = fresh();
    $session = session($first);
    $first->enableOutput();
    $first->run("begin dbms_output.put_line('secret of A'); end;");

    $cursor = $first->select('select level n from dual connect by level <= 3', [], new ExecuteOptions(fetchSize: 1));
    $rows = [];
    foreach ($cursor as $row) {
        $rows[] = $row;
        break;
    }
    unset($first);

    expect([...$rows, ...$cursor->all()])->toBe([['N' => 1], ['N' => 2], ['N' => 3]]);
    unset($cursor);

    $second = next();
    expect(session($second))->toBe($session, "B has A's Session.")
        ->and($second->output())->toBeEmpty();
});
test('output enabled through raw sql stays with the session', function (): void {
    $first = fresh();
    $first->run("begin dbms_output.enable(null); dbms_output.put_line('left by A'); end;");
    $first->close();

    $second = next();

    expect($second->output())->toBe(['left by A']);
});
test('on a killed session output fails and the release stays silent', function (bool $close): void {
    $observer = IndependentSession::open();
    try {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(standalone: true));
        $connection->enableOutput();
        $sid = sid($connection);
        $serial = $observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $sid])[0]['SERIAL'];
        $observer->execute("alter system kill session '$sid,$serial' immediate");

        expect(function () use ($connection): void {
            $connection->output();
            self::fail('output() read from a killed Session.');
        })->toThrow(ConnectionLostException::class);
        if ($close) {
            $connection->close();
        }
        unset($connection);
    } finally {
        $observer->close();
    }
    $this->addToAssertionCount(1);
})->with('closedOrDropped');
/**
 * @return iterable<string, array{bool}>
 */
dataset('closedOrDropped', function () {
    yield 'close()' => [true];
    yield 'dropped' => [false];
});
test('output calls never commit an open transaction', function (bool $close): void {
    $observer = TransactionObserver::open();
    try {
        $observer->execute("insert into fx_tx_rows (id, note) values (1, 'committed')");
        $note = static fn(): string | null => $observer->select('select note from fx_tx_rows where id = 1')[0]['NOTE'];
        $connection = fresh();
        $connection->begin();
        $connection->run("update fx_tx_rows set note = 'uncommitted' where id = 1");
        $connection->enableOutput();
        $connection->run("begin dbms_output.put_line('in a transaction'); end;");
        expect($connection->output())->toBe(['in a transaction']);
        $connection->disableOutput();
        $connection->enableOutput();
        expect($note())->toBe('committed')
            ->and($observer->locked(1))->toBeTrue('The transaction is still open.');

        if ($close) {
            $connection->close();
        }
        unset($connection);

        expect($note())->toBe('committed')
            ->and($observer->locked(1))->toBeFalse('The Release rolled back.');
    } finally {
        $observer->close();
    }
})->with('closedOrDropped');
/**
 * @return iterable<string, array{Closure(Connection): mixed}>
 */
dataset('outputCalls', function () {
    yield 'enableOutput()' => [static fn(Connection $connection) => $connection->enableOutput()];
    yield 'output()' => [static fn(Connection $connection): array => $connection->output()];
    yield 'disableOutput()' => [static fn(Connection $connection) => $connection->disableOutput()];
});
test('output calls after close fail', function (Closure $call): void {
    $connection = connect();
    $connection->enableOutput();
    $connection->close();

    expect(fn() => $call($connection))->toThrow(UsageException::class, 'The Connection is closed.');
})->with('outputCalls');
/**
 * A Connection from a Pool only these tests use, one Session big, so
 * each Connection gets the Session the last one released. One Pool for
 * the file: a Pool keeps its Session for the whole process. tearDown()
 * does not close it.
 */
function pooled(): Connection
{
    return Oracle::connect(TestDatabase::fromEnvironment()->config(maxConnections: 1, connectTimeout: 30.062));
}
/**
 * A pooled Connection whose Session has DBMS_OUTPUT off and empty,
 * whatever an earlier test left in it. tearDown() does not close it.
 */
function fresh(): Connection
{
    $connection = pooled();
    $connection->run('begin dbms_output.disable; end;');

    return $connection;
}
/**
 * The next user of the Session, as the last Connection left it; closed
 * in tearDown().
 */
function next(): Connection
{
    $connection = pooled();
    $test = test();
    $test->connections = [...$test->connections, $connection];

    return $connection;
}
/**
 * Which Session is behind a Connection: its AUDSID, unique per logon.
 */
function session(Connection $connection): string
{
    $session = $connection->select("select sys_context('userenv', 'sessionid') from dual")->value();
    expect($session)->toBeString();

    return $session;
}
function connect(): Connection
{
    $connection = fresh();
    $test = test();
    $test->connections = [...$test->connections, $connection];

    return $connection;
}
function sid(Connection $connection): int
{
    $sid = $connection->select("select sys_context('userenv', 'sid') from dual")->value();
    expect($sid)->toBeString();

    return (int) $sid;
}
