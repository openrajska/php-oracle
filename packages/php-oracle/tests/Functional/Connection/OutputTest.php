<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Connection\OutputTest;

use Closure;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run('create table output_rows (id number(10) primary key, note varchar2(100))');
});

test('output reads every line in order and then nothing', function (): void {
    $connection = $this->connect();
    $connection->enableOutput();
    $connection->run("begin for i in 1 .. 2500 loop dbms_output.put_line('line ' || i); end loop; end;");

    $lines = $connection->output();

    expect($lines)->toBe(array_map(static fn (int $i): string => "line $i", range(1, 2500)))
        ->and($connection->output())->toBeEmpty();
});

test('reading 2500 lines costs three round trips', function (): void {
    $connection = $this->connect();
    $sid = $this->sid($connection);
    $connection->enableOutput();
    $connection->output();
    $connection->run("begin for i in 1 .. 2500 loop dbms_output.put_line('line ' || i); end loop; end;");

    $trips = roundTrips($sid, $connection->output(...));

    expect($trips)->toBe(3);
});

test('lines come back unchanged', function (): void {
    $connection = $this->connect();
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

    $lines = $connection->output();

    expect($lines)->toBe([str_repeat('x', 32767), '', '  padded  ', 'ação €', 'in parts']);
});

/**
 * @return iterable<string, array{Closure(Connection): mixed}>
 */
dataset('outputOff', function () {
    yield 'never enabled' => [static fn (Connection $connection): null => null];
    yield 'disabled after it was enabled' => [static function (Connection $connection): void {
        $connection->enableOutput();
        $connection->run("begin dbms_output.put_line('unread'); end;");
        $connection->disableOutput();
    }];
});

test('without output enabled output is empty', function (Closure $setUp): void {
    $connection = disabled($this->connect(...pooled()));
    $setUp($connection);
    $connection->run("begin dbms_output.put_line('lost'); end;");

    $lines = $connection->output();

    expect($lines)->toBeEmpty();
})->with('outputOff');

test('output enabled through raw sql is read', function (): void {
    $connection = $this->connect();
    $connection->run("begin dbms_output.enable(null); dbms_output.put_line('raw'); end;");

    $lines = $connection->output();

    expect($lines)->toBe(['raw']);
});

test('enable output starts from an empty buffer', function (): void {
    $connection = $this->connect();
    $connection->run("begin dbms_output.enable(null); dbms_output.put_line('before'); end;");

    $connection->enableOutput();
    $connection->run("begin dbms_output.put_line('after'); end;");

    expect($connection->output())->toBe(['after']);
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
    $first = disabled(Oracle::connect($this->config(...pooled())));
    $session = session($first);
    $first->enableOutput();
    $first->run("begin dbms_output.put_line('secret of A'); end;");

    release($first, $close);

    $second = $this->connect(...pooled());
    if ($enable) {
        $second->enableOutput();
    }
    $second->run("begin dbms_output.put_line('of B'); end;");
    expect(session($second))->toBe($session, "B has A's Session.")
        ->and($second->output())->toBe($enable ? ['of B'] : []);
})->with('releasesAndNextUsers');

test('output is purged when a connection is dropped before its cursor', function (): void {
    $first = disabled(Oracle::connect($this->config(...pooled())));
    $session = session($first);
    $first->enableOutput();
    $first->run("begin dbms_output.put_line('secret of A'); end;");

    $cursor = $first->select('select level n from dual connect by level <= 3', [], new Options(fetchSize: 1));
    foreach ($cursor as $row) {
        break;
    }

    unset($first);
    $rest = $cursor->all();
    unset($cursor);

    $second = $this->connect(...pooled());
    expect($rest)->toBe([['N' => 2], ['N' => 3]])
        ->and(session($second))->toBe($session, "B has A's Session.")
        ->and($second->output())->toBeEmpty();
});

test('output enabled through raw sql stays with the session', function (): void {
    $first = disabled(Oracle::connect($this->config(...pooled())));
    $first->run("begin dbms_output.enable(null); dbms_output.put_line('left by A'); end;");

    $first->close();

    expect($this->connect(...pooled())->output())->toBe(['left by A']);
});

test('on a killed session output throws connection lost', function (): void {
    $connection = $this->connect(standalone: true);
    $connection->enableOutput();
    kill($this->sid($connection));

    $read = $connection->output(...);

    expect($read)->toThrow(ConnectionLostException::class);
});

test('releasing a killed session with output enabled stays silent', function (bool $close): void {
    $connection = Oracle::connect($this->config(standalone: true));
    $connection->enableOutput();
    kill($this->sid($connection));
    expect(fn (): array => $connection->output())->toThrow(ConnectionLostException::class);

    release($connection, $close);

    $this->addToAssertionCount(1);
})->with(['close()' => [true], 'dropped' => [false]]);

test('output calls never commit an open transaction', function (): void {
    schema()->run("insert into output_rows (id, note) values (1, 'committed')");
    $connection = $this->connect();
    $connection->begin();
    $connection->run("update output_rows set note = 'uncommitted' where id = 1");

    $connection->enableOutput();
    $connection->run("begin dbms_output.put_line('in a transaction'); end;");
    $connection->output();
    $connection->disableOutput();
    $connection->enableOutput();

    expect(otherSession()->select('select note from output_rows where id = 1')->value())->toBe('committed')
        ->and(locked('output_rows', 1))->toBeTrue('The transaction is still open.');
});

test('releasing a connection with output enabled rolls its transaction back', function (bool $close): void {
    schema()->run("insert into output_rows (id, note) values (1, 'committed')");
    $connection = Oracle::connect($this->config());
    $connection->begin();
    $connection->run("update output_rows set note = 'uncommitted' where id = 1");
    $connection->enableOutput();

    release($connection, $close);

    expect(otherSession()->select('select note from output_rows where id = 1')->value())->toBe('committed')
        ->and(locked('output_rows', 1))->toBeFalse();
})->with(['close()' => [true], 'dropped' => [false]]);

/**
 * Config fields of a Pool only these tests use, one Session big, so each
 * Connection gets the Session the last one released.
 *
 * @return array{maxConnections: int, connectTimeout: float}
 */
function pooled(): array
{
    return ['standalone' => false, 'maxConnections' => 1, 'connectTimeout' => 30.062];
}

/**
 * The Connection, its Session's DBMS_OUTPUT off and empty whatever an earlier
 * test left in it.
 */
function disabled(Connection $connection): Connection
{
    $connection->run('begin dbms_output.disable; end;');

    return $connection;
}

/**
 * Releases the Connection with close(), or by dropping its last reference.
 *
 * @param-out null $connection
 */
function release(Connection | null &$connection, bool $close): void
{
    if ($close) {
        $connection?->close();
    }
    $connection = null;
}

/**
 * Which Session is behind a Connection: its AUDSID, unique per logon.
 */
function session(Connection $connection): string
{
    $session = $connection->select("select sys_context('userenv', 'sessionid') from dual")->value();
    assert(\is_string($session));

    return $session;
}
