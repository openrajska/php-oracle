<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use Rajska\Oracle\Driver\DriverExecution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Driver\DriverConnection;
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

/**
 * Conformance catalog, Native surface (#31). The cases about the native
 * classes themselves (1, 2, 3) use them directly; translation (9) goes
 * through the Driver seam.
 */
final class NativeSurfaceTest extends ConformanceTestCase
{
    private const string DISTINCTIVE = 'distinctive-bound-value-35';

    /** @var list<NativeConnection> */
    private array $natives = [];

    protected function tearDown(): void
    {
        foreach ($this->natives as $native) {
            $native->close();
        }
        $this->natives = [];
        parent::tearDown();
    }

    /**
     * Native surface 1.
     */
    #[Test]
    public function every_native_class_is_final_and_cannot_be_created_cloned_or_serialized(): void
    {
        $connection = $this->native();
        $cursor = $connection->execute('select 1 from dual', [], self::nativeOptions())['cursor'];
        self::assertInstanceOf(NativeCursor::class, $cursor);
        $lob = $connection->execute("select to_clob('x') as c from dual", [], self::nativeOptions())['cursor']?->fetchBatch()[0]['C'] ?? null;
        self::assertInstanceOf(NativeLob::class, $lob);
        $exception = $this->nativeException(fn () => $connection->execute('select 1 from dual', [], ['unknown' => 1] + self::nativeOptions()));

        foreach ([$connection, $cursor, $lob, $exception] as $object) {
            $class = $object::class;
            self::assertTrue(new \ReflectionClass($class)->isFinal(), "$class is final");
            $this->assertFails(static fn (): \Rajska\Oracle\Extension\Connection|NativeCursor|NativeLob|NativeException => new $class(), "new $class");
            $this->assertFails(static fn (): \Rajska\Oracle\Extension\Connection|NativeCursor|NativeLob|NativeException => clone $object, "clone $class");
            $this->assertFails(static fn (): string => serialize($object), "serialize($class)");
            $this->assertFails(
                static fn (): mixed => unserialize(\sprintf('O:%d:"%s":0:{}', \strlen($class), $class)),
                "unserialize($class)",
            );
        }
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function sensitiveParameters(): iterable
    {
        yield 'Connection::run() $binds' => [Connection::class, 'run', 'binds'];
        yield 'Connection::select() $binds' => [Connection::class, 'select', 'binds'];
        yield 'DriverConnection::execute() $binds' => [DriverConnection::class, 'execute', 'binds'];
        yield 'DriverConnection::executeMany() $rows' => [DriverConnection::class, 'executeMany', 'rows'];
        yield 'ExtensionConnection::execute() $binds' => [ExtensionConnection::class, 'execute', 'binds'];
        yield 'ExtensionConnection::executeMany() $rows' => [ExtensionConnection::class, 'executeMany', 'rows'];
        yield 'native Connection::connect() $pool' => [NativeConnection::class, 'connect', 'pool'];
        yield 'native Connection::execute() $binds' => [NativeConnection::class, 'execute', 'binds'];
        yield 'native Connection::executeMany() $rows' => [NativeConnection::class, 'executeMany', 'rows'];
    }

    /**
     * Native surface 2, at every layer.
     *
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('sensitiveParameters')]
    public function pool_binds_and_rows_are_sensitive_parameters(string $class, string $method, string $parameter): void
    {
        $parameters = array_filter(
            new \ReflectionMethod($class, $method)->getParameters(),
            static fn (\ReflectionParameter $p): bool => $p->getName() === $parameter,
        );

        self::assertCount(1, $parameters, "$class::$method() has \$$parameter");
        self::assertCount(1, array_values($parameters)[0]->getAttributes(\SensitiveParameter::class));
    }

    /**
     * Native surface 2, in a trace: an error raised inside a native call
     * shows the values as SensitiveParameterValue.
     */
    #[Test]
    public function native_traces_hide_pool_binds_and_rows(): void
    {
        $native = $this->native();
        $secret = ['secret' => self::DISTINCTIVE];
        // [the call, its method, the position of the sensitive argument];
        // each passes a string for an array on purpose.
        $calls = [
            // @phpstan-ignore argument.type, argument.type
            [static fn () => NativeConnection::connect($secret, 'not an array'), 'connect', 0],
            // @phpstan-ignore argument.type
            [static fn () => $native->execute('select 1 from dual', $secret, 'not an array'), 'execute', 1],
            // @phpstan-ignore argument.type
            [static fn () => $native->executeMany('select 1 from dual', [$secret], 'not an array'), 'executeMany', 1],
        ];

        foreach ($calls as [$call, $method, $position]) {
            try {
                $call();
                self::fail("$method() accepted a string for an array.");
            } catch (\TypeError $e) {
                $frame = $e->getTrace()[0];
                self::assertSame([NativeConnection::class, $method], [$frame['class'] ?? null, $frame['function']]);
                self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][$position] ?? null, $method);
                self::assertStringNotContainsString(self::DISTINCTIVE, $e->getTraceAsString());
            }
        }
    }

    /**
     * Native surface 3.
     */
    #[Test]
    public function an_unknown_key_in_any_array_fails_with_kind_usage_before_any_round_trip(): void
    {
        $database = TestDatabase::fromEnvironment();
        // The arrays break the documented shapes on purpose.
        // @phpstan-ignore argument.type, argument.type
        $connect = fn (array $pool, array $session) => NativeConnection::connect($pool, $session);

        foreach ([
            '$pool' => fn () => $connect(['unknown' => 1] + self::nativePool($database), self::nativeSession()),
            '$session' => fn () => $connect(self::nativePool($database), ['unknown' => 1] + self::nativeSession()),
            '$session[tracing]' => fn () => $connect(
                self::nativePool($database),
                ['tracing' => ['unknown' => 1] + self::nativeSession()['tracing']] + self::nativeSession(),
            ),
        ] as $array => $call) {
            self::assertSame('usage', $this->nativeException($call)->kind, "an unknown key in $array");
        }

        $native = $this->native();
        $rows = $native->execute("select sys_context('userenv', 'sid') as sid from dual", [], self::nativeOptions())['cursor']?->fetchBatch();
        $sid = $rows[0]['SID'] ?? null;
        self::assertIsString($sid);
        $sid = (int) $sid;
        $counter = RoundTripCounter::open();

        try {
            self::assertSame(0, $counter->during($sid, function () use ($native): void {
                $exception = $this->nativeException(fn () => $native->execute(
                    'select 1 from dual',
                    [],
                    ['unknown' => 1] + self::nativeOptions(),
                ));
                self::assertSame('usage', $exception->kind, 'an unknown key in $options');
                $exception = $this->nativeException(fn () => $native->trace(
                    ['unknown' => 1] + self::nativeSession()['tracing'],
                ));
                self::assertSame('usage', $exception->kind, 'an unknown key in $tracing');
            }));
        } finally {
            $counter->close();
        }
    }

    /**
     * Native surface 9, for the kinds this slice raises: the Driver seam
     * throws Core's class with the native code, message and offset, and the
     * native exception is unreachable.
     */
    #[Test]
    public function each_kind_translates_to_its_core_class_keeping_code_message_and_offset(): void
    {
        $driver = $this->connect();
        $native = $this->native();
        $wrongPassword = TestDatabase::fromEnvironment()->config(password: 'not-the-password');
        $duplicate = "insert into fx_items (id, category_id, name, price, created_at) values (1, 1, 'duplicate', 1, sysdate)";
        $cpuBound = 'select count(*) from fx_bench_rows a, fx_bench_rows b';

        $cases = [
            'database' => [
                QueryException::class,
                fn () => $native->execute('select 1 fro dual', [], self::nativeOptions()),
                fn (): DriverExecution => $driver->execute('select 1 fro dual', [], self::options()),
            ],
            'database, ORA-00001' => [
                UniqueViolationException::class,
                fn () => $native->execute($duplicate, [], self::nativeOptions()),
                fn (): DriverExecution => $driver->execute($duplicate, [], self::options()),
            ],
            'connection' => [
                ConnectionException::class,
                fn () => NativeConnection::connect(['password' => 'not-the-password'] + self::nativePool(TestDatabase::fromEnvironment()), self::nativeSession()),
                fn (): DriverConnection => $this->driver()->connect($wrongPassword),
            ],
            'timeout' => [
                TimeoutException::class,
                fn () => $native->execute($cpuBound, [], ['timeout' => 500] + self::nativeOptions())['cursor']?->fetchBatch(),
                fn (): ?array => $driver->execute($cpuBound, [], self::options(timeout: 0.5))->cursor()?->fetchBatch(),
            ],
            'unsupported' => [
                UnsupportedException::class,
                fn () => $native->execute("select bfilename('NO_SUCH_DIRECTORY', 'x') from dual", [], self::nativeOptions())['cursor']?->fetchBatch(),
                fn (): ?array => $driver->execute("select bfilename('NO_SUCH_DIRECTORY', 'x') from dual", [], self::options())->cursor()?->fetchBatch(),
            ],
            'usage' => [
                UsageException::class,
                static function () use ($native): void {
                    $cursor = $native->execute('select 1 from dual', [], self::nativeOptions())['cursor'];
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
            $expected = $this->nativeException($nativeCall);

            try {
                $driverCall();
                self::fail("$case: no exception");
            } catch (OracleException $e) {
                self::assertSame($class, $e::class, $case);
                self::assertSame($expected->getCode(), $e->getCode(), "$case: code");
                self::assertSame($expected->getMessage(), $e->getMessage(), "$case: message");
                if ($e instanceof QueryException) {
                    self::assertSame($expected->offset, $e->offset(), "$case: offset");
                }
                self::assertNull($e->getPrevious(), "$case: previous");
            }
        }
    }

    /**
     * Native surface 5: every acquire applies its own `$session`, whatever
     * the Session's previous Connection had.
     */
    #[Test]
    public function the_session_array_is_reapplied_on_every_acquire(): void
    {
        $pool = ['maxConnections' => 1, 'connectTimeout' => 20_061] + self::nativePool(TestDatabase::fromEnvironment());
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
            $row = $connection->execute($sql, [], self::nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
            $connection->close();
            $sessions[] = array_shift($row);
            self::assertSame([
                'MODULE' => "module $name",
                'ACTION' => "action $name",
                'CLIENT_IDENTIFIER' => "id $name",
                'CLIENT_INFO' => "info $name",
            ], $row);
        }
        self::assertSame($sessions[0], $sessions[1], 'One Session for both');
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function nonPositiveDurations(): iterable
    {
        foreach ([0, -1] as $ms) {
            yield "\$session callTimeout $ms" => ['callTimeout', $ms];
            yield "\$options timeout $ms" => ['timeout', $ms];
        }
    }

    /**
     * A Call timeout is at least 1 ms, or null for no limit.
     */
    #[Test]
    #[DataProvider('nonPositiveDurations')]
    public function a_call_timeout_under_1_ms_fails_with_kind_usage(string $key, int $ms): void
    {
        $native = $this->native();
        $exception = $this->nativeException(static fn () => $key === 'callTimeout'
            ? NativeConnection::connect(self::nativePool(TestDatabase::fromEnvironment()), ['callTimeout' => $ms] + self::nativeSession())
            : $native->execute('select 1 from dual', [], ['timeout' => $ms] + self::nativeOptions()));

        self::assertSame('usage', $exception->kind);
        self::assertStringContainsString("'$key'", $exception->getMessage());
    }

    /**
     * Native surface 8 and 10, for Lobs: `type()` gives a LobType backing
     * value; after `close()` of their Connection every call fails with
     * `kind: usage`. Over 1000 cycles on one Session, the temporary LOBs
     * dropped by the Release are freed, and the next owner reads its own.
     */
    #[Test]
    public function after_close_its_lobs_fail_with_kind_usage_and_the_sessions_next_owner_is_unaffected(): void
    {
        $pool = ['maxConnections' => 1, 'connectTimeout' => 20_051] + self::nativePool(TestDatabase::fromEnvironment());
        $sql = "select to_clob('x') as c, to_blob(hextoraw('ff')) as b from dual";
        $orphans = [];
        for ($i = 0; $i < 1000; ++$i) {
            $connection = NativeConnection::connect($pool, self::nativeSession());
            $row = $connection->execute($sql, [], self::nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
            self::assertInstanceOf(NativeLob::class, $row['C'] ?? null);
            self::assertInstanceOf(NativeLob::class, $row['B'] ?? null);
            self::assertSame(['clob', 'blob'], [$row['C']->type(), $row['B']->type()]);
            $connection->close();
            $orphans[] = $row;
        }

        $lob = $orphans[999]['C'];
        foreach (['type()' => $lob->type(...), 'size()' => $lob->size(...), 'readChunk()' => $lob->readChunk(...)] as $call => $read) {
            $e = $this->nativeException($read);
            self::assertSame('usage', $e->kind, $call);
            self::assertSame('The Connection is closed.', $e->getMessage(), $call);
        }
        $lob->close();

        $next = NativeConnection::connect($pool, self::nativeSession());
        $this->natives[] = $next;
        $row = $next->execute($sql, [], self::nativeOptions())['cursor']?->fetchBatch()[0] ?? [];
        unset($lob, $orphans);
        self::assertInstanceOf(NativeLob::class, $row['C'] ?? null);
        self::assertSame('x', $row['C']->readChunk());
        $temporary = $next->execute(
            'select count(*) as n from v$temporary_lobs t join v$session s on s.sid = t.sid where s.audsid = userenv(\'sessionid\') and t.cache_lobs + t.nocache_lobs + t.abstract_lobs > 2',
            [],
            self::nativeOptions(),
        );
        self::assertSame([['N' => 0]], $temporary['cursor']?->fetchBatch(), 'No temporary LOB of the earlier owners is left');
    }

    /**
     * Native surface 8, for query Cursors. A Pool of one Session, so every
     * cycle runs on the same Session: a Cursor left open on the server by a
     * Release would pile up there until ORA-01000 (open_cursors is 300), and
     * a close sent after the Release would land on the next owner.
     */
    #[Test]
    public function after_close_its_cursors_fail_with_kind_usage_and_the_sessions_next_owner_is_unaffected(): void
    {
        $pool = ['maxConnections' => 1, 'connectTimeout' => 20_040] + self::nativePool(TestDatabase::fromEnvironment());
        $options = ['fetchSize' => 1] + self::nativeOptions();
        $sql = 'select level as n from dual connect by level <= 3';
        $orphans = [];
        for ($i = 0; $i < 1000; ++$i) {
            $connection = NativeConnection::connect($pool, self::nativeSession());
            $cursor = $connection->execute($sql, [], $options)['cursor'];
            self::assertInstanceOf(NativeCursor::class, $cursor);
            self::assertSame([['N' => 1]], $cursor->fetchBatch());
            $connection->close();
            $orphans[] = $cursor;
        }

        foreach (['fetchBatch()' => static fn () => $cursor->fetchBatch(), 'columns()' => static fn () => $cursor->columns()] as $call => $read) {
            $e = $this->nativeException($read);
            self::assertSame('usage', $e->kind, $call);
            self::assertSame('The Connection is closed.', $e->getMessage(), $call);
        }
        $cursor->close();

        $next = NativeConnection::connect($pool, self::nativeSession());
        $this->natives[] = $next;
        $reading = $next->execute($sql, [], $options)['cursor'];
        self::assertInstanceOf(NativeCursor::class, $reading);
        self::assertSame([['N' => 1]], $reading->fetchBatch());
        unset($cursor, $orphans);
        self::assertSame([['N' => 2]], $reading->fetchBatch());
        self::assertSame([['N' => 3]], $reading->fetchBatch());
        self::assertSame([], $reading->fetchBatch());
    }

    /**
     * Native surface 11: Core's copy of the Extension's stub is for static
     * analysis only and never reaches users' vendor/. Core's dist archive
     * is `git archive` of its directory at HEAD, as GitHub serves it from
     * a split repository.
     */
    #[Test]
    public function cores_dist_archive_has_no_stubs(): void
    {
        $package = \dirname(__DIR__, 2);
        [$status, $paths] = self::command(['git', '-C', $package, 'rev-parse', '--show-toplevel', '--show-prefix']);
        if ($status !== 0) {
            self::markTestSkipped("No git repository here: $paths");
        }
        [$root, $prefix] = explode("\n", trim($paths)) + ['', ''];
        $archive = tempnam(sys_get_temp_dir(), 'core-dist');
        self::assertIsString($archive);

        try {
            [$status, $output] = self::command(['git', '-C', $root, 'archive', '--format=tar', '-o', $archive, 'HEAD:' . rtrim($prefix, '/')]);
            self::assertSame(0, $status, $output);
            [$status, $listing] = self::command(['tar', '-tf', $archive]);
            self::assertSame(0, $status, $listing);
        } finally {
            unlink($archive);
        }
        $entries = explode("\n", trim($listing));

        self::assertContains('src/Oracle.php', $entries, $listing);
        self::assertSame([], array_values(array_filter($entries, static fn (string $entry): bool => str_starts_with($entry, 'stubs'))));
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string} the exit status and the output (stdout, then stderr)
     */
    private static function command(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function native(): NativeConnection
    {
        $native = NativeConnection::connect(self::nativePool(TestDatabase::fromEnvironment()), self::nativeSession());
        $this->natives[] = $native;

        return $native;
    }

    private function nativeException(callable $call): NativeException
    {
        try {
            $call();
        } catch (NativeException $e) {
            return $e;
        }
        self::fail('The native call did not throw.');
    }

    private function assertFails(callable $call, string $what): void
    {
        try {
            $call();
        } catch (\Throwable) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail("$what succeeded.");
    }

    /**
     * @return array{dsn: string, user: string, password: string, proxyUser: null, walletLocation: null, walletPassword: null, configDir: null, connectTimeout: int, drcpConnectionClass: null, maxConnections: int, acquireTimeout: int, statementCacheSize: int, standalone: bool}
     */
    private static function nativePool(TestDatabase $database): array
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
    private static function nativeSession(): array
    {
        return [
            'tracing' => ['module' => null, 'action' => null, 'clientIdentifier' => null, 'clientInfo' => null],
            'callTimeout' => null,
        ];
    }

    /**
     * @return array{autocommit: bool, timeout: null, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}
     */
    private static function nativeOptions(): array
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
}
