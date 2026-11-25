<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\BenchStatsTest;

use InvalidArgumentException;
use Rajska\Oracle\Bench\Stats;

test('the median of an odd count is the middle sample', function (): void {
    expect(Stats::median([5, 1, 3]))->toBe(3.0);
});
test('the median of an even count is the mean of the two middle samples', function (): void {
    expect(Stats::median([4, 1, 3, 2]))->toBe(2.5);
});
test('a percentile is the nearest rank below', function (): void {
    $samples = range(1, 100);

    expect(Stats::percentile($samples, 95))->toBe(95.0)
        ->and(Stats::percentile($samples, 0))->toBe(1.0)
        ->and(Stats::percentile($samples, 100))->toBe(100.0);
});
test('the spread is the lowest and highest per round median', function (): void {
    expect(Stats::spread([[1, 2, 3], [10, 20, 30], [5]]))->toBe([2.0, 20.0]);
});
test('an empty sample set has no median', function (): void {
    expect(fn(): float => Stats::median([]))->toThrow(InvalidArgumentException::class);
});
