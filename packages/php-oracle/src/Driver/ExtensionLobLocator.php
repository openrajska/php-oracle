<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Override;
use Rajska\Oracle\Contracts\Driver\Locator;
use Rajska\Oracle\Extension\Exception;
use Rajska\Oracle\Extension\Lob;
use Rajska\Oracle\Lob\Type;

/**
 * @internal
 */
final readonly class ExtensionLobLocator implements Locator
{
    /**
     * @param string $sql the SQL of the Execution the LOB was read from, for `sql()`
     */
    public function __construct(
        private Lob $native,
        private string $sql,
    ) {
    }

    #[Override]
    public function type(): Type
    {
        try {
            return Type::from($this->native->type());
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
    }

    #[Override]
    public function size(): int
    {
        try {
            return $this->native->size();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
    }

    #[Override]
    public function readChunk(): string
    {
        try {
            return $this->native->readChunk();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
    }

    #[Override]
    public function close(): void
    {
        try {
            $this->native->close();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $this->sql);
        }
    }
}
