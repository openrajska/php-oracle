<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\ArrayDml;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ArrayDmlException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\OutValue;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;

/**
 * Array DML: `runMany()` sends every row in one Round trip, gives a
 * `ArrayDml`, and throws one `ArrayDmlException` listing every failing row,
 * none of that call's rows applied (fixtures/sql/06_batch.sql,
 * 11_transaction.sql).
 */
final class ArrayDmlTest extends TestCase
{
    private const string INSERT = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';

    private static IndependentSession $observer;

    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        self::$observer = IndependentSession::open();
    }

    public static function tearDownAfterClass(): void
    {
        self::$observer->close();
    }

    protected function setUp(): void
    {
        self::clear();
        $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        self::clear();
    }

    #[Test]
    public function run_many_gives_an_array_dml_and_commits_under_autocommit(): void
    {
        $arrayDml = $this->connection->runMany(self::INSERT, self::valid(3));

        self::assertInstanceOf(ArrayDml::class, $arrayDml);
        self::assertSame(3, $arrayDml->affected());
        self::assertCount(3, $arrayDml);
        self::assertSame(3, self::committed());

        $updated = $this->connection->runMany('update fx_batch_rows set qty = 0 where id >= :low', [['low' => 2], ['low' => 1]]);
        self::assertSame(2, $updated->count());
        self::assertSame(5, $updated->affected());
    }

    #[Test]
    public function out_gives_one_out_value_per_row_in_order(): void
    {
        $this->connection->runMany(self::INSERT, self::valid(3));

        $returning = $this->connection->runMany('update fx_batch_rows set qty = qty + 1 where id >= :low returning id into :ids', [
            ['low' => 3, 'ids' => Number::out()],
            ['low' => 9, 'ids' => Number::out()],
        ]);
        $block = $this->connection->runMany('begin :label := :prefix || :n; end;', [
            ['n' => 1, 'prefix' => 'a', 'label' => Varchar::out()],
            ['n' => 2, 'prefix' => 'b', 'label' => Varchar::out()],
        ]);

        $ids = $returning->out('IDS');
        self::assertSame([[3], []], array_map(static fn(OutValue $value): array => $value->list(), $ids));
        self::assertSame(3, $ids[0]->int());
        self::assertSame(['a1', 'b2'], array_map(static fn(OutValue $value): ?string => $value->string(), $block->out('label')));
    }

    /**
     * So does any name on an empty ArrayDml, which has no OUT values.
     */
    #[Test]
    public function out_with_an_unknown_or_an_in_only_name_fails(): void
    {
        $arrayDml = $this->connection->runMany('begin :label := :prefix; end;', [['prefix' => 'a', 'label' => Varchar::out()]]);

        foreach (['missing' => "No OUT bind is named 'missing'; they are 'label'.", 'PREFIX' => "Bind 'prefix' is IN only, so it has no OUT value; bind it with out() or inOut() of a typed bind to read it back."] as $name => $message) {
            try {
                $arrayDml->out($name);
                self::fail("out('$name') did not fail.");
            } catch (UsageException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }

        $empty = $this->connection->runMany(self::INSERT, []);
        self::assertCount(0, $empty);
        self::assertSame(0, $empty->affected());
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("No OUT bind is named 'anything'; the statement has none.");
        $empty->out('anything');
    }

    #[Test]
    public function failing_rows_inside_begin_undo_only_that_call(): void
    {
        $this->connection->begin();
        $this->connection->run('insert into fx_tx_rows (id) values (1)');
        $rows = self::valid(8);
        $rows[3]['id'] = 1;
        $rows[5]['code'] = null;
        $rows[7]['qty'] = -1;

        try {
            $this->connection->runMany(self::INSERT, $rows);
            self::fail('The bad rows were inserted.');
        } catch (ArrayDmlException $e) {
            self::assertSame(1, $e->getCode());
            self::assertSame(self::INSERT, $e->sql());
            self::assertStringStartsWith('3 of 8 rows failed; first: row 3: ORA-00001', $e->getMessage());
            self::assertSame([[3, 1], [5, 1400], [7, 2290]], array_map(static fn(RowFailure $row): array => [$row->index, $row->code], $e->rows()));
        }
        $this->connection->runMany(self::INSERT, self::valid(2));
        self::assertSame(0, self::committed());
        $this->connection->commit();

        self::assertSame(2, self::committed());
        self::assertSame('1', self::$observer->select('select count(*) as n from fx_tx_rows')[0]['N']);
    }

    #[Test]
    public function a_bound_value_never_appears_in_a_run_many_error(): void
    {
        $secret = 'zq60secret';
        $calls = [
            'row errors' => fn(): ArrayDml => $this->connection->runMany(self::INSERT, [['id' => 1, 'code' => $secret, 'qty' => 1], ['id' => 2, 'code' => $secret, 'qty' => 1]]),
            'refused row' => fn(): ArrayDml => $this->connection->runMany(self::INSERT, [['id' => 1, 'code' => 'a', 'qty' => 1], ['id' => 2, 'code' => 'b', 'qty' => $secret]]),
        ];
        foreach ($calls as $kind => $call) {
            try {
                $call();
                self::fail("$kind: the rows were inserted.");
            } catch (OracleException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage(), $kind);
                self::assertStringNotContainsString($secret, $e->getTraceAsString(), $kind);
                self::assertStringNotContainsString($secret, (string) $e, $kind);
                if ($e instanceof ArrayDmlException) {
                    foreach ($e->rows() as $row) {
                        self::assertStringNotContainsString($secret, $row->message, $kind);
                    }
                }
            }
        }
    }

    #[Test]
    public function run_many_after_close_fails(): void
    {
        $this->connection->close();

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('The Connection is closed.');
        $this->connection->runMany(self::INSERT, []);
    }

    /**
     * @return list<array{id: int, code: string, qty: int}>
     */
    private static function valid(int $count): array
    {
        return array_map(static fn(int $id): array => ['id' => $id, 'code' => "c$id", 'qty' => $id], range(1, $count));
    }

    private static function committed(): int
    {
        return (int) self::$observer->select('select count(*) as n from fx_batch_rows')[0]['N'];
    }

    private static function clear(): void
    {
        self::$observer->execute('delete from fx_batch_rows');
        self::$observer->execute('delete from fx_tx_rows');
        self::$observer->commit();
    }
}
