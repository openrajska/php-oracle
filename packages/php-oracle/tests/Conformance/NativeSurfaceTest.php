<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\NativeSurfaceTest;

use Rajska\Oracle\Connection;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Driver\Extension\ExtensionConnection;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Extension\Connection as NativeConnection;
use Rajska\Oracle\Extension\Cursor as NativeCursor;
use Rajska\Oracle\Extension\Exception as NativeException;
use Rajska\Oracle\Extension\Lob as NativeLob;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Throwable;
use TypeError;

beforeEach(function (): void {
    $this->natives = [];
});
afterEach(function (): void {
    foreach ($this->natives as $native) {
        $native->close();
    }
    $this->natives = [];
});
test('every native class is final and cannot be created cloned or serialized', function (): void {
    $connection = native();
    $cursor = $connection->execute('select 1 from dual', [], nativeOptions())['cursor'];
    expect($cursor)->toBeInstanceOf(NativeCursor::class);
    $lob = $connection->execute("select to_clob('x') as c from dual", [], nativeOptions())['cursor']?->fetchBatch()[0]['C'] ?? null;
    expect($lob)->toBeInstanceOf(NativeLob::class);
    $exception = nativeException(fn() => $connection->execute('select 1 from dual', [], ['unknown' => 1] + nativeOptions()));

    foreach ([$connection, $cursor, $lob, $exception] as $object) {
        $class = $object::class;
        expect(new ReflectionClass($class)->isFinal())->toBeTrue("$class is final");
        assertFails(static fn(): NativeCursor | NativeException | NativeLob | \Rajska\Oracle\Extension\Connection => new $class(), "new $class");
        assertFails(static fn(): NativeCursor | NativeException | NativeLob | \Rajska\Oracle\Extension\Connection => clone $object, "clone $class");
        assertFails(static fn(): string => serialize($object), "serialize($class)");
        assertFails(static fn(): mixed => unserialize(\sprintf('O:%d:"%s":0:{}', \strlen($class), $class)), "unserialize($class)");
    }
});
/**
 * @return iterable<string, array{class-string, string, string}>
 */
