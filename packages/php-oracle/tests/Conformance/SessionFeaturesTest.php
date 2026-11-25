<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tracing;

/**
 * Session features, and the Fetch size cases.
 */
final class SessionFeaturesTest extends ConformanceTestCase
{
    private const string ROWS = 'select level n from dual connect by level <= :n';

    /**
     * One Round trip counter and one observer serve the whole file (both only
     * read V$ views): a logon per test adds to the listener's load, which
     * refuses logons with ORA-12516 when it runs high.
     */
    private static RoundTripCounter $counter;

    private static IndependentSession $observer;

    public static function setUpBeforeClass(): void
    {
        self::$counter = RoundTripCounter::open();
        self::$observer = IndependentSession::open();
    }

    public static function tearDownAfterClass(): void
    {
        self::$counter->close();
        self::$observer->close();
        parent::tearDownAfterClass();
    }

    #[Test]
    public function config_tracing_shows_in_v_session_after_the_first_round_trip(): void
    {
        $connection = $this->connect(tracing: new Tracing(
            module: 'billing',
            action: 'close month',
            clientIdentifier: 'user-42',
            clientInfo: 'worker 7',
        ));
        $sid = self::sid($connection);

        self::assertSame([
            'MODULE' => 'billing',
            'ACTION' => 'close month',
            'CLIENT_IDENTIFIER' => 'user-42',
            'CLIENT_INFO' => 'worker 7',
        ], $this->tracing($sid));
    }

