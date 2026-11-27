<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\ExtensionTest;

use Closure;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\ExtensionSession;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Extension;
use Rajska\Oracle\Extension\Cursor;
use Rajska\Oracle\Extension\Exception;
use Rajska\Oracle\Extension\Lob;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Driver\TestCase;
use ReflectionClass;
use RuntimeException;
use SensitiveParameterValue;
use Throwable;

uses(TestCase::class);

const SECRET = 'distinctive-bound-value-35';

beforeAll(function (): void {
    schema()->run('create table native_rows (id number(10) primary key)');
    schema()->run('insert into native_rows select level from dual connect by level <= 10000');
});

beforeEach(function (): void {
    $this->natives = [];
});

afterEach(function (): void {
    /** @var list<Extension\Connection> $natives */
    $natives = $this->natives;
    foreach ($natives as $native) {
        $native->close();
    }
    $this->natives = [];
});

/**
 * Each native class, made from a live native Connection.
 *
 * @return iterable<string, array{Closure(Extension\Connection): object}>
 */
dataset('nativeObjects', function () {
    yield 'Connection' => [static fn (Extension\Connection $c): object => $c];
    yield 'Cursor' => [static fn (Extension\Connection $c): object | null => $c->execute('select 1 from dual', [], nativeOptions())['cursor']];
    yield 'Lob' => [static fn (Extension\Connection $c): mixed => $c->execute("select to_clob('x') as c from dual", [], nativeOptions())['cursor']?->fetchBatch()[0]['C'] ?? null];
    yield 'Exception' => [static fn (Extension\Connection $c): Exception => nativeException(static fn (): array => $c->execute('select 1 from dual', [], ['unknown' => 1] + nativeOptions()))];
});

test('every native class is final and cannot be created cloned or serialized', function (Closure $make): void {
    $object = $make(native());
    assert(\is_object($object));
    $class = $object::class;

    $succeeded = array_filter([
        'new' => succeeds(static fn (): object => new $class()),
        'clone' => succeeds(static fn (): object => clone $object),
        'serialize' => succeeds(static fn (): string => serialize($object)),
        'unserialize' => succeeds(static fn (): mixed => unserialize(\sprintf('O:%d:"%s":0:{}', \strlen($class), $class))),
    ]);

    expect(new ReflectionClass($class)->isFinal())->toBeTrue()
        ->and($succeeded)->toBeEmpty();
})->with('nativeObjects');

/**
 * A call that fails with a secret among its arguments, and the frame that
 * holds it: [class, method, argument position]. The native calls pass a
 * string for an array on purpose; Core's run on a closed Connection.
 *
 * @return iterable<string, array{Closure(Extension\Connection, Connection, ExtensionSession): mixed, class-string, string, int}>
 */
dataset('sensitiveCalls', function () {
    $secret = ['secret' => SECRET];
    // @phpstan-ignore argument.type, argument.type
    yield 'native connect() $pool' => [static fn (): mixed => Extension\Connection::connect($secret, 'not an array'), Extension\Connection::class, 'connect', 0];
    // @phpstan-ignore argument.type
    yield 'native execute() $binds' => [static fn (Extension\Connection $n): mixed => $n->execute('select 1 from dual', $secret, 'not an array'), Extension\Connection::class, 'execute', 1];
    // @phpstan-ignore argument.type
    yield 'native executeMany() $rows' => [static fn (Extension\Connection $n): mixed => $n->executeMany('select 1 from dual', [$secret], 'not an array'), Extension\Connection::class, 'executeMany', 1];
    yield 'Connection::run() $binds' => [static fn (Extension\Connection $n, Connection $c): mixed => $c->run('select 1 from dual', $secret), Connection::class, 'run', 1];
    yield 'Connection::select() $binds' => [static fn (Extension\Connection $n, Connection $c): mixed => $c->select('select 1 from dual', $secret), Connection::class, 'select', 1];
    yield 'Session::execute() $binds' => [static fn (Extension\Connection $n, Connection $c, ExtensionSession $s): mixed => $s->execute('select 1 from dual', $secret, TestCase::options()), ExtensionSession::class, 'execute', 1];
    yield 'Session::executeMany() $rows' => [static fn (Extension\Connection $n, Connection $c, ExtensionSession $s): mixed => $s->executeMany('select 1 from dual', [$secret], TestCase::options()), ExtensionSession::class, 'executeMany', 1];
});

