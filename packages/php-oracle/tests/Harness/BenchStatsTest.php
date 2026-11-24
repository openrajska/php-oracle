<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bench\Stats;

final class BenchStatsTest extends TestCase
{
    #[Test]
    public function the_median_of_an_odd_count_is_the_middle_sample(): void
    {
        self::assertSame(3.0, Stats::median([5, 1, 3]));
    }

    #[Test]
    public function the_median_of_an_even_count_is_the_mean_of_the_two_middle_samples(): void
    {
        self::assertSame(2.5, Stats::median([4, 1, 3, 2]));
    }

    #[Test]
    public function a_percentile_is_the_nearest_rank_below(): void
    {
        $samples = range(1, 100);

        self::assertSame(95.0, Stats::percentile($samples, 95));
        self::assertSame(1.0, Stats::percentile($samples, 0));
        self::assertSame(100.0, Stats::percentile($samples, 100));
    }

    #[Test]
    public function the_spread_is_the_lowest_and_highest_per_round_median(): void
    {
        self::assertSame([2.0, 20.0], Stats::spread([[1, 2, 3], [10, 20, 30], [5]]));
    }

    #[Test]
    public function an_empty_sample_set_has_no_median(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Stats::median([]);
    }
}