    #[Test]
    public function the_default_fetch_size_reads_up_to_100_rows_in_one_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        foreach ([1, 99, 100] as $count) {
            $rows = [];
            self::assertSame(1, self::$counter->during($sid, function () use ($connection, $count, &$rows): void {
                $rows = $this->readAll($connection, $count, self::options());
            }), "$count rows");
            self::assertSame([$count], array_map(count(...), $rows), "$count rows");
        }
    }

    #[Test]
    public function a_fetch_size_of_10_reads_25_rows_in_3_round_trips(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $cursor = null;
        $trips = [];

        $trips[] = self::$counter->during($sid, function () use ($connection, &$cursor): void {
            $cursor = $connection->execute(self::ROWS, ['n' => 25], self::options(fetchSize: 10))->cursor();
        });
        self::assertNotNull($cursor);
        $sizes = [];
        for ($i = 0; $i < 5; ++$i) {
            $trips[] = self::$counter->during($sid, function () use ($cursor, &$sizes): void {
                $sizes[] = \count($cursor->fetchBatch());
            });
        }

        self::assertSame([10, 10, 5, 0, 0], $sizes);
        self::assertSame(3, array_sum($trips));
        self::assertSame([0, 0], \array_slice($trips, 4), 'no Round trip at the end');
        self::assertLessThanOrEqual(1, max($trips));
    }

    #[Test]
    public function the_fetch_size_of_an_execute_governs_its_cursor(): void
    {
        $connection = $this->connect();
        $small = $connection->execute(self::ROWS, ['n' => 25], self::options(fetchSize: 7))->cursor();
        $large = $connection->execute(self::ROWS, ['n' => 25], self::options(fetchSize: 20))->cursor();
        self::assertNotNull($small);
        self::assertNotNull($large);

        self::assertSame(7, \count($small->fetchBatch()));
        self::assertSame(20, \count($large->fetchBatch()));
        self::assertSame(7, \count($small->fetchBatch()));
    }

    /**
     * The next Round trip carries the whole new set, clearing what it leaves
     * unset.
     */
    #[Test]
    public function trace_makes_no_round_trip_and_replaces_the_whole_set(): void
    {
        $connection = $this->connect(tracing: new Tracing(
            module: 'billing',
            action: 'close month',
            clientIdentifier: 'user-42',
            clientInfo: 'worker 7',
        ));
        $sid = self::sid($connection);

        self::assertSame(0, self::$counter->during($sid, static function () use ($connection): void {
            $connection->trace(new Tracing(module: 'reports', clientInfo: 'worker 8'));
        }));
        self::assertSame('billing', $this->tracing($sid)['MODULE'], 'still pending');

        self::sid($connection);
        self::assertSame([
            'MODULE' => 'reports',
            'ACTION' => null,
            'CLIENT_IDENTIFIER' => null,
            'CLIENT_INFO' => 'worker 8',
        ], $this->tracing($sid));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function sentOrPending(): iterable
    {
        yield 'sent' => [true];
        yield 'still pending' => [false];
    }

    #[Test]
    #[DataProvider('sentOrPending')]
    public function tracing_set_by_one_connection_is_empty_for_the_next_on_the_same_session(bool $sent): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $first = $this->connect(...$fields);
        $sid = self::sid($first);
        $first->trace(new Tracing(module: 'A', action: 'A', clientIdentifier: 'A', clientInfo: 'A'));
        if ($sent) {
            self::sid($first);
        }
        $first->close();

        $second = $this->connect(...$fields);

        self::assertSame(
            ['SID' => (string) $sid, 'MODULE' => null, 'ACTION' => null, 'CLIENT_IDENTIFIER' => null, 'CLIENT_INFO' => null],
            self::rows($second, <<<'SQL'
                select sys_context('userenv', 'sid') sid,
                       sys_context('userenv', 'module') module,
                       sys_context('userenv', 'action') action,
                       sys_context('userenv', 'client_identifier') client_identifier,
                       sys_context('userenv', 'client_info') client_info
                  from dual
                SQL)[0],
        );
    }

    #[Test]
    public function a_long_read_whose_round_trips_are_each_under_the_call_timeout_completes(): void
    {
        $connection = $this->connect();
        $started = microtime(true);

        $rows = self::rows(
            $connection,
            'select fx_pool.slow(level, 0.3) n from dual connect by level <= 6',
            [],
            self::options(fetchSize: 1, timeout: 1),
        );

        self::assertSame([['N' => 1], ['N' => 2], ['N' => 3], ['N' => 4], ['N' => 5], ['N' => 6]], $rows);
        self::assertGreaterThan(1.5, microtime(true) - $started, 'The read as a whole outlasted the Call timeout.');
    }

    #[Test]
    public function a_fetch_batch_over_the_call_timeout_throws_timeout_exception(): void
    {
        $connection = $this->connect(...self::ownPool() + ['maxConnections' => 2]);
        $cursor = $connection->execute(
            'select fx_pool.slow(level, case when level > 2 then 1 else 0 end) n from dual connect by level <= 4',
            [],
            self::options(fetchSize: 1, timeout: 0.5),
        )->cursor();
        self::assertNotNull($cursor);

        self::assertSame([['N' => 1]], $cursor->fetchBatch());
        self::assertSame([['N' => 2]], $cursor->fetchBatch());
        $this->expectException(TimeoutException::class);
        $cursor->fetchBatch();
    }

    /**
     * On a killed Session, `trace()` still makes no Round trip, so it cannot
     * see the kill.
     */
    #[Test]
    public function on_a_killed_session_tracing_and_the_call_timeout_give_way_to_connection_lost(): void
    {
        $connection = $this->connect(...self::ownPool() + ['maxConnections' => 2]);
        self::kill($connection);

        $connection->trace(new Tracing(module: 'after the kill'));
        $started = microtime(true);
        try {
            $connection->execute('select 1 from dual', [], self::options(timeout: 30));
            self::fail('A killed Session ran a statement.');
        } catch (ConnectionLostException) {
            self::assertLessThan(5, microtime(true) - $started, 'No wait for the Call timeout.');
        }

        $this->expectException(ConnectionLostException::class);
        $this->expectExceptionMessageMatches('/^Connection is broken: /');
        $connection->trace(new Tracing(module: 'broken'));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function statementCacheSizes(): iterable
    {
        yield 'the default Statement cache' => [20, 1];
        yield 'statementCacheSize: 0' => [0, 3];
    }

    #[Test]
    #[DataProvider('statementCacheSizes')]
    public function a_repeated_statement_parses_once_unless_the_statement_cache_is_off(int $size, int $parses): void
    {
        $connection = $this->connect(...self::ownPool() + ['statementCacheSize' => $size]);
        $sid = self::sid($connection);
        $sql = 'select :n * 2 doubled from dual';

        self::assertSame($parses, $this->statistic($sid, 'parse count (total)', static function () use ($connection, $sql): void {
            foreach ([1, 2, 3] as $n) {
                self::assertSame([['DOUBLED' => 2 * $n]], self::rows($connection, $sql, ['n' => $n]));
            }
        }));
    }

    /**
     * How much a statistic of V$SESSTAT grew for a Session while $action ran.
     */
    private function statistic(int $sid, string $name, callable $action): int
    {
        $read = fn(): int => (int) self::$observer->select(
            'select s.value from v$sesstat s join v$statname n on n.statistic# = s.statistic# where n.name = :name and s.sid = :sid',
            ['name' => $name, 'sid' => $sid],
        )[0]['VALUE'];
        $before = $read();
        $action();

        return $read() - $before;
    }

    /**
     * @return array<string, string|null>
     */
    private function tracing(int $sid): array
    {
        return self::$observer->select(
            'select module, action, client_identifier, client_info from v$session where sid = :sid',
            ['sid' => $sid],
        )[0];
    }

    /**
     * @return list<list<array<int|string, mixed>>> the batches
     */
    private function readAll(DriverConnection $connection, int $count, DriverOptions $options): array
    {
        $cursor = $connection->execute(self::ROWS, ['n' => $count], $options)->cursor();
        self::assertNotNull($cursor);
        $batches = [];
        while (($batch = $cursor->fetchBatch()) !== []) {
            $batches[] = $batch;
        }
        $cursor->close();

        return $batches;
    }
}
