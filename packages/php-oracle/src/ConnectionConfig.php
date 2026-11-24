<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Exceptions\ConfigurationException;

/**
 * Everything `Oracle::connect()` needs. It needs no Extension to build, and
 * it validates in pure PHP. Durations are in seconds.
 */
final readonly class ConnectionConfig
{
    /**
     * @param string $dsn an Easy Connect string, a (DESCRIPTION=…) descriptor or a TNS alias
     * @param ?string $walletLocation the directory holding ewallet.pem
     * @param ?string $configDir where tnsnames.ora lives; else $TNS_ADMIN
     * @param int|float $connectTimeout seconds
     * @param int|float $acquireTimeout seconds
     * @param int $statementCacheSize 0 disables the Statement cache
     * @param int|float|null $callTimeout seconds per Round trip; null = no limit
     * @param int $fetchSize rows per Round trip, >= 1
     *
     * @throws ConfigurationException for a fetchSize below 1 or a timeout that is not a positive number of seconds
     */
    public function __construct(
        public string $dsn,
        public string $user,
        #[\SensitiveParameter] public string $password,
        public ?string $proxyUser = null,
        public ?string $walletLocation = null,
        #[\SensitiveParameter] public ?string $walletPassword = null,
        public ?string $configDir = null,
        public int|float $connectTimeout = 20,
        public ?string $drcpConnectionClass = null,
        public int $maxConnections = 16,
        public int|float $acquireTimeout = 5,
        public int $statementCacheSize = 20,
        public bool $standalone = false,
        public Tracing $tracing = new Tracing(),
        public int|float|null $callTimeout = null,
        public int $fetchSize = 100,
        public ValueMapping $values = new ValueMapping(),
        public RowShape $rowShape = RowShape::Assoc,
        public KeyCase $keyCase = KeyCase::Native,
    ) {
        if ($fetchSize < 1) {
            throw new ConfigurationException("ConnectionConfig: fetchSize must be at least 1, got $fetchSize.");
        }
        foreach (['connectTimeout' => $connectTimeout, 'acquireTimeout' => $acquireTimeout, 'callTimeout' => $callTimeout] as $field => $seconds) {
            if ($seconds !== null && !($seconds > 0 && is_finite($seconds))) {
                $or = $field === 'callTimeout' ? ' or null' : '';
                throw new ConfigurationException("ConnectionConfig: $field must be a positive number of seconds$or, got $seconds.");
            }
        }
    }
}
