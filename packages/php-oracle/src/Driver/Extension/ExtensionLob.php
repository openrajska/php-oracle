<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Driver\DriverLob;
use Rajska\Oracle\Extension\Exception as NativeException;
use Rajska\Oracle\Extension\Lob as NativeLob;
use Rajska\Oracle\LobType;

/**
 * @internal
 */
final readonly class ExtensionLob implements DriverLob
{
    /**
     * @param string $sql the SQL of the Execution the LOB was read from, for `sql()`
     */
    public function __construct(private NativeLob $native, private string $sql) {}

    public function type(): LobType
    {
        try {
            return LobType::from($this->native->type());
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }

    public function size(): int
    {
        try {
            return $this->native->size();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }

    public function readChunk(): string
    {
        try {
            return $this->native->readChunk();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }

    public function close(): void
    {
        try {
            $this->native->close();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }
}
