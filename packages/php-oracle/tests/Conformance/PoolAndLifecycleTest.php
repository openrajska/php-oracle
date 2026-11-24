<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Tests\Support\IndependentSession;

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
}
