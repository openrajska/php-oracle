<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Driver\DriverBatch;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Driver\DriverOptions;
use Rajska\Oracle\Extension\Connection as NativeConnection;
use Rajska\Oracle\Extension\Exception as NativeException;
use Rajska\Oracle\Tracing;

/**
 * @internal
 */
final class ExtensionConnection implements DriverConnection
{
    /**
     * The last options passed, and their `$options` array: Core passes the
     * same DriverOptions to every call that sets none.
     */
    private ?DriverOptions $lastOptions = null;

    /**
     * @var array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}|null
     */
    private ?array $lastArray = null;

    public function __construct(private readonly NativeConnection $native)
    {
    }

    public function execute(string $sql, #[\SensitiveParameter] array $binds, DriverOptions $options): DriverExecution
    {
        try {
            $result = $this->native->execute($sql, $binds, $this->options($options));
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $sql);
        }

        return new ExtensionExecution(
            $result['rowCount'],
            $result['cursor'] === null ? null : new ExtensionCursor($result['cursor'], $sql),
            $result['outValues'] === [] ? [] : ExtensionCursor::handles($result['outValues'], $sql),
            $result['implicitResults'] === [] ? [] : array_map(static fn ($cursor) => new ExtensionCursor($cursor, $sql), $result['implicitResults']),
            $result['warning'],
        );
    }

    public function executeMany(string $sql, #[\SensitiveParameter] array $rows, DriverOptions $options): DriverBatch
    {
        try {
            $result = $this->native->executeMany($sql, $rows, $this->options($options));
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e, $sql);
        }

        return new ExtensionBatch($result['rowCount'], ExtensionCursor::handlesPerRow($result['outValues'], $sql));
    }

    public function commit(): void
    {
        try {
            $this->native->commit();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e);
        }
    }

    public function rollback(): void
    {
        try {
            $this->native->rollback();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e);
        }
    }

    public function trace(Tracing $tracing): void
    {
        try {
            $this->native->trace(ExtensionDriver::tracing($tracing));
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e);
        }
    }

    public function serverVersion(): string
    {
        try {
            return $this->native->serverVersion();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e);
        }
    }

    public function close(): void
    {
        try {
            $this->native->close();
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e);
        }
    }

    /**
     * The `$options` array of `$options`, built once while Core passes the
     * same DriverOptions.
     *
     * @return array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}
     */
    private function options(DriverOptions $options): array
    {
        if ($options !== $this->lastOptions || $this->lastArray === null) {
            $this->lastArray = self::array($options);
            $this->lastOptions = $options;
        }

        return $this->lastArray;
    }

    /**
     * @return array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}
     */
    private static function array(DriverOptions $options): array
    {
        return [
            'autocommit' => $options->autocommit,
            'timeout' => ExtensionDriver::optionalMilliseconds($options->timeout),
            'fetchSize' => $options->fetchSize,
            'numbers' => $options->values->numbers->value,
            'temporals' => $options->values->temporals->value,
            'lobs' => $options->values->lobs->value,
            'rowShape' => $options->rowShape->value,
            'keyCase' => $options->keyCase->value,
        ];
    }
}
