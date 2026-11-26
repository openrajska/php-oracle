<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Override;
use Rajska\Oracle\Contracts\Driver\ArrayDml;

/**
 * @internal
 */
final readonly class ExtensionArrayDml implements ArrayDml
{
    /**
     * @param array<string, list<list<mixed>>> $outValues
     */
    public function __construct(private int $rowCount, private array $outValues)
    {
    }

    #[Override]
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    #[Override]
    public function outValues(): array
    {
        return $this->outValues;
    }
}