test('a trace hides pool binds and rows', function (Closure $call, string $class, string $method, int $position): void {
    $core = Oracle::connect($this->config());
    $core->close();

    $session = $this->connect();
    assert($session instanceof ExtensionSession);
    $session->close();

    $thrown = thrown(static fn (): mixed => $call(native(), $core, $session));

    $frame = array_values(array_filter($thrown->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === $class && $frame['function'] === $method))[0] ?? [];
    expect($frame['args'][$position] ?? null)->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($thrown->getTraceAsString())->not->toContain(SECRET);
})->with('sensitiveCalls');

test('an unknown key in a connect array fails with kind usage', function (Closure $arrays): void {
    /** @var array{array<string, mixed>, array<string, mixed>} $arrays */
    $arrays = $arrays(nativePool($this->config()), nativeSession());
    [$pool, $session] = $arrays;

    // @phpstan-ignore argument.type, argument.type (the arrays break the documented shapes on purpose)
    $exception = nativeException(static fn (): Extension\Connection => Extension\Connection::connect($pool, $session));

    expect($exception->kind)->toBe('usage');
})->with([
    '$pool' => [static fn (array $pool, array $session): array => [['unknown' => 1] + $pool, $session]],
    '$session' => [static fn (array $pool, array $session): array => [$pool, ['unknown' => 1] + $session]],
    '$session[tracing]' => [static fn (array $pool, array $session): array => [$pool, ['tracing' => ['unknown' => 1] + nativeSession()['tracing']] + $session]],
]);

test('an unknown key in an execute or trace array fails with kind usage before any round trip', function (Closure $call): void {
    $native = native();
    $sid = $native->execute("select sys_context('userenv', 'sid') as sid from dual", [], nativeOptions())['cursor']?->fetchBatch()[0]['SID'] ?? null;
    assert(\is_string($sid));
    $exception = null;

    $trips = $this->roundTrips((int) $sid, static function () use ($call, $native, &$exception): void {
        $exception = nativeException(static fn (): mixed => $call($native));
    });

    expect($exception?->kind)->toBe('usage')
        ->and($trips)->toBe(0);
})->with([
    '$options' => [static fn (Extension\Connection $n): array => $n->execute('select 1 from dual', [], ['unknown' => 1] + nativeOptions())],
    '$tracing' => [static fn (Extension\Connection $n) => $n->trace(['unknown' => 1] + nativeSession()['tracing'])],
]);

/**
 * Each kind of native exception, the Core class it becomes, and the same
 * failure through the native class and through the Driver.
 *
 * @return iterable<string, array{class-string<OracleException>, Closure(Extension\Connection): mixed, Closure(Session, Closure(ConnectionConfig): Session): mixed}>
 */
dataset('kinds', function () {
    $duplicate = 'insert into native_rows (id) values (1)';
    $cpuBound = 'select count(*) from native_rows a, native_rows b';
    $bfile = "select bfilename('NO_SUCH_DIRECTORY', 'x') from dual";
    yield 'database' => [
        QueryException::class,
        static fn (Extension\Connection $n): array => $n->execute('select 1 fro dual', [], nativeOptions()),
        static fn (Session $s): mixed => $s->execute('select 1 fro dual', [], TestCase::options()),
    ];
    yield 'database, ORA-00001' => [
        UniqueViolationException::class,
        static fn (Extension\Connection $n): array => $n->execute($duplicate, [], nativeOptions()),
        static fn (Session $s): mixed => $s->execute($duplicate, [], TestCase::options()),
    ];
    yield 'connection' => [
        ConnectionException::class,
        static fn (): Extension\Connection => Extension\Connection::connect(['password' => 'not-the-password'] + nativePool(TestCase::config()), nativeSession()),
        static fn (Session $s, Closure $connect): mixed => $connect(TestCase::config(password: 'not-the-password')),
    ];
    yield 'timeout' => [
        TimeoutException::class,
        static fn (Extension\Connection $n): mixed => $n->execute($cpuBound, [], ['timeout' => 500] + nativeOptions())['cursor']?->fetchBatch(),
        static fn (Session $s): mixed => $s->execute($cpuBound, [], TestCase::options(timeout: 0.5))->cursor()?->fetchBatch(),
    ];
    yield 'unsupported' => [
        UnsupportedException::class,
        static fn (Extension\Connection $n): mixed => $n->execute($bfile, [], nativeOptions())['cursor']?->fetchBatch(),
        static fn (Session $s): mixed => $s->execute($bfile, [], TestCase::options())->cursor()?->fetchBatch(),
    ];
    yield 'usage' => [
        UsageException::class,
        static function (Extension\Connection $n): void {
            $cursor = $n->execute('select 1 from dual', [], nativeOptions())['cursor'];
            $cursor?->close();
            $cursor?->fetchBatch();
        },
        static function (Session $s): void {
            $cursor = $s->execute('select 1 from dual', [], TestCase::options())->cursor();
            $cursor?->close();
            $cursor?->fetchBatch();
        },
    ];
});

