<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bench;

use InvalidArgumentException;

/**
 * Order statistics over timing samples (nanoseconds).
 */
final class Stats
{
    /**
     * @param list<float|int> $samples
     */
    public static function median(array $samples): float
    {
        $sorted = self::sorted($samples);
        $count = \count($sorted);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Nearest rank below: the sample at floor(p / 100 * (n - 1)).
     *
     * @param list<float|int> $samples
     */
    public static function percentile(array $samples, float $percent): float
    {
        $sorted = self::sorted($samples);

        return (float) $sorted[(int) floor($percent / 100 * (\count($sorted) - 1))];
    }

    /**
     * The lowest and the highest of the per-round medians.
     *
     * @param non-empty-list<list<float|int>> $rounds
     *
     * @return array{float, float}
     */
    public static function spread(array $rounds): array
    {
        $medians = array_map(self::median(...), $rounds);

        return [min($medians), max($medians)];
    }

    /**
     * @param list<float|int> $samples
     *
     * @return non-empty-list<float|int>
     */
    private static function sorted(array $samples): array
    {
        if ($samples === []) {
            throw new InvalidArgumentException('No samples.');
        }
        sort($samples);

        return $samples;
    }
}
