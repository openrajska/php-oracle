<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\Exceptions\ArrayDmlException;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\RowFailure;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Extension\Exception as NativeException;

/**
 * The one place where the Extension's exception becomes Core's: its `kind`
 * picks the class, and code, message, offset and rows are kept. The native
 * exception is never attached as `previous`, so nothing of it (its trace
 * included) reaches the user.
 *
 * @internal
 */
final class ExceptionTranslator
{
    private const int UNIQUE_VIOLATION = 1;

    /**
     * @param ?string $sql the SQL that ran, for `sql()`
     */
    public static function translate(NativeException $native, ?string $sql = null): OracleException
    {
        $message = $native->getMessage();
        $code = $native->getCode();

        return match ($native->kind) {
            'database' => $code === self::UNIQUE_VIOLATION
                ? new UniqueViolationException($message, $code, $sql, null, $native->offset)
                : new QueryException($message, $code, $sql, null, $native->offset),
            'array_dml' => new ArrayDmlException($message, $code, self::rows($native), $sql),
            'connection' => new ConnectionException($message, $code, $sql),
            'connection_lost' => new ConnectionLostException($message, $code, $sql),
            'pool_exhausted' => new PoolExhaustedException($message, $code, $sql),
            'timeout' => new TimeoutException($message, $code, $sql),
            'unsupported' => new UnsupportedException($message, $code, $sql),
            'usage' => new UsageException($message, $code, $sql),
            'configuration' => new ConfigurationException($message, $code, $sql),
            // A kind this Core does not know: an Extension newer than the
            // version check allows. Reported, never swallowed.
            default => new QueryException("[{$native->kind}] $message", $code, $sql, null, $native->offset),
        };
    }

    /**
     * @return list<RowFailure>
     */
    private static function rows(NativeException $native): array
    {
        return array_map(
            static fn (array $row): RowFailure => new RowFailure($row['index'], $row['code'], $row['message']),
            $native->rows,
        );
    }
}
