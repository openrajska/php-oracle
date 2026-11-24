<?php

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension;

use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Driver\Driver;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Extension\Connection as NativeConnection;
use Rajska\Oracle\Extension\Exception as NativeException;
use Rajska\Oracle\Tracing;

/**
 * The Primary Backend, `rajska_oracle`, behind the Driver contract.
 *
 * An Extension class cannot implement an interface declared in PHP (the
 * Extension's classes exist before Composer loads), so this adapter forwards
 * each call to the native surface and translates its exceptions. Values
 * cross as arrays of scalars: enums as backing values, durations in ms.
 *
 * @internal
 */
final class ExtensionDriver implements Driver
{
    public function connect(ConnectionConfig $config): DriverConnection
    {
        $pool = [
            'dsn' => $config->dsn,
            'user' => $config->user,
            'password' => $config->password,
            'proxyUser' => $config->proxyUser,
            'walletLocation' => $config->walletLocation,
            'walletPassword' => $config->walletPassword,
            'configDir' => $config->configDir,
            'connectTimeout' => self::milliseconds($config->connectTimeout),
            'drcpConnectionClass' => $config->drcpConnectionClass,
            'maxConnections' => $config->maxConnections,
            'acquireTimeout' => self::milliseconds($config->acquireTimeout),
            'statementCacheSize' => $config->statementCacheSize,
            'standalone' => $config->standalone,
        ];
        $session = [
            'tracing' => self::tracing($config->tracing),
            'callTimeout' => self::optionalMilliseconds($config->callTimeout),
        ];

        try {
            return new ExtensionConnection(NativeConnection::connect($pool, $session));
        } catch (NativeException $e) {
            throw ExceptionTranslator::translate($e);
        }
    }

    /**
     * @return array{module: ?string, action: ?string, clientIdentifier: ?string, clientInfo: ?string}
     */
    public static function tracing(Tracing $tracing): array
    {
        return [
            'module' => $tracing->module,
            'action' => $tracing->action,
            'clientIdentifier' => $tracing->clientIdentifier,
            'clientInfo' => $tracing->clientInfo,
        ];
    }

    /**
     * Seconds, as the public API takes them, to the Extension's milliseconds.
     */
    public static function milliseconds(int|float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    public static function optionalMilliseconds(int|float|null $seconds): ?int
    {
        return $seconds === null ? null : self::milliseconds($seconds);
    }
}
