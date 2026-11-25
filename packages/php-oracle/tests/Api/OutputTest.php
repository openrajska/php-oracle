<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;
use Rajska\Oracle\Tests\Support\TestDatabase;
use Rajska\Oracle\Tests\Support\TransactionObserver;

/**
 * DBMS_OUTPUT through `enableOutput()` / `output()` / `disableOutput()`.
 */
final class OutputTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    /**
     * Lines come in batches of 1000, one Round trip each. The first
     * `output()` on a Session also describes the Collection type once; that
     * is left out of the count.
     */
    #[Test]
    public function output_gives_2500_lines_in_order_then_nothing(): void
    {
        $connection = $this->connect();
        $connection->enableOutput();
        self::assertSame([], $connection->output());
        $connection->run("begin for i in 1 .. 2500 loop dbms_output.put_line('line ' || i); end loop; end;");
        $counter = RoundTripCounter::open();

        try {
            $lines = [];
            self::assertSame(3, $counter->during($this->sid($connection), static function () use ($connection, &$lines): void {
                $lines = $connection->output();
            }));
        } finally {
            $counter->close();
        }

        self::assertSame(array_map(static fn(int $i): string => "line $i", range(1, 2500)), $lines);
        self::assertSame([], $connection->output());
    }

    /**
     * An empty line is NULL to PL/SQL.
     */
    #[Test]
    public function lines_come_back_unchanged(): void
    {
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

        self::assertSame([str_repeat('x', 32767), '', '  padded  ', 'ação €', 'in parts'], $connection->output());
    }

    #[Test]
    public function without_enable_output_output_is_empty(): void
    {
        $connection = $this->connect();
        $connection->run("begin dbms_output.put_line('lost'); end;");

        self::assertSame([], $connection->output());
    }

    /**
     * `output()` makes no check of its own.
     */
    #[Test]
    public function output_enabled_through_raw_sql_is_read(): void
    {
        $connection = $this->connect();
        $connection->run("begin dbms_output.enable(null); dbms_output.put_line('raw'); end;");

        self::assertSame(['raw'], $connection->output());
    }

    #[Test]
    public function enable_output_starts_from_an_empty_buffer(): void
    {
        $connection = $this->connect();
        $connection->run("begin dbms_output.enable(null); dbms_output.put_line('before'); end;");

        $connection->enableOutput();
        $connection->run("begin dbms_output.put_line('after'); end;");

        self::assertSame(['after'], $connection->output());
    }

    #[Test]
    public function after_disable_output_output_is_empty(): void
    {
        $connection = $this->connect();
        $connection->enableOutput();
        $connection->run("begin dbms_output.put_line('unread'); end;");

        $connection->disableOutput();
        $connection->run("begin dbms_output.put_line('lost'); end;");

        self::assertSame([], $connection->output());
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function releasesAndNextUsers(): iterable
    {
        yield 'close(), next user enables' => [true, true];
        yield 'close(), next user does not enable' => [true, false];
        yield 'dropped, next user enables' => [false, true];
        yield 'dropped, next user does not enable' => [false, false];
    }

    #[Test]
    #[DataProvider('releasesAndNextUsers')]
    public function unread_output_enabled_through_the_api_is_purged_on_release(bool $close, bool $enable): void
    {
        $first = $this->fresh();
        $session = $this->session($first);
        $first->enableOutput();
        $first->run("begin dbms_output.put_line('secret of A'); end;");
        if ($close) {
            $first->close();
        }
        unset($first);

        $second = $this->next();
        self::assertSame($session, $this->session($second), 'B has A\'s Session.');
        if ($enable) {
            $second->enableOutput();
        }
        $second->run("begin dbms_output.put_line('of B'); end;");

        self::assertSame($enable ? ['of B'] : [], $second->output());
    }

    /**
     * Output turns off at once; the Cursor reads on, and the next user of
     * the Session, after the Cursor is gone, gets `[]`.
     */
    #[Test]
    public function output_is_purged_when_a_connection_is_dropped_before_its_cursor(): void
    {
        $first = $this->fresh();
        $session = $this->session($first);
        $first->enableOutput();
        $first->run("begin dbms_output.put_line('secret of A'); end;");
        $cursor = $first->select('select level n from dual connect by level <= 3', [], new ExecuteOptions(fetchSize: 1));
        $rows = [];
        foreach ($cursor as $row) {
            $rows[] = $row;
            break;
        }
        unset($first);

        self::assertSame([['N' => 1], ['N' => 2], ['N' => 3]], [...$rows, ...$cursor->all()]);
        unset($cursor);

        $second = $this->next();
        self::assertSame($session, $this->session($second), 'B has A\'s Session.');
        self::assertSame([], $second->output());
    }

    /**
     * Release resets nothing else: output enabled through raw SQL stays
     * with the Session, unread lines included, for its next user (a Known
     * limitation; the escape is `standalone: true`).
     */
    #[Test]
    public function output_enabled_through_raw_sql_stays_with_the_session(): void
    {
        $first = $this->fresh();
        $first->run("begin dbms_output.enable(null); dbms_output.put_line('left by A'); end;");
        $first->close();

        $second = $this->next();

        self::assertSame(['left by A'], $second->output());
    }

    /**
     * The DISABLE before the Release fails silently.
     */
    #[Test]
    #[DataProvider('closedOrDropped')]
    public function on_a_killed_session_output_fails_and_the_release_stays_silent(bool $close): void
    {
        $observer = IndependentSession::open();
        try {
            $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(standalone: true));
            $connection->enableOutput();
            $sid = $this->sid($connection);
            $serial = $observer->select('select serial# as serial from v$session where sid = :sid', ['sid' => $sid])[0]['SERIAL'];
            $observer->execute("alter system kill session '$sid,$serial' immediate");

            try {
                $connection->output();
                self::fail('output() read from a killed Session.');
            } catch (ConnectionLostException) {
            }
            if ($close) {
                $connection->close();
            }
            unset($connection);
        } finally {
            $observer->close();
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function closedOrDropped(): iterable
    {
        yield 'close()' => [true];
        yield 'dropped' => [false];
    }

    /**
     * DBMS_OUTPUT calls never commit: inside an Explicit transaction, the
     * work stays uncommitted through them, and the DISABLE before the
     * Release does not commit it either; the Release rolls it back.
     */
    #[Test]
    #[DataProvider('closedOrDropped')]
    public function output_calls_never_commit_an_open_transaction(bool $close): void
    {
        $observer = TransactionObserver::open();
        try {
            $observer->execute("insert into fx_tx_rows (id, note) values (1, 'committed')");
            $note = static fn(): ?string => $observer->select('select note from fx_tx_rows where id = 1')[0]['NOTE'];
            $connection = $this->fresh();
            $connection->begin();
            $connection->run("update fx_tx_rows set note = 'uncommitted' where id = 1");
            $connection->enableOutput();
            $connection->run("begin dbms_output.put_line('in a transaction'); end;");
            self::assertSame(['in a transaction'], $connection->output());
            $connection->disableOutput();
            $connection->enableOutput();
            self::assertSame('committed', $note());
            self::assertTrue($observer->locked(1), 'The transaction is still open.');

            if ($close) {
                $connection->close();
            }
            unset($connection);

            self::assertSame('committed', $note());
            self::assertFalse($observer->locked(1), 'The Release rolled back.');
        } finally {
            $observer->close();
        }
    }

    /**
     * @return iterable<string, array{Closure(Connection): mixed}>
     */
    public static function outputCalls(): iterable
    {
        yield 'enableOutput()' => [static fn(Connection $connection) => $connection->enableOutput()];
        yield 'output()' => [static fn(Connection $connection): array => $connection->output()];
        yield 'disableOutput()' => [static fn(Connection $connection) => $connection->disableOutput()];
    }

    /**
     * @param Closure(Connection): mixed $call
     */
    #[Test]
    #[DataProvider('outputCalls')]
    public function output_calls_after_close_fail(Closure $call): void
    {
        $connection = $this->connect();
        $connection->enableOutput();
        $connection->close();

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('The Connection is closed.');
        $call($connection);
    }

    /**
     * A Connection from a Pool only these tests use, one Session big, so
     * each Connection gets the Session the last one released. One Pool for
     * the file: a Pool keeps its Session for the whole process. tearDown()
     * does not close it.
     */
    private function pooled(): Connection
    {
        return Oracle::connect(TestDatabase::fromEnvironment()->config(maxConnections: 1, connectTimeout: 30.062));
    }

    /**
     * A pooled Connection whose Session has DBMS_OUTPUT off and empty,
     * whatever an earlier test left in it. tearDown() does not close it.
     */
    private function fresh(): Connection
    {
        $connection = $this->pooled();
        $connection->run('begin dbms_output.disable; end;');

        return $connection;
    }

    /**
     * The next user of the Session, as the last Connection left it; closed
     * in tearDown().
     */
    private function next(): Connection
    {
        $connection = $this->pooled();
        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * Which Session is behind a Connection: its AUDSID, unique per logon.
     */
    private function session(Connection $connection): string
    {
        $session = $connection->select("select sys_context('userenv', 'sessionid') from dual")->value();
        self::assertIsString($session);

        return $session;
    }

    private function connect(): Connection
    {
        $connection = $this->fresh();
        $this->connections[] = $connection;

        return $connection;
    }

    private function sid(Connection $connection): int
    {
        $sid = $connection->select("select sys_context('userenv', 'sid') from dual")->value();
        self::assertIsString($sid);

        return (int) $sid;
    }
}