test('each kind translates to its core class keeping code message and offset', function (string $class, Closure $nativeCall, Closure $driverCall): void {
    $native = native();
    $expected = nativeException(static fn (): mixed => $nativeCall($native));

    $session = $this->connect();

    $thrown = thrown(fn (): mixed => $driverCall($session, fn (ConnectionConfig $config): Session => $this->driver()->connect($config)));

    expect($thrown)->toBeInstanceOf(OracleException::class)
        ->and($thrown::class)->toBe($class)
        ->and($thrown->getCode())->toBe($expected->getCode())
        ->and($thrown->getMessage())->toBe($expected->getMessage())
        ->and($thrown->getPrevious())->toBeNull()
        ->and($thrown instanceof QueryException ? $thrown->offset() : $expected->offset)->toBe($expected->offset);
})->with('kinds');

test('the session array is reapplied on every acquire', function (): void {
    $pool = ['maxConnections' => 1, 'connectTimeout' => 20_061] + nativePool($this->config());
    $sql = <<<'SQL'
        select sys_context('userenv', 'sid') sid,
               sys_context('userenv', 'module') module,
               sys_context('userenv', 'action') action,
               sys_context('userenv', 'client_identifier') client_identifier,
               sys_context('userenv', 'client_info') client_info
          from dual
        SQL;
    $read = static function (string $name) use ($pool, $sql): array {
        $connection = Extension\Connection::connect($pool, [
            'tracing' => ['module' => "module $name", 'action' => "action $name", 'clientIdentifier' => "id $name", 'clientInfo' => "info $name"],
            'callTimeout' => 60_000,
        ]);
        $row = $connection->execute($sql, [], nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
        $connection->close();

        return $row;
    };

    $rows = [$read('A'), $read('B')];

    expect($rows[1]['SID'] ?? null)->toBe($rows[0]['SID'] ?? null)
        ->and($rows[0])->toMatchArray(['MODULE' => 'module A', 'ACTION' => 'action A', 'CLIENT_IDENTIFIER' => 'id A', 'CLIENT_INFO' => 'info A'])
        ->and($rows[1])->toMatchArray(['MODULE' => 'module B', 'ACTION' => 'action B', 'CLIENT_IDENTIFIER' => 'id B', 'CLIENT_INFO' => 'info B']);
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
    $pool = nativePool($this->config());

    $exception = nativeException(static fn (): Extension\Connection | array => $key === 'callTimeout'
        ? Extension\Connection::connect($pool, ['callTimeout' => $ms] + nativeSession())
        : $native->execute('select 1 from dual', [], ['timeout' => $ms] + nativeOptions()));

    expect($exception->kind)->toBe('usage')
        ->and($exception->getMessage())->toContain("'$key'");
})->with('nonPositiveDurations');

/**
 * What a native Connection hands out that outlives it: the query, how to
 * take the object from its Cursor, and the reads on it.
 *
 * @return iterable<string, array{string, Closure(Cursor): object, Closure(object): array<string, Closure(): mixed>}>
 */
dataset('orphans', function () {
    yield 'a Lob' => [
        "select to_clob('x') as c from dual",
        static fn (Cursor $cursor): mixed => $cursor->fetchBatch()[0]['C'] ?? null,
        static fn (Lob $lob): array => ['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)],
    ];
    yield 'a Cursor' => [
        'select level as n from dual connect by level <= 3',
        static function (Cursor $cursor): Cursor {
            $cursor->fetchBatch();

            return $cursor;
        },
        static fn (Cursor $cursor): array => ['fetchBatch()' => $cursor->fetchBatch(...), 'columns()' => $cursor->columns(...)],
    ];
});

test('after close what the connection handed out fails with kind usage', function (string $sql, Closure $take, Closure $reads): void {
    $connection = Extension\Connection::connect(nativePool($this->config()), nativeSession());
    $cursor = $connection->execute($sql, [], ['fetchSize' => 1] + nativeOptions())['cursor'];
    assert($cursor instanceof Cursor);
    $orphan = $take($cursor);
    $connection->close();

    /** @var array<string, Closure(): mixed> $calls */
    $calls = $reads($orphan);

    $failures = array_map(static function (Closure $read): array {
        $exception = nativeException($read);

        return [$exception->kind, $exception->getMessage()];
    }, $calls);

    expect($failures)->toBe(array_fill_keys(array_keys($failures), ['usage', 'The Connection is closed.']));
})->with('orphans');

test('what earlier owners handed out leaves the next owner of the session unaffected', function (string $sql, Closure $take, Closure $reads): void {
    $pool = ['maxConnections' => 1, 'connectTimeout' => 20_051] + nativePool($this->config());
    $options = ['fetchSize' => 1] + nativeOptions();
    $orphans = [];
    for ($i = 0; $i < 1000; ++$i) {
        $connection = Extension\Connection::connect($pool, nativeSession());
        $cursor = $connection->execute($sql, [], $options)['cursor'];
        assert($cursor instanceof Cursor);
        $orphans[] = $take($cursor);
        $connection->close();
    }
    $next = Extension\Connection::connect($pool, nativeSession());
    $this->natives[] = $next;
    $reading = $next->execute($sql, [], $options)['cursor'];
    assert($reading instanceof Cursor);

    $rows = $reading->fetchBatch();
    unset($orphans);
    while (($batch = $reading->fetchBatch()) !== []) {
        array_push($rows, ...$batch);
    }

    $temporary = $next->execute(
        "select count(*) as n from v\$temporary_lobs t join v\$session s on s.sid = t.sid where s.audsid = userenv('sessionid') and t.cache_lobs + t.nocache_lobs + t.abstract_lobs > 2",
        [],
        nativeOptions(),
    )['cursor']?->fetchBatch();
    expect(array_map(plain(...), $rows))->toBe(str_contains($sql, 'to_clob') ? [['C' => 'x']] : [['N' => 1], ['N' => 2], ['N' => 3]])
        ->and($temporary)->toBe([['N' => 0]]);
})->with('orphans');

/**
 * @param array<int|string, mixed> $row
 *
 * @return array<int|string, mixed>
 */
function plain(array $row): array
{
    return array_map(static fn (mixed $value): mixed => $value instanceof Lob ? $value->readChunk() : $value, $row);
}

function native(): Extension\Connection
{
    $native = Extension\Connection::connect(nativePool(TestCase::config()), nativeSession());
    $test = test();
    $test->natives = [...$test->natives, $native];

    return $native;
}

function nativeException(callable $call): Exception
{
    $thrown = thrown($call);

    return $thrown instanceof Exception ? $thrown : throw new RuntimeException('Not a native exception: ' . $thrown::class, previous: $thrown);
}

function thrown(callable $call): Throwable
{
    try {
        $call();
    } catch (Throwable $throwable) {
        return $throwable;
    }

    throw new RuntimeException('The call did not throw.');
}

function succeeds(callable $call): bool
{
    try {
        $call();
    } catch (Throwable) {
        return false;
    }

    return true;
}

/**
 * @return array{dsn: string, user: string, password: string, proxyUser: null, walletLocation: null, walletPassword: null, configDir: null, connectTimeout: int, drcpConnectionClass: null, maxConnections: int, acquireTimeout: int, statementCacheSize: int, standalone: bool}
 */
function nativePool(ConnectionConfig $config): array
{
    return [
        'dsn' => $config->dsn,
        'user' => $config->user,
        'password' => $config->password,
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
