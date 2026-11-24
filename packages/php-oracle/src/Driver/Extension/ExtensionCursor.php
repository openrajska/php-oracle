<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Extension\Cursor as NativeCursor;
use Rajska\Oracle\Extension\Exception as NativeException;

/**
 * @internal
 */
final class ExtensionCursor implements DriverCursor
{
    /**
     * @param string $sql the SQL of the Execution the Cursor was born of, for `sql()`
     */
    public function __construct(private readonly NativeCursor $native, private readonly string $sql)
    {
    }

    public function columns(): array
    {
        try {
            return $this->native->columns();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $this->sql);
        }
    }

    public function fetchBatch(): array
    {
        try {
            return $this->native->fetchBatch();
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
