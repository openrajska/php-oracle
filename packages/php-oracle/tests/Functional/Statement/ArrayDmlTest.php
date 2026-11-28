<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Statement\ArrayDmlTest;

use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Exceptions\ArrayDmlFailedException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\OutValue;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    schema()->run(<<<'SQL'
        create table batch_rows (
            id number(10) primary key,
            code varchar2(10) not null unique,
            qty number(10) not null check (qty >= 0)
        )
        SQL);
    schema()->run('create table array_dml_marks (id number(10) primary key)');


    $this->connection = $this->connect();
});

describe('running many rows', function (): void {
    it('runs the statement once per row and commits under autocommit', function (): void {
        $arrayDml = $this->connection->runMany(INSERT, rows(3));

        expect($arrayDml->affected())->toBe(3)
            ->and($arrayDml)->toHaveCount(3)
            ->and(otherSession()->select('select count(*) from batch_rows')->value())->toBe(3);
    });

    it('sums the rows every bind row touched', function (): void {
        $this->connection->runMany(INSERT, rows(3));

        $arrayDml = $this->connection->runMany('update batch_rows set qty = 0 where id >= :low', [['low' => 2], ['low' => 1]]);

        expect($arrayDml)->toHaveCount(2)
            ->and($arrayDml->affected())->toBe(5);
    });

    it('gives an empty result for no rows', function (): void {
        $arrayDml = $this->connection->runMany(INSERT, []);

        expect($arrayDml)->toBeEmpty()
            ->and($arrayDml->affected())->toBe(0);
    });
});

describe('out values', function (): void {
    it('gives one value per row in order from returning into', function (): void {
        $this->connection->runMany(INSERT, rows(3));

        $arrayDml = $this->connection->runMany('update batch_rows set qty = qty + 1 where id >= :low returning id into :ids', [
            ['low' => 3, 'ids' => new Out\Number()],
            ['low' => 9, 'ids' => new Out\Number()],
        ]);

        $ids = $arrayDml->out('IDS');
        expect(array_map(static fn (OutValue $value): array => $value->list(), $ids))->toBe([[3], []])
            ->and($ids[0]->int())->toBe(3);
    });

    it('gives one value per row in order from a block', function (): void {
        $arrayDml = $this->connection->runMany('begin :label := :prefix || :n; end;', [
            ['n' => 1, 'prefix' => 'a', 'label' => new Out\Varchar()],
            ['n' => 2, 'prefix' => 'b', 'label' => new Out\Varchar()],
        ]);

        $labels = $arrayDml->out('label');

        expect(array_map(static fn (OutValue $value): string | null => $value->string(), $labels))->toBe(['a1', 'b2']);
    });

    it('fails naming the fix for a name with no out value', function (string $name, string $message): void {
        $arrayDml = $this->connection->runMany('begin :label := :prefix; end;', [['prefix' => 'a', 'label' => new Out\Varchar()]]);

        $read = fn () => $arrayDml->out($name);

        expect($read)->toThrow(UsageException::class, $message);
    })->with([
        'an unknown name' => ['missing', "No OUT bind is named 'missing'; they are 'label'."],
        'an IN only name' => ['PREFIX', "Bind 'prefix' is IN only, so it has no OUT value; bind it with an Out\\ or InOut\\ class to read it back."],
    ]);

    it('says the statement has no out binds when no rows ran', function (): void {
        $arrayDml = $this->connection->runMany(INSERT, []);

        $read = fn () => $arrayDml->out('anything');

        expect($read)->toThrow(UsageException::class, "No OUT bind is named 'anything'; the statement has none.");
    });
});

describe('failing rows', function (): void {
    it('throws one exception listing each failing row', function (): void {
        $rows = rows(8);
        $rows[3]['id'] = 1;
        $rows[5]['code'] = null;
        $rows[7]['qty'] = -1;

        $runMany = fn () => $this->connection->runMany(INSERT, $rows);

        expect($runMany)->toThrow(function (ArrayDmlFailedException $arrayDmlFailedException): void {
            expect($arrayDmlFailedException->getCode())->toBe(1)
                ->and($arrayDmlFailedException->sql())->toBe(INSERT)
                ->and($arrayDmlFailedException->getMessage())->toStartWith('3 of 8 rows failed; first: row 3: ORA-00001')
                ->and(array_map(static fn (RowFailure $row): array => [$row->index, $row->code], $arrayDmlFailedException->rows()))->toBe([[3, 1], [5, 1400], [7, 2290]]);
        });
    });

    it('undoes only that call inside a transaction', function (): void {
        $this->connection->begin();
        $this->connection->run('insert into array_dml_marks (id) values (1)');

        $rows = rows(2);
        $rows[1]['id'] = 1;
        expect(fn () => $this->connection->runMany(INSERT, $rows))->toThrow(ArrayDmlFailedException::class);
        $this->connection->runMany(INSERT, [['id' => 5, 'code' => 'c5', 'qty' => 5]]);

        $this->connection->commit();

        expect(otherSession()->select('select id from batch_rows')->all())->toBe([['ID' => 5]])
            ->and(otherSession()->select('select count(*) from array_dml_marks')->value())->toBe(1);
    });
});

const INSERT = 'insert into batch_rows (id, code, qty) values (:id, :code, :qty)';

/**
 * @return list<array{id: int, code: string|null, qty: int}>
 */
function rows(int $count): array
{
    return array_map(static fn (int $id): array => ['id' => $id, 'code' => "c$id", 'qty' => $id], range(1, $count));
}
