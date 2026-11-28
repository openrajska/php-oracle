<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Override;
use Rajska\Oracle\Contracts\Driver\Cursor;
use Rajska\Oracle\Contracts\Driver\Response;

/**
 * @internal
 */
final readonly class ExtensionResponse implements Response
{
    /**
     * @param array<string, list<mixed>> $outValues
     * @param list<Cursor> $implicitResults
     */
    public function __construct(
        private int $rowCount,
        private Cursor | null $cursor,
        private array $outValues,
        private array $implicitResults,
        private string | null $warning,
    ) {
    }

    #[Override]
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    #[Override]
    public function cursor(): Cursor | null
    {
        return $this->cursor;
    }

    #[Override]
    public function outValues(): array
    {
        return $this->outValues;
    }

    #[Override]
    public function implicitResults(): array
    {
        return $this->implicitResults;
    }

    #[Override]
    public function warning(): string | null
    {
        return $this->warning;
    }
}
