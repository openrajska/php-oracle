<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Driver\DriverArrayDml;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tracing;
use Rajska\Oracle\ValueMapping;

/**
 * Conformance catalog, Pool and lifecycle (#19, #21).
 */
final class PoolAndLifecycleTest extends ConformanceTestCase
{
    /**
     * Pool and lifecycle 1.
     */
    #[Test]
    public function two_connects_give_two_sessions_that_do_not_see_each_others_uncommitted_changes(): void
    {
        $first = $this->connect();
        $second = $this->connect();

        self::assertNotSame(self::sid($first), self::sid($second));

        $first->execute(
            "update fx_items set name = 'changed by the first' where id = 1",
            [],
            self::options(autocommit: false),
        );

        self::assertSame('changed by the first', self::value($first, 'select name from fx_items where id = 1'));
        self::assertSame('Item 0001', self::value($second, 'select name from fx_items where id = 1'));
    }

    #[Test]
    public function commit_makes_the_work_visible_to_another_session(): void
    {
        $connection = $this->connect();
        $observer = IndependentSession::open();

        try {
            $connection->execute(
                "insert into fx_batch_rows (id, code, qty) values (35001, 'commit', 1)",
                [],
                self::options(autocommit: false),
            );
            $connection->commit();

            self::assertSame(
                [['N' => '1']],
                $observer->select('select count(*) as n from fx_batch_rows where id = 35001'),
            );
        } finally {
            $observer->execute('delete from fx_batch_rows where id = 35001');
            $observer->commit();
            $observer->close();
        }
    }

    #[Test]
    public function rollback_undoes_the_work(): void
    {
        $connection = $this->connect();
        $connection->execute(
            "insert into fx_batch_rows (id, code, qty) values (35002, 'rollback', 1)",
            [],
            self::options(autocommit: false),
        );

        $connection->rollback();

        self::assertSame(0, self::value($connection, 'select count(*) from fx_batch_rows where id = 35002'));
    }

    #[Test]
    public function the_server_version_has_five_parts(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+\.\d+\.\d+$/', $this->connect()->serverVersion());
    }

    /**
     * Pool and lifecycle 5, the standalone part.
     */
    #[Test]
    public function a_standalone_connection_starts_clean_and_logs_off(): void
    {
        $fields = self::ownPool() + ['standalone' => true];
        $first = $this->connect(...$fields);
        self::assertSame(1, self::value($first, 'select fx_pool.bump_counter from dual'));
        self::assertSame(2, self::value($first, 'select fx_pool.bump_counter from dual'));
        $session = self::value($first, "select sys_context('userenv', 'sessionid') from dual");
        self::assertIsString($session);

        $first->close();

        $observer = IndependentSession::open();
        try {
            self::assertTrue(
                self::eventually(static fn(): bool => $observer->select(
                    'select count(*) as n from v$session where audsid = :audsid',
                    ['audsid' => $session],
                ) === [['N' => '0']]),
                'The standalone Session logged off.',
            );
        } finally {
            $observer->close();
        }
        $second = $this->connect(...$fields);
        self::assertNotSame($session, self::value($second, "select sys_context('userenv', 'sessionid') from dual"));
        self::assertSame(1, self::value($second, 'select fx_pool.bump_counter from dual'));
    }

    #[Test]
    public function standalone_connections_are_not_held_to_max_connections(): void
    {
        $fields = self::ownPool() + ['standalone' => true, 'maxConnections' => 1, 'acquireTimeout' => 0.25];
        $first = $this->connect(...$fields);
        $second = $this->connect(...$fields);

        self::assertNotSame(self::sid($first), self::sid($second));
    }

    /**
     * Pool and lifecycle 7: the ceiling.
     */
    #[Test]
    public function at_max_connections_a_connect_fails_as_pool_exhausted_after_acquire_timeout_and_succeeds_after_a_release(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $held = $this->connect(...$fields);
        $sid = self::sid($held);

        $started = hrtime(true);
        try {
            $this->connect(...$fields);
            self::fail('A second Session came out of a Pool of one.');
        } catch (PoolExhaustedException) {
            $waited = (hrtime(true) - $started) / 1e9;
            self::assertGreaterThanOrEqual(0.25, $waited);
            self::assertLessThan(1.25, $waited);
        }

        $held->close();

        self::assertSame($sid, self::sid($this->connect(...$fields)));
    }

    /**
     * Pool and lifecycle 7: the growth.
     */
    #[Test]
    public function the_pool_grows_one_session_at_a_time_up_to_max_connections(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 3, 'acquireTimeout' => 0.25];
        $sids = [];
        for ($i = 0; $i < 3; ++$i) {
            $sids[] = self::sid($this->connect(...$fields));
        }

