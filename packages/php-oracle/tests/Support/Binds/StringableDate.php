<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Support\Binds;

use DateTimeImmutable;
use Override;
use Stringable;

/**
 * A date that is also `Stringable`, as Carbon is: it must fail as a
 * DateTimeInterface, never bind as its text.
 */
final class StringableDate extends DateTimeImmutable implements Stringable
{
    #[Override]
    public function __toString(): string
    {
        return $this->format('Y-m-d H:i:s');
    }
}
