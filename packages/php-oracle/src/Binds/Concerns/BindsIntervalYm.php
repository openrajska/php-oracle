<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use DateInterval;
use Rajska\Oracle\Exceptions\UsageException;
use SensitiveParameter;

/**
 * The constructor of the INTERVAL YEAR TO MONTH binds.
 *
 * @internal
 */
trait BindsIntervalYm
{
    public readonly DateInterval | null $value;

    /**
     * @throws UsageException if the interval has days or smaller units
     */
    public function __construct(#[SensitiveParameter] DateInterval | null $value)
    {
        if ($value instanceof DateInterval && ($value->d !== 0 || $value->h !== 0 || $value->i !== 0 || $value->s !== 0 || $value->f !== 0.0)) {
            throw new UsageException('An INTERVAL YEAR TO MONTH bind takes years and months only, but the DateInterval has days or smaller units; bind those with In\\IntervalDs.');
        }
        $this->value = $value instanceof DateInterval ? clone $value : null;
    }
}