        self::assertCount(3, array_unique($sids));
        $this->expectException(PoolExhaustedException::class);
        $this->connect(...$fields);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fieldsOutsideThePoolKey(): iterable
    {
        yield 'values' => [['values' => new ValueMapping(numbers: NumberMode::Float)]];
        yield 'rowShape' => [['rowShape' => RowShape::List]];
        yield 'keyCase' => [['keyCase' => KeyCase::Lower]];
        yield 'tracing' => [['tracing' => new Tracing(module: 'another module')]];
        yield 'callTimeout' => [['callTimeout' => 30]];
        yield 'fetchSize' => [['fetchSize' => 7]];
    }

    /**
     * Pool and lifecycle 13 (and Native surface 4: a `$session` difference
     * alone shares the Pool). With the Pool's one Session held, a config
     * that shares the Pool finds it exhausted; once it is free, that config
     * gets the same Session.
     *
     * @param array<string, mixed> $difference
     */
    #[Test]
    #[DataProvider('fieldsOutsideThePoolKey')]
    public function configs_differing_only_outside_the_pool_key_share_one_pool(array $difference): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $held = $this->connect(...$fields);
        $sid = self::sid($held);

        try {
            $this->connect(...$difference, ...$fields);
            self::fail('The config got a Pool of its own.');
        } catch (PoolExhaustedException) {
            $this->addToAssertionCount(1);
        }

        $held->close();

