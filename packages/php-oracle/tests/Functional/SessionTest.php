<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\SessionTest;

use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Tests\Functional\TestCase;
use Rajska\Oracle\Tracing;

uses(TestCase::class);

const CPU_BOUND = 'select count(*) from session_rows a, session_rows b';

const ONE_SECOND = 'begin session_sleep(1); end;';

beforeEach(function (): void {
    schema()->run('create table session_rows (id number(10) primary key)');
    schema()->run('insert into session_rows (id) select level from dual connect by level <= 10000');
    schema()->run(<<<'SQL'
        create procedure session_sleep(p_seconds number) is
        begin
            dbms_session.sleep(p_seconds);
        end;
        SQL);
});

test('trace replaces the whole tracing the session reports', function (): void {
    $connection = $this->connect(tracing: new Tracing(module: 'billing', action: 'close month'));

    $connection->trace(new Tracing(module: 'reports', clientIdentifier: 'user-42'));

    expect(tracing($connection))->toBe(['reports', null, 'user-42']);
});

test('an execute options timeout overrides the config call timeout', function (): void {
    $connection = $this->connect(callTimeout: 0.5);

    $run = fn (): Execution => $connection->run(ONE_SECOND, [], new Options(timeout: 5));

    expect($run)->not->toThrow(TimeoutException::class);
});

test('the config call timeout applies again after an execution that overrode it', function (): void {
    $connection = $this->connect(callTimeout: 0.5);
    $connection->run(ONE_SECOND, [], new Options(timeout: 5));

    $query = fn (): mixed => $connection->select(CPU_BOUND)->value();

    expect($query)->toThrow(TimeoutException::class);
});

test('a new acquire uses its own configs call timeout', function (): void {
    $pool = ['maxConnections' => 1, 'connectTimeout' => 30.061];
    $first = $this->connect(...$pool, callTimeout: 5);
    $first->run(ONE_SECOND);
    $first->close();

    $second = $this->connect(...$pool, callTimeout: 0.5);

    $query = fn (): mixed => $second->select(CPU_BOUND)->value();

    expect($query)->toThrow(TimeoutException::class);
});

/**
 * Module, action and client identifier, as the Session reports them.
 *
 * @return list<mixed>
 */
function tracing(Connection $connection): array
{
    return array_values($connection->select(<<<'SQL'
        select sys_context('userenv', 'module'),
               sys_context('userenv', 'action'),
               sys_context('userenv', 'client_identifier')
          from dual
        SQL)->first() ?? []);
}
