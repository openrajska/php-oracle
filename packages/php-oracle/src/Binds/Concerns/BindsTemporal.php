<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use SensitiveParameter;

/**
 * The constructor of the timestamp binds. The date is copied once, here, into
 * a `DateTimeImmutable`, so a `DateTime` changed afterwards does not change
 * the bind.
 *
 * @internal
 */
trait BindsTemporal
{
    public function __construct(#[SensitiveParameter] DateTimeInterface | null $value)
    {
        parent::__construct($value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : null);
    }
}
