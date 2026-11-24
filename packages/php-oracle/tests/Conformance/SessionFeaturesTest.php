<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

/**
 * Conformance catalog, Session features (#28), and the Fetch size cases of
 * Seam and rows 5 and Native surface 6.
 */
final class SessionFeaturesTest extends ConformanceTestCase
{
    private const string ROWS = 'select level n from dual connect by level <= :n';

    private RoundTripCounter $counter;

    protected function setUp(): void
    {
        $this->counter = RoundTripCounter::open();
    }

    protected function tearDown(): void
    {
        $this->counter->close();
        parent::tearDown();
    }

    /**
     * Session features 4: with the default Fetch size, a query of up to 100
     * rows costs one Round trip, its execute included.
     */
    #[Test]
    public function the_default_fetch_size_reads_up_to_100_rows_in_one_round_trip(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        foreach ([1, 99, 100] as $count) {
            $rows = [];
            self::assertSame(1, $this->counter->during($sid, function () use ($connection, $count, &$rows): void {
                $rows = $this->readAll($connection, $count, self::options());
            }), "$count rows");
            self::assertSame([$count], array_map('count', $rows), "$count rows");
        }
    }

    /**
     * Session features 4 and Seam and rows 5: `fetchSize: 10` on 25 rows
     * takes 3 Round trips with batches of at most 10, each `fetchBatch()` at
     * most one, and `[]` at the end and on every later call, with none.
     */
    #[Test]
    public function a_fetch_size_of_10_reads_25_rows_in_3_round_trips(): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);
        $cursor = null;
        $trips = [];

        $trips[] = $this->counter->during($sid, function () use ($connection, &$cursor): void {
            $cursor = $connection->execute(self::ROWS, ['n' => 25], self::options(fetchSize: 10))->cursor();
        });
        self::assertNotNull($cursor);
        $sizes = [];
        for ($i = 0; $i < 5; ++$i) {
            $trips[] = $this->counter->during($sid, function () use ($cursor, &$sizes): void {
                $sizes[] = \count($cursor->fetchBatch());
            });
        }

        self::assertSame([10, 10, 5, 0, 0], $sizes);
        self::assertSame(3, array_sum($trips));
        self::assertSame([0, 0], \array_slice($trips, 4), 'no Round trip at the end');
        self::assertLessThanOrEqual(1, max($trips));
    }

    /**
     * Native surface 6, query part: the `fetchSize` of an execute governs
     * its Cursor, whatever the next execute on the Session asks for.
     */
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
