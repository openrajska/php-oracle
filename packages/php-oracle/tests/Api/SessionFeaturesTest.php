<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\SessionFeaturesTest;

use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tracing;

beforeEach(function (): void {
    $this->connections = [];
});

afterEach(function (): void {
    /** @var list<Connection> $connections */
    $connections = $this->connections;
    foreach ($connections as $connection) {
        $connection->close();
    }
});

test('trace replaces the tracing the session reports', function (): void {
    $connection = connect(tracing: new Tracing(module: 'billing', action: 'close month'));
    expect(tracing($connection))->toBe(['billing', 'close month', null]);

    $connection->trace(new Tracing(module: 'reports', clientIdentifier: 'user-42'));

    expect(tracing($connection))->toBe(['reports', null, 'user-42']);
});

test('trace after close fails', function (): void {
    $connection = connect();
    $connection->close();

    expect(fn() => $connection->trace(new Tracing(module: 'late')))->toThrow(UsageException::class, 'The Connection is closed.');
});

test('the config call timeout throws timeout exception', function (): void {
    $cpuBound = 'select count(*) from fx_bench_rows a, fx_bench_rows b';

    $connection = connect(callTimeout: 0.5);
    expect(fn(): mixed => $connection->select($cpuBound)->value())->toThrow(function (TimeoutException $timeoutException) use ($cpuBound): void {
        expect($timeoutException->getMessage())->toBe('Call timeout of 500 ms exceeded.')
            ->and($timeoutException->sql())->toBe($cpuBound);
    })
        ->and($connection->select('select 1 from dual')->value())->toBe(1);
});

test('the execute options timeout applies to one execution only', function (): void {
    $cpuBound = 'select count(*) from fx_bench_rows a, fx_bench_rows b';
    $oneSecond = 'begin fx_pool.sleep(1); end;';

    $connection = connect(callTimeout: 0.5);

    $connection->run($oneSecond, [], new ExecuteOptions(timeout: 5));

    expect(fn(): mixed => $connection->select($cpuBound)->value())->toThrow(TimeoutException::class);
});

test('a new acquire uses its own configs call timeout', function (): void {
    $cpuBound = 'select count(*) from fx_bench_rows a, fx_bench_rows b';
    $oneSecond = 'begin fx_pool.sleep(1); end;';

    $pool = ['maxConnections' => 1, 'connectTimeout' => 30.061];
    $first = connect(...$pool, callTimeout: 5);
    $first->run($oneSecond);
    $first->close();

    $second = connect(...$pool, callTimeout: 0.5);
    expect(fn(): mixed => $second->select($cpuBound)->value())->toThrow(TimeoutException::class);
});

function connect(mixed ...$fields): Connection
{
    $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(...$fields));
    $test = test();
    $test->connections = [...$test->connections, $connection];

    return $connection;
}

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