        self::assertSame($sid, self::sid($this->connect(...$difference, ...$fields)));
    }

    /**
     * Pool and lifecycle 13: the Pool-key part.
     */
    #[Test]
    public function a_different_statement_cache_size_or_password_gives_another_pool(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1, 'acquireTimeout' => 0.25];
        $held = $this->connect(...$fields);

        self::assertNotSame(self::sid($held), self::sid($this->connect(...['statementCacheSize' => 7] + $fields)));

        $held->close();
        try {
            // The first Pool's Session is free now: a shared Pool would hand
            // it over, while a Pool of its own logs on and fails.
            $this->connect(...['password' => 'not-the-password'] + $fields);
            self::fail('A wrong password got a Session.');
        } catch (ConnectionException $e) {
            self::assertSame(ConnectionException::class, $e::class);
            self::assertSame(1017, $e->getCode());
        }
    }

    /**
     * Pool and lifecycle 1, across the Pool: two Connections held at once
     * never share a Session.
     */
    #[Test]
    public function connections_held_at_once_never_share_a_session(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 2];
        $first = $this->connect(...$fields);
        $second = $this->connect(...$fields);

        self::assertNotSame(self::sid($first), self::sid($second));
    }

    /**
     * Scope 6, the ping: after 60 s idle, an acquire pings each free
     * Session first. The live one comes back; the killed one is dropped and
     * replaced, so no Connection gets a dead Session.
     */
    #[Test]
    #[Group('slow')]
    public function a_session_idle_for_60_seconds_is_pinged_on_acquire_and_dropped_if_the_ping_fails(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 2];
        $live = $this->connect(...$fields);
        $killed = $this->connect(...$fields);
        $liveSid = self::sid($live);
        $killedSid = self::sid($killed);
        $live->close();
        $killed->close();

        $observer = IndependentSession::open();
        try {
            $serial = $observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $killedSid])[0]['SERIAL'];
            $observer->execute("alter system kill session '$killedSid,$serial' immediate");
        } finally {
            $observer->close();
        }

        sleep(61);

        $sids = [self::sid($this->connect(...$fields)), self::sid($this->connect(...$fields))];
        self::assertContains($liveSid, $sids, 'The live Session came back after its ping.');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function releases(): iterable
    {
        yield 'destroying the Connection' => ['destroy', false];
        yield 'close()' => ['close', false];
        yield 'a Standalone Connection' => ['close', true];
    }

    /**
     * Pool and lifecycle 2. Destroying the Connection releases it in the
     * native free path, in the middle of the request: no request end (no
     * RSHUTDOWN, which FrankenPHP's worker mode skips) is involved.
     */
    #[Test]
    #[DataProvider('releases')]
    public function a_release_rolls_back_and_never_commits(string $release, bool $standalone): void
    {
        $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config(...self::ownPool(), standalone: $standalone));
        $connection->execute("update fx_items set name = 'uncommitted' where id = 41", [], self::options(autocommit: false));
        self::assertTrue(self::locked(41), 'The update holds the row.');

        if ($release === 'destroy') {
            unset($connection);
        } else {
            $connection->close();
        }

        self::assertFalse(self::locked(41), 'The Release rolled back.');
        $observer = IndependentSession::open();
        try {
            self::assertSame([['NAME' => 'Item 0041']], $observer->select('select name from fx_items where id = 41'));
        } finally {
            $observer->close();
        }
    }

    /**
     * Pool and lifecycle 3.
     *
     * @return iterable<string, array{Closure(DriverConnection): mixed}>
     */
    public static function connectionCalls(): iterable
    {
        yield 'execute()' => [static fn(DriverConnection $c): DriverExecution => $c->execute('select 1 from dual', [], self::options())];
        yield 'executeMany()' => [static fn(DriverConnection $c): DriverArrayDml => $c->executeMany('insert into fx_batch_rows (id) values (:id)', [['id' => 1]], self::options())];
        yield 'commit()' => [static fn(DriverConnection $c) => $c->commit()];
        yield 'rollback()' => [static fn(DriverConnection $c) => $c->rollback()];
        yield 'trace()' => [static fn(DriverConnection $c) => $c->trace(new Tracing(module: 'after close'))];
        yield 'serverVersion()' => [static fn(DriverConnection $c): string => $c->serverVersion()];
    }

    /**
     * Pool and lifecycle 3: every call after `close()` fails, and a second
     * `close()` is fine.
     *
     * @param Closure(DriverConnection): mixed $call
     */
    #[Test]
    #[DataProvider('connectionCalls')]
    public function every_call_after_close_fails_as_usage(Closure $call): void
    {
        $connection = $this->connect();
        $connection->close();

        try {
            $call($connection);
            self::fail('A call after close() succeeded.');
        } catch (UsageException $e) {
            self::assertSame('The Connection is closed.', $e->getMessage());
        }
        $connection->close();
    }

    /**
     * Pool and lifecycle 4.
     */
    #[Test]
    public function a_live_cursor_delays_the_release_and_freeing_it_then_rolls_back(): void
    {
        $connection = $this->driver()->connect(TestDatabase::fromEnvironment()->config());
        $connection->execute("update fx_items set name = 'uncommitted' where id = 42", [], self::options(autocommit: false));
        $cursor = $connection->execute('select level as n from dual connect by level <= 5', [], self::options(fetchSize: 2, autocommit: false))->cursor();
        self::assertNotNull($cursor);
        self::assertSame([['N' => 1], ['N' => 2]], $cursor->fetchBatch());

        unset($connection);

        self::assertTrue(self::locked(42), 'The Cursor keeps the Connection, and its transaction.');
        self::assertSame([['N' => 3], ['N' => 4]], $cursor->fetchBatch());
        self::assertSame([['N' => 5]], $cursor->fetchBatch());
        self::assertSame([], $cursor->fetchBatch());

        unset($cursor);

        self::assertFalse(self::locked(42), 'Freeing the last Cursor released the Connection, rolling back.');
    }

    /**
     * Pool and lifecycle 5, the pooled part: Release resets nothing, so the
     * Session's next owner sees its package state (documented).
     */
    #[Test]
    public function package_state_survives_a_pooled_release(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $first = $this->connect(...$fields);
        $session = self::session($first);
        self::assertSame(1, self::value($first, 'select fx_pool.bump_counter from dual'));
        $first->close();

        $second = $this->connect(...$fields);

        self::assertSame($session, self::session($second));
        self::assertSame(2, self::value($second, 'select fx_pool.bump_counter from dual'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function kills(): iterable
    {
        yield 'kill immediate' => [true];
        yield 'kill' => [false];
    }

    /**
     * Pool and lifecycle 6 and Errors 6: the call that sees the loss throws
     * ConnectionLostException with the original code and message; every
     * later call throws it with the same code and "Connection is broken:
     * <original message>". Release discards the Session.
     */
    #[Test]
    #[DataProvider('kills')]
    public function a_killed_session_makes_the_connection_broken_and_never_comes_back(bool $immediate): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        // The Session's second loan: the crate ends the first one with a
        // request-boundary rollback of its own, which would hide a Session
        // going back to the Pool.
        $this->connect(...$fields)->close();
        $connection = $this->connect(...$fields);
        $session = self::session($connection);
        $cursor = $connection->execute('select level as n from dual connect by level <= 5', [], self::options(fetchSize: 2))->cursor();
        self::assertNotNull($cursor);
        $cursor->fetchBatch();
        self::kill($connection, $immediate);

        try {
            self::value($connection, 'select 1 from dual');
            self::fail('A call on a killed Session succeeded.');
        } catch (ConnectionLostException $lost) {
            self::assertStringStartsNotWith('Connection is broken: ', $lost->getMessage());
        }
        foreach ([
            'execute()' => static fn(): DriverExecution => $connection->execute('select 1 from dual', [], self::options()),
            'commit()' => static fn() => $connection->commit(),
            'rollback()' => static fn() => $connection->rollback(),
            'serverVersion()' => static fn(): string => $connection->serverVersion(),
            'fetchBatch()' => static fn(): array => $cursor->fetchBatch(),
            'columns()' => static fn(): array => $cursor->columns(),
        ] as $what => $call) {
            try {
                $call();
                self::fail("$what on a Broken Connection succeeded.");
            } catch (ConnectionLostException $later) {
                self::assertSame($lost->getCode(), $later->getCode(), $what);
                self::assertSame('Connection is broken: ' . $lost->getMessage(), $later->getMessage(), $what);
            }
        }

        $cursor->close();
        $connection->close();

        $next = $this->connect(...$fields);
        self::assertNotSame($session, self::session($next), 'The killed Session came back.');
    }

    /**
     * @param callable(): bool $condition
     */
    private static function eventually(callable $condition, float $seconds = 5): bool
    {
        $deadline = microtime(true) + $seconds;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(50_000);
        }

        return true;
    }
}