dataset('sensitiveParameters', function () {
    yield 'Connection::run() $binds' => [Connection::class, 'run', 'binds'];
    yield 'Connection::select() $binds' => [Connection::class, 'select', 'binds'];
    yield 'DriverConnection::execute() $binds' => [DriverConnection::class, 'execute', 'binds'];
    yield 'DriverConnection::executeMany() $rows' => [DriverConnection::class, 'executeMany', 'rows'];
    yield 'ExtensionConnection::execute() $binds' => [ExtensionConnection::class, 'execute', 'binds'];
    yield 'ExtensionConnection::executeMany() $rows' => [ExtensionConnection::class, 'executeMany', 'rows'];
    yield 'native Connection::connect() $pool' => [NativeConnection::class, 'connect', 'pool'];
    yield 'native Connection::execute() $binds' => [NativeConnection::class, 'execute', 'binds'];
    yield 'native Connection::executeMany() $rows' => [NativeConnection::class, 'executeMany', 'rows'];
});
test('pool binds and rows are sensitive parameters', function (string $class, string $method, string $parameter): void {
    $parameters = array_filter(
        new ReflectionMethod($class, $method)->getParameters(),
        static fn(ReflectionParameter $p): bool => $p->getName() === $parameter,
    );

    expect($parameters)->toHaveCount(1, "$class::$method() has \$$parameter")
        ->and(array_values($parameters)[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with('sensitiveParameters');
test('native traces hide pool binds and rows', function (): void {
    $distinctive = 'distinctive-bound-value-35';

    $native = native();
    $secret = ['secret' => $distinctive];

    // [the call, its method, the position of the sensitive argument];
    // each passes a string for an array on purpose.
    $calls = [
        // @phpstan-ignore argument.type, argument.type
        [static fn() => NativeConnection::connect($secret, 'not an array'), 'connect', 0],
        // @phpstan-ignore argument.type
        [static fn() => $native->execute('select 1 from dual', $secret, 'not an array'), 'execute', 1],
        // @phpstan-ignore argument.type
        [static fn() => $native->executeMany('select 1 from dual', [$secret], 'not an array'), 'executeMany', 1],
    ];

    foreach ($calls as [$call, $method, $position]) {
        try {
            $call();
            self::fail("$method() accepted a string for an array.");
        } catch (TypeError $e) {
            $frame = $e->getTrace()[0];
            expect([$frame['class'] ?? null, $frame['function']])->toBe([NativeConnection::class, $method])
                ->and($frame['args'][$position] ?? null)->toBeInstanceOf(SensitiveParameterValue::class, $method)
                ->and($e->getTraceAsString())->not->toContain($distinctive);
        }
    }
});
test('an unknown key in any array fails with kind usage before any round trip', function (): void {
    $database = TestDatabase::fromEnvironment();

    // The arrays break the documented shapes on purpose.
    // @phpstan-ignore argument.type, argument.type
    $connect = fn(array $pool, array $session) => NativeConnection::connect($pool, $session);

    foreach ([
        '$pool' => fn() => $connect(['unknown' => 1] + nativePool($database), nativeSession()),
        '$session' => fn() => $connect(nativePool($database), ['unknown' => 1] + nativeSession()),
        '$session[tracing]' => fn() => $connect(
            nativePool($database),
            ['tracing' => ['unknown' => 1] + nativeSession()['tracing']] + nativeSession(),
        ),
    ] as $array => $call) {
        expect(nativeException($call)->kind)->toBe('usage', "an unknown key in $array");
    }

    $native = native();
    $rows = $native->execute("select sys_context('userenv', 'sid') as sid from dual", [], nativeOptions())['cursor']?->fetchBatch();
    $sid = $rows[0]['SID'] ?? null;
    expect($sid)->toBeString();
    $sid = (int) $sid;
    $counter = RoundTripCounter::open();

    try {
        expect($counter->during($sid, function () use ($native): void {
            $exception = nativeException(fn() => $native->execute(
                'select 1 from dual',
                [],
                ['unknown' => 1] + nativeOptions(),
            ));
            expect($exception->kind)->toBe('usage', 'an unknown key in $options');
            $exception = nativeException(fn() => $native->trace(
                ['unknown' => 1] + nativeSession()['tracing'],
            ));
            expect($exception->kind)->toBe('usage', 'an unknown key in $tracing');
        }))->toBe(0);
    } finally {
        $counter->close();
    }
});
test('each kind translates to its core class keeping code message and offset', function (): void {
    $driver = $this->connect();
    $native = native();
    $wrongPassword = TestDatabase::fromEnvironment()->config(password: 'not-the-password');
    $duplicate = "insert into fx_items (id, category_id, name, price, created_at) values (1, 1, 'duplicate', 1, sysdate)";
    $cpuBound = 'select count(*) from fx_bench_rows a, fx_bench_rows b';

    $cases = [
        'database' => [
            QueryException::class,
            fn() => $native->execute('select 1 fro dual', [], nativeOptions()),
            fn(): DriverExecution => $driver->execute('select 1 fro dual', [], self::options()),
        ],
        'database, ORA-00001' => [
            UniqueViolationException::class,
            fn() => $native->execute($duplicate, [], nativeOptions()),
            fn(): DriverExecution => $driver->execute($duplicate, [], self::options()),
        ],
        'connection' => [
            ConnectionException::class,
            fn() => NativeConnection::connect(['password' => 'not-the-password'] + nativePool(TestDatabase::fromEnvironment()), nativeSession()),
            fn(): DriverConnection => $this->driver()->connect($wrongPassword),
        ],
        'timeout' => [
            TimeoutException::class,
            fn() => $native->execute($cpuBound, [], ['timeout' => 500] + nativeOptions())['cursor']?->fetchBatch(),
            fn(): array | null => $driver->execute($cpuBound, [], self::options(timeout: 0.5))->cursor()?->fetchBatch(),
        ],
        'unsupported' => [
            UnsupportedException::class,
            fn() => $native->execute("select bfilename('NO_SUCH_DIRECTORY', 'x') from dual", [], nativeOptions())['cursor']?->fetchBatch(),
            fn(): array | null => $driver->execute("select bfilename('NO_SUCH_DIRECTORY', 'x') from dual", [], self::options())->cursor()?->fetchBatch(),
        ],
        'usage' => [
            UsageException::class,
            static function () use ($native): void {
                $cursor = $native->execute('select 1 from dual', [], nativeOptions())['cursor'];
                $cursor?->close();
                $cursor?->fetchBatch();
            },
            static function () use ($driver): void {
                $cursor = $driver->execute('select 1 from dual', [], self::options())->cursor();
                $cursor?->close();
                $cursor?->fetchBatch();
            },
        ],
    ];

    foreach ($cases as $case => [$class, $nativeCall, $driverCall]) {
        $expected = nativeException($nativeCall);

        try {
            $driverCall();
            self::fail("$case: no exception");
        } catch (OracleException $e) {
            expect($e::class)->toBe($class, $case)
                ->and($e->getCode())->toBe($expected->getCode(), "$case: code")
                ->and($e->getMessage())->toBe($expected->getMessage(), "$case: message");
            if ($e instanceof QueryException) {
                expect($e->offset())->toBe($expected->offset, "$case: offset");
            }
            expect($e->getPrevious())->toBeNull("$case: previous");
        }
    }
});
test('the session array is reapplied on every acquire', function (): void {
    $pool = ['maxConnections' => 1, 'connectTimeout' => 20_061] + nativePool(TestDatabase::fromEnvironment());
    $sql = <<<'SQL'
            select sys_context('userenv', 'sid') sid,
                   sys_context('userenv', 'module') module,
                   sys_context('userenv', 'action') action,
                   sys_context('userenv', 'client_identifier') client_identifier,
                   sys_context('userenv', 'client_info') client_info
              from dual
            SQL;
    $sessions = [];
    foreach (['A', 'B'] as $name) {
        $connection = NativeConnection::connect($pool, [
            'tracing' => ['module' => "module $name", 'action' => "action $name", 'clientIdentifier' => "id $name", 'clientInfo' => "info $name"],
            'callTimeout' => 60_000,
        ]);
        $row = $connection->execute($sql, [], nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
        $connection->close();
        $sessions[] = array_shift($row);
        expect($row)->toBe([
            'MODULE' => "module $name",
            'ACTION' => "action $name",
            'CLIENT_IDENTIFIER' => "id $name",
            'CLIENT_INFO' => "info $name",
        ]);
    }
    expect($sessions[1])->toBe($sessions[0], 'One Session for both');
});
/**
 * @return iterable<string, array{string, int}>
 */
dataset('nonPositiveDurations', function () {
    foreach ([0, -1] as $ms) {
        yield "\$session callTimeout $ms" => ['callTimeout', $ms];
        yield "\$options timeout $ms" => ['timeout', $ms];
    }
});
test('a call timeout under 1 ms fails with kind usage', function (string $key, int $ms): void {
    $native = native();
    $exception = nativeException(static fn() => $key === 'callTimeout'
        ? NativeConnection::connect(nativePool(TestDatabase::fromEnvironment()), ['callTimeout' => $ms] + nativeSession())
        : $native->execute('select 1 from dual', [], ['timeout' => $ms] + nativeOptions()));

    expect($exception->kind)->toBe('usage')
        ->and($exception->getMessage())->toContain("'$key'");
})->with('nonPositiveDurations');
test('after close its lobs fail with kind usage and the sessions next owner is unaffected', function (): void {
    $pool = ['maxConnections' => 1, 'connectTimeout' => 20_051] + nativePool(TestDatabase::fromEnvironment());
    $sql = "select to_clob('x') as c, to_blob(hextoraw('ff')) as b from dual";
    $orphans = [];
    for ($i = 0; $i < 1000; ++$i) {
        $connection = NativeConnection::connect($pool, nativeSession());
        $row = $connection->execute($sql, [], nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
        expect($row['C'] ?? null)->toBeInstanceOf(NativeLob::class)
            ->and($row['B'] ?? null)->toBeInstanceOf(NativeLob::class)
            ->and([$row['C']->type(), $row['B']->type()])->toBe(['clob', 'blob']);
        $connection->close();
        $orphans[] = $row;
    }

    $lob = $orphans[999]['C'];
    foreach (['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)] as $call => $read) {
        $e = nativeException($read);
        expect($e->kind)->toBe('usage', $call)
            ->and($e->getMessage())->toBe('The Connection is closed.', $call);
    }
    $lob->close();

    $next = NativeConnection::connect($pool, nativeSession());
    $this->natives[] = $next;
    $row = $next->execute($sql, [], nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
    unset($lob, $orphans);
    expect($row['C'] ?? null)->toBeInstanceOf(NativeLob::class)
        ->and($row['C']->readChunk())->toBe('x');

    $temporary = $next->execute(
        'select count(*) as n from v$temporary_lobs t join v$session s on s.sid = t.sid where s.audsid = userenv(\'sessionid\') and t.cache_lobs + t.nocache_lobs + t.abstract_lobs > 2',
        [],
        nativeOptions(),
    );
    expect($temporary['cursor']?->fetchBatch())->toBe([['N' => 0]], 'No temporary LOB of the earlier owners is left');
});
test('after close its cursors fail with kind usage and the sessions next owner is unaffected', function (): void {
    $pool = ['maxConnections' => 1, 'connectTimeout' => 20_040] + nativePool(TestDatabase::fromEnvironment());
    $options = ['fetchSize' => 1] + nativeOptions();
    $sql = 'select level as n from dual connect by level <= 3';
    $orphans = [];
    for ($i = 0; $i < 1000; ++$i) {
        $connection = NativeConnection::connect($pool, nativeSession());
        $cursor = $connection->execute($sql, [], $options)['cursor'];
        expect($cursor)->toBeInstanceOf(NativeCursor::class)
            ->and($cursor->fetchBatch())->toBe([['N' => 1]]);
        $connection->close();
        $orphans[] = $cursor;
    }

    foreach (['fetchBatch()' => static fn() => $cursor->fetchBatch(), 'columns()' => static fn() => $cursor->columns()] as $call => $read) {
        $e = nativeException($read);
        expect($e->kind)->toBe('usage', $call)
            ->and($e->getMessage())->toBe('The Connection is closed.', $call);
    }
    $cursor->close();

    $next = NativeConnection::connect($pool, nativeSession());
    $this->natives[] = $next;
    $reading = $next->execute($sql, [], $options)['cursor'];
    expect($reading)->toBeInstanceOf(NativeCursor::class)
        ->and($reading->fetchBatch())->toBe([['N' => 1]]);
    unset($cursor, $orphans);
    expect($reading->fetchBatch())->toBe([['N' => 2]])
        ->and($reading->fetchBatch())->toBe([['N' => 3]])
        ->and($reading->fetchBatch())->toBe([]);
});
test('cores dist archive has no stubs', function (): void {
    $package = \dirname(__DIR__, 2);
    [$status, $paths] = command(['git', '-C', $package, 'rev-parse', '--show-toplevel', '--show-prefix']);
    if ($status !== 0) {
        self::markTestSkipped("No git repository here: $paths");
    }
    [$root, $prefix] = explode("\n", trim($paths)) + ['', ''];
    $archive = tempnam(sys_get_temp_dir(), 'core-dist');
    expect($archive)->toBeString();

    try {
        [$status, $output] = command(['git', '-C', $root, 'archive', '--format=tar', '-o', $archive, 'HEAD:' . rtrim($prefix, '/')]);
        expect($status)->toBe(0, $output);
        [$status, $listing] = command(['tar', '-tf', $archive]);
        expect($status)->toBe(0, $listing);
    } finally {
        unlink($archive);
    }
    $entries = explode("\n", trim($listing));

    expect($entries)->toContain('src/Oracle.php')
        ->and(array_values(array_filter($entries, static fn(string $entry): bool => str_starts_with($entry, 'stubs'))))->toBeEmpty();
});
/**
 * @param list<string> $command
 *
 * @return array{int, string} the exit status and the output (stdout, then stderr)
 */
function command(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}
function native(): NativeConnection
{
    $native = NativeConnection::connect(nativePool(TestDatabase::fromEnvironment()), nativeSession());
    $test = test();
    $test->natives = [...$test->natives, $native];

    return $native;
}
function nativeException(callable $call): NativeException
{
    try {
        $call();
    } catch (NativeException $nativeException) {
        return $nativeException;
    }
    throw new RuntimeException('The native call did not throw.');
}
function assertFails(callable $call, string $what): void
{
    try {
        $call();
    } catch (Throwable) {
        test()->addToAssertionCount(1);

        return;
    }
    throw new RuntimeException("$what succeeded.");
}
/**
 * @return array{dsn: string, user: string, password: string, proxyUser: null, walletLocation: null, walletPassword: null, configDir: null, connectTimeout: int, drcpConnectionClass: null, maxConnections: int, acquireTimeout: int, statementCacheSize: int, standalone: bool}
 */
function nativePool(TestDatabase $database): array
{
    return [
        'dsn' => $database->connectString,
        'user' => $database->user,
        'password' => $database->password,
        'proxyUser' => null,
        'walletLocation' => null,
        'walletPassword' => null,
        'configDir' => null,
        'connectTimeout' => 20000,
        'drcpConnectionClass' => null,
        'maxConnections' => 16,
        'acquireTimeout' => 5000,
        'statementCacheSize' => 20,
        'standalone' => false,
    ];
}
/**
 * @return array{tracing: array{module: null, action: null, clientIdentifier: null, clientInfo: null}, callTimeout: null}
 */
function nativeSession(): array
{
    return [
        'tracing' => ['module' => null, 'action' => null, 'clientIdentifier' => null, 'clientInfo' => null],
        'callTimeout' => null,
    ];
}
/**
 * @return array{autocommit: bool, timeout: null, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}
 */
function nativeOptions(): array
{
    return [
        'autocommit' => false,
        'timeout' => null,
        'fetchSize' => 100,
        'numbers' => 'exact',
        'temporals' => 'object',
        'lobs' => 'locator',
        'rowShape' => 'assoc',
        'keyCase' => 'native',
    ];
}
