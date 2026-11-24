<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\IndependentSession;
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
                self::eventually(static fn (): bool => $observer->select(
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
