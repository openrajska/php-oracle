<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Driver\DriverBatch;

/**
 * @internal
 */
final readonly class ExtensionBatch implements DriverBatch
{
    /**
     * @param array<string, list<mixed>> $outValues
     */
    public function __construct(private int $rowCount, private array $outValues)
    {
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function outValues(): array
    {
        return $this->outValues;
    }
}
