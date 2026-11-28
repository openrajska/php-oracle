<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;

/**
 * The fully resolved options Core passes on every call: the user's
 * `Statement\Options` resolved against the `ConnectionConfig`, plus
 * `autocommit` from Core's Explicit-transaction flag.
 *
 * @internal
 */
final readonly class Options
{
    /**
     * @param float|int|null $timeout seconds per Round trip; null = no limit
     */
    public function __construct(
        public bool $autocommit,
        public float | int | null $timeout,
        public int $fetchSize,
        public ValueMapping $values,
        public RowShape $rowShape,
        public KeyCase $keyCase,
    ) {
    }

    /**
     * These options, committing with each execute or not.
     */
    public function withAutocommit(bool $autocommit): self
    {
        return $autocommit === $this->autocommit
            ? $this
            : new self($autocommit, $this->timeout, $this->fetchSize, $this->values, $this->rowShape, $this->keyCase);
    }
}
