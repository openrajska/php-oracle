<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\IndependentSessionTest;

use Rajska\Oracle\Tests\Support\IndependentSession;
use RuntimeException;

beforeEach(function (): void {
    $this->sessions = [];
});

afterEach(function (): void {
    /** @var list<IndependentSession> $sessions */
    $sessions = $this->sessions;
    foreach ($sessions as $session) {
        $session->rollback();
        $session->close();
    }

    $cleanup = open();
    $cleanup->execute("delete from fx_batch_rows where id between 9000 and 9099");
    $cleanup->commit();
    $cleanup->close();
});

test('each one has its own session', function (): void {
    expect(open()->sid())->not->toBe(open()->sid());
});

it('sees only what another session committed', function (): void {
    $writer = open();
    $reader = open();
    $count = "select count(*) as n from fx_batch_rows where code = 'visible'";

    $writer->execute("insert into fx_batch_rows (id, code, qty) values (9001, 'visible', 1)");
    expect($reader->select($count))->toBe([['N' => '0']]);

    $writer->commit();
    expect($reader->select($count))->toBe([['N' => '1']]);
});

it('binds by name', function (): void {
    expect(open()->select('select :value + 1 as answer from dual', ['value' => 41]))->toBe([['ANSWER' => '42']]);
});

it('fails on a row another session locked', function (): void {
    $holder = open();
    $holder->execute("insert into fx_batch_rows (id, code, qty) values (9002, 'locked', 1)");
    $holder->commit();
    $holder->execute('update fx_batch_rows set qty = 2 where id = 9002');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionCode(54);
    $this->expectExceptionMessage('ORA-00054');

    open()->select('select id from fx_batch_rows where id = 9002 for update nowait');
});

function open(): IndependentSession
{
    $session = IndependentSession::open();
    $test = test();
    $test->sessions = [...$test->sessions, $session];

    return $session;
}
