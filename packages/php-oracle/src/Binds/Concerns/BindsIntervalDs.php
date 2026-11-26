<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use DateInterval;
use Rajska\Oracle\Exceptions\UsageException;
use SensitiveParameter;

/**
 * The constructor of the INTERVAL DAY TO SECOND binds.
 *
 * @internal
 */
trait BindsIntervalDs
{
    /**
     * @throws UsageException if the interval has years or months
     */
    public function __construct(#[SensitiveParameter] DateInterval | null $value)
    {
        if ($value instanceof DateInterval && ($value->y !== 0 || $value->m !== 0)) {
            throw new UsageException('An INTERVAL DAY TO SECOND bind takes days and smaller units, but the DateInterval has years or months; bind those with In\\IntervalYm.');
        }
        parent::__construct($value instanceof DateInterval ? clone $value : null);
    }
}
