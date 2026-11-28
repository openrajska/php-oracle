<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Driver;

use Override;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Contracts\Driver\Manager;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Extension\Connection;
use Rajska\Oracle\Extension\Exception;
use WeakMap;

/**
 * The Primary Backend, `oracledb`, behind the Driver contract.
 *
 * An Extension class cannot implement an interface declared in PHP (the
 * Extension's classes exist before Composer loads), so this adapter forwards
 * each call to the native surface and translates its exceptions. Values
 * cross as arrays of scalars: enums as backing values, durations in ms.
 *
 * The first `connect()` of the process checks that the Extension is loaded
 * and that its version is in `>= MINIMUM, < BREAKING`, and remembers the
 * answer. A version given to the constructor stands in for the loaded one,
 * and is checked on every `connect()`. Nothing runs at autoload or class
 * load.
 *
 * @internal
 *
 * @phpstan-type PoolFields array{dsn: string, user: string, password: string, proxyUser: ?string, walletLocation: ?string, walletPassword: ?string, configDir: ?string, connectTimeout: int, drcpConnectionClass: ?string, maxConnections: int, acquireTimeout: int, statementCacheSize: int, standalone: bool}
 * @phpstan-type SessionFields array{tracing: array{module: ?string, action: ?string, clientIdentifier: ?string, clientInfo: ?string}, callTimeout: ?int}
 */
final class ExtensionManager implements Manager
{
    /**
     * The oldest Extension this Core works with, and CI runs the suite
     * against it. Raise the three constants and Core's composer.json
     * together, only after releasing that Extension.
     */
    private const string MINIMUM = '0.1.0';

    /**
     * The first Extension version that may break this Core: the next major,
     * or the next minor below 1.0, as Composer's caret reads `CONSTRAINT`.
     */
    private const string BREAKING = '0.2.0';

    /**
     * Core's composer.json `require` of `ext-oracledb`.
     */
    private const string CONSTRAINT = '^0.1';

    /**
     * Why the Extension cannot be used, '' once it can, null before the
     * first check.
     */
    private static string | null $refusal = null;

    /**
     * The `$pool` and `$session` arrays built from each config still alive:
     * a ConnectionConfig is immutable, and an application usually connects
     * with the same one every time. Weak, so what they copy (the passwords)
     * goes with the config.
     *
     * @var ?WeakMap<ConnectionConfig, array{PoolFields, SessionFields}>
     */
    private static WeakMap | null $arrays = null;

    /**
     * @param false|string|null $version the Extension version to check in
     *                                   place of the loaded one (false: not
     *                                   loaded)
     */
    public function __construct(private readonly false | string | null $version = null)
    {
    }

    #[Override]
    public function connect(ConnectionConfig $config): Session
    {
        $refusal = $this->version === null
            ? self::$refusal ??= $this->refusal(phpversion('oracledb'))
            : $this->refusal($this->version);
        if ($refusal !== '') {
            throw new ConfigurationException($refusal);
        }

        self::$arrays ??= new WeakMap();
        [$pool, $session] = self::$arrays[$config] ??= [$this->pool($config), $this->session($config)];

        try {
            return new ExtensionSession(Connection::connect($pool, $session));
        } catch (Exception $exception) {
            throw ExceptionTranslator::translate($exception);
        }
    }

    /**
     * The Pool-key fields of a config, as the Extension takes them.
     *
     * @return PoolFields
     */
    private function pool(ConnectionConfig $config): array
    {
        return [
            'dsn' => $config->dsn,
            'user' => $config->user,
            'password' => $config->password,
            'proxyUser' => $config->proxyUser,
            'walletLocation' => $config->walletLocation,
            'walletPassword' => $config->walletPassword,
            'configDir' => $config->configDir,
            'connectTimeout' => self::toMilliseconds($config->connectTimeout),
            'drcpConnectionClass' => $config->drcpConnectionClass,
            'maxConnections' => $config->maxConnections,
            'acquireTimeout' => self::toMilliseconds($config->acquireTimeout),
            'statementCacheSize' => $config->statementCacheSize,
            'standalone' => $config->standalone,
        ];
    }

    /**
     * What every acquire applies to its Session, as the Extension takes it.
     *
     * @return SessionFields
     */
    private function session(ConnectionConfig $config): array
    {
        return [
            'tracing' => $config->tracing->toArray(),
            'callTimeout' => self::toOptionalMilliseconds($config->callTimeout),
        ];
    }

    /**
     * Why this Extension version cannot be used, or '' when it can.
     */
    private function refusal(false | string $version): string
    {
        if ($version === false) {
            return 'The oracledb extension is not loaded. Install it with `pie install openrajska/php-oracle-ext`, '
                . 'then check it is enabled for this SAPI (`php --ri oracledb`; CLI and FPM read different php.ini files).';
        }

        // '-dev' sorts below any pre-release: a beta of BREAKING is outside too.
        if (version_compare($version, self::MINIMUM, '>=') && version_compare($version, self::BREAKING . '-dev', '<')) {
            return '';
        }

        return \sprintf(
            'oracledb %s is loaded, but openrajska/php-oracle needs >= %s, < %s. Upgrade it with `pie install openrajska/php-oracle-ext:%s`.',
            $version,
            self::MINIMUM,
            self::BREAKING,
            self::CONSTRAINT,
        );
    }

    /**
     * Seconds, as the public API takes them (validated positive), to the
     * Extension's milliseconds. A sub-millisecond duration becomes 1 ms,
     * never 0.
     */
    public static function toMilliseconds(float | int $seconds): int
    {
        return max(1, (int) round($seconds * 1000));
    }

    public static function toOptionalMilliseconds(float | int | null $seconds): int | null
    {
        return $seconds === null ? null : self::toMilliseconds($seconds);
    }
}
