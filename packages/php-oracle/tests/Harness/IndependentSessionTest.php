<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Tests\Support\IndependentSession;
use RuntimeException;

final class IndependentSessionTest extends TestCase
{
    /** @var list<IndependentSession> */
    private array $sessions = [];

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            $session->rollback();
            $session->close();
        }

        $cleanup = $this->open();
        $cleanup->execute("delete from fx_batch_rows where id between 9000 and 9099");
        $cleanup->commit();
        $cleanup->close();
    }

    #[Test]
    public function each_one_has_its_own_session(): void
    {
        self::assertNotSame($this->open()->sid(), $this->open()->sid());
    }

    #[Test]
    public function it_sees_only_what_another_session_committed(): void
    {
        $writer = $this->open();
        $reader = $this->open();
        $count = "select count(*) as n from fx_batch_rows where code = 'visible'";

        $writer->execute("insert into fx_batch_rows (id, code, qty) values (9001, 'visible', 1)");
        self::assertSame([['N' => '0']], $reader->select($count));

        $writer->commit();
        self::assertSame([['N' => '1']], $reader->select($count));
    }

    #[Test]
    public function it_binds_by_name(): void
    {
        self::assertSame(
            [['ANSWER' => '42']],
            $this->open()->select('select :value + 1 as answer from dual', ['value' => 41]),
        );
    }

    #[Test]
    public function it_fails_on_a_row_another_session_locked(): void
    {
        $holder = $this->open();
        $holder->execute("insert into fx_batch_rows (id, code, qty) values (9002, 'locked', 1)");
        $holder->commit();
        $holder->execute('update fx_batch_rows set qty = 2 where id = 9002');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(54);
        $this->expectExceptionMessage('ORA-00054');

        $this->open()->select('select id from fx_batch_rows where id = 9002 for update nowait');
    }

    private function open(): IndependentSession
    {
        return $this->sessions[] = IndependentSession::open();
    }
}
