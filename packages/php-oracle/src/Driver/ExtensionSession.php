<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Override;
use Rajska\Oracle\Contracts\Driver\ArrayDml;
use Rajska\Oracle\Contracts\Driver\Response;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Extension\Connection;
use Rajska\Oracle\Extension\Cursor;
use Rajska\Oracle\Extension\Exception;
use Rajska\Oracle\Tracing;
use SensitiveParameter;

/**
 * @internal
 */
final class ExtensionSession implements Session
{
    /**
     * The last options passed, and their `$options` array: Core passes the
     * same Options to every call that sets none.
     */
    private Options | null $lastOptions = null;

    /**
     * @var array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}|null
     */
    private array | null $lastArray = null;

    public function __construct(private readonly Connection $native)
    {
    }

    #[Override]
    public function execute(string $sql, #[SensitiveParameter] array $binds, Options $options): Response
    {
        try {
            $result = $this->native->execute($sql, $binds, $this->options($options));
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $sql);
        }

        return new ExtensionResponse(
            $result['rowCount'],
            $result['cursor'] === null ? null : new ExtensionCursor($result['cursor'], $sql),
            $result['outValues'] === [] ? [] : ExtensionCursor::handles($result['outValues'], $sql),
            $result['implicitResults'] === [] ? [] : array_map(static fn (Cursor $cursor): ExtensionCursor => new ExtensionCursor($cursor, $sql), $result['implicitResults']),
            $result['warning'],
        );
    }

    #[Override]
    public function executeMany(string $sql, #[SensitiveParameter] array $rows, Options $options): ArrayDml
    {
        try {
            $result = $this->native->executeMany($sql, $rows, $this->options($options));
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception, $sql);
        }

        return new ExtensionArrayDml($result['rowCount'], ExtensionCursor::handlesPerRow($result['outValues'], $sql));
    }

    #[Override]
    public function commit(): void
    {
        try {
            $this->native->commit();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception);
        }
    }

    #[Override]
    public function rollback(): void
    {
        try {
            $this->native->rollback();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception);
        }
    }

    #[Override]
    public function trace(Tracing $tracing): void
    {
        try {
            $this->native->trace($tracing->toArray());
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception);
        }
    }

    #[Override]
    public function serverVersion(): string
    {
        try {
            return $this->native->serverVersion();
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception);
        }
    }

    #[Override]
    public function close(): void
    {
        $this->native->close();
    }

    /**
     * @return array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}
     */
    private function options(Options $options): array
    {
        if ($options !== $this->lastOptions || $this->lastArray === null) {
            $this->lastArray = $this->array($options);
            $this->lastOptions = $options;
        }

        return $this->lastArray;
    }

    /**
     * @return array{autocommit: bool, timeout: ?int, fetchSize: int, numbers: string, temporals: string, lobs: string, rowShape: string, keyCase: string}
     */
    private function array(Options $options): array
    {
        return [
            'autocommit' => $options->autocommit,
            'timeout' => ExtensionManager::toOptionalMilliseconds($options->timeout),
            'fetchSize' => $options->fetchSize,
            'numbers' => $options->values->numbers->value,
            'temporals' => $options->values->temporals->value,
            'lobs' => $options->values->lobs->value,
            'rowShape' => $options->rowShape->value,
            'keyCase' => $options->keyCase->value,
        ];
    }
}
