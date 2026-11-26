<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\ArrayDmlTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Exceptions\ArrayDmlFailedException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Statement\ArrayDml;
use Rajska\Oracle\Statement\OutValue;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;

beforeEach(function (): void {
    $this->observer = IndependentSession::open();
    clear($this->observer);
    $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
});

afterEach(function (): void {
    $this->connection->close();
    clear($this->observer);
    $this->observer->close();
});

test('run many gives an array dml and commits under autocommit', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $arrayDml = $this->connection->runMany($insert, valid(3));

    expect($arrayDml->affected())->toBe(3)
        ->and($arrayDml)->toHaveCount(3)
        ->and(committed($this->observer))->toBe(3);

    $updated = $this->connection->runMany('update fx_batch_rows set qty = 0 where id >= :low', [['low' => 2], ['low' => 1]]);
    expect($updated->count())->toBe(2)
        ->and($updated->affected())->toBe(5);
});

test('out gives one out value per row in order', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $this->connection->runMany($insert, valid(3));

    $returning = $this->connection->runMany('update fx_batch_rows set qty = qty + 1 where id >= :low returning id into :ids', [
        ['low' => 3, 'ids' => new Out\Number()],
        ['low' => 9, 'ids' => new Out\Number()],
    ]);
    $block = $this->connection->runMany('begin :label := :prefix || :n; end;', [
        ['n' => 1, 'prefix' => 'a', 'label' => new Out\Varchar()],
        ['n' => 2, 'prefix' => 'b', 'label' => new Out\Varchar()],
    ]);

    $ids = $returning->out('IDS');
    expect(array_map(static fn (OutValue $value): array => $value->list(), $ids))->toBe([[3], []])
        ->and($ids[0]->int())->toBe(3)
        ->and(array_map(static fn (OutValue $value): string | null => $value->string(), $block->out('label')))->toBe(['a1', 'b2']);
});

test('out with an unknown or an in only name fails', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $arrayDml = $this->connection->runMany('begin :label := :prefix; end;', [['prefix' => 'a', 'label' => new Out\Varchar()]]);

    foreach (['missing' => "No OUT bind is named 'missing'; they are 'label'.", 'PREFIX' => "Bind 'prefix' is IN only, so it has no OUT value; bind it with out() or inOut() of a typed bind to read it back."] as $name => $message) {
        expect(function () use ($arrayDml, $name): void {
            $arrayDml->out($name);
            $this->fail("out('$name') did not fail.");
        })->toThrow(UsageException::class, $message);
    }

    $empty = $this->connection->runMany($insert, []);
    expect($empty)->toBeEmpty()
        ->and($empty->affected())->toBe(0)
        ->and(fn () => $empty->out('anything'))->toThrow(UsageException::class, "No OUT bind is named 'anything'; the statement has none.");
});

test('failing rows inside begin undo only that call', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $this->connection->begin();
    $this->connection->run('insert into fx_tx_rows (id) values (1)');

    $rows = valid(8);
    $rows[3]['id'] = 1;
    $rows[5]['code'] = null;
    $rows[7]['qty'] = -1;

    try {
        $this->connection->runMany($insert, $rows);
        $this->fail('The bad rows were inserted.');
    } catch (ArrayDmlFailedException $arrayDmlFailedException) {
        expect($arrayDmlFailedException->getCode())->toBe(1)
            ->and($arrayDmlFailedException->sql())->toBe($insert)
            ->and($arrayDmlFailedException->getMessage())->toStartWith('3 of 8 rows failed; first: row 3: ORA-00001')
            ->and(array_map(static fn (RowFailure $row): array => [$row->index, $row->code], $arrayDmlFailedException->rows()))->toBe([[3, 1], [5, 1400], [7, 2290]]);
    }
    $this->connection->runMany($insert, valid(2));
    expect(committed($this->observer))->toBe(0);
    $this->connection->commit();

    expect(committed($this->observer))->toBe(2)
        ->and($this->observer->select('select count(*) as n from fx_tx_rows')[0]['N'])->toBe('1');
});

test('a bound value never appears in a run many error', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $secret = 'zq60secret';
    $calls = [
        'row errors' => fn (): ArrayDml => $this->connection->runMany($insert, [['id' => 1, 'code' => $secret, 'qty' => 1], ['id' => 2, 'code' => $secret, 'qty' => 1]]),
        'refused row' => fn (): ArrayDml => $this->connection->runMany($insert, [['id' => 1, 'code' => 'a', 'qty' => 1], ['id' => 2, 'code' => 'b', 'qty' => $secret]]),
    ];
    foreach ($calls as $kind => $call) {
        try {
            $call();
            $this->fail("$kind: the rows were inserted.");
        } catch (OracleException $e) {
            expect($e->getMessage())->not->toContain($secret)
                ->and($e->getTraceAsString())->not->toContain($secret)
                ->and((string) $e)->not->toContain($secret);
            if ($e instanceof ArrayDmlFailedException) {
                foreach ($e->rows() as $row) {
                    expect($row->message)->not->toContain($secret);
                }
            }
        }
    }
});

test('run many after close fails', function (): void {
    $insert = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    $this->connection->close();
    expect(fn () => $this->connection->runMany($insert, []))->toThrow(UsageException::class, 'The Connection is closed.');
});

/**
 * @return list<array{id: int, code: string, qty: int}>
 */
function valid(int $count): array
{
    return array_map(static fn (int $id): array => ['id' => $id, 'code' => "c$id", 'qty' => $id], range(1, $count));
}

function committed(IndependentSession $observer): int
{
    return (int) $observer->select('select count(*) as n from fx_batch_rows')[0]['N'];
}

function clear(IndependentSession $observer): void
{
    $observer->execute('delete from fx_batch_rows');
    $observer->execute('delete from fx_tx_rows');
    $observer->commit();
}
