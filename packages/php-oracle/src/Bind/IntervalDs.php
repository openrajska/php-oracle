<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Type;

/**
 * INTERVAL DAY TO SECOND, from a `DateInterval` of days, hours, minutes,
 * seconds and microseconds (`invert` for a negative one). Years and months
 * have no fixed length in days, so they are rejected: use `IntervalYm`.
 * Read back, the interval is a `DateInterval` normalised to days, hours,
 * minutes and seconds.
 */
final readonly class IntervalDs extends Bind
{
    /**
     * @throws UsageException if the interval has years or months
     */
    public static function in(?\DateInterval $value): self
    {
        return new self(Type::IntervalDs, Direction::In, self::checked($value));
    }

    public static function out(): self
    {
        return new self(Type::IntervalDs, Direction::Out, null);
    }

    /**
     * @throws UsageException if the interval has years or months
     */
    public static function inOut(?\DateInterval $value): self
    {
        return new self(Type::IntervalDs, Direction::InOut, self::checked($value));
    }

    private static function checked(?\DateInterval $value): ?\DateInterval
    {
        if ($value !== null && ($value->y !== 0 || $value->m !== 0)) {
            throw new UsageException('IntervalDs::in() takes days and smaller units, but the DateInterval has years or months; bind those with IntervalYm::in().');
        }

        return $value === null ? null : clone $value;
    }
}
