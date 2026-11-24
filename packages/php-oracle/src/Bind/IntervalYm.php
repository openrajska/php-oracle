<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Type;

/**
 * INTERVAL YEAR TO MONTH, from a `DateInterval` of years and months
 * (`invert` for a negative one). Anything below a month is rejected: use
 * `IntervalDs`. Read back, the interval is a `DateInterval` of years and
 * months.
 */
final readonly class IntervalYm extends Bind
{
    /**
     * @throws UsageException if the interval has days or smaller units
     */
    public static function in(?\DateInterval $value): self
    {
        return new self(Type::IntervalYm, Direction::In, self::checked($value));
    }

    public static function out(): self
    {
        return new self(Type::IntervalYm, Direction::Out, null);
    }

    /**
     * @throws UsageException if the interval has days or smaller units
     */
    public static function inOut(?\DateInterval $value): self
    {
        return new self(Type::IntervalYm, Direction::InOut, self::checked($value));
    }

    private static function checked(?\DateInterval $value): ?\DateInterval
    {
        if ($value !== null && ($value->d !== 0 || $value->h !== 0 || $value->i !== 0 || $value->s !== 0 || $value->f !== 0.0)) {
            throw new UsageException('IntervalYm::in() takes years and months only, but the DateInterval has days or smaller units; bind those with IntervalDs::in().');
        }

        return $value === null ? null : clone $value;
    }
}
