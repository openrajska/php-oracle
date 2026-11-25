<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Driver\DriverExecution;

/**
 * @internal
 */
final readonly class ExtensionExecution implements DriverExecution
{
    /**
     * @param array<string, list<mixed>> $outValues
     * @param list<DriverCursor> $implicitResults
     */
    public function __construct(
        private int $rowCount,
        private DriverCursor | null $cursor,
        private array $outValues,
        private array $implicitResults,
        private string | null $warning,
    ) {
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function cursor(): DriverCursor | null
    {
        return $this->cursor;
    }

    public function outValues(): array
    {
        return $this->outValues;
    }

    public function implicitResults(): array
    {
        return $this->implicitResults;
    }

    public function warning(): string | null
    {
        return $this->warning;
    }
}
