<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\ExecuteOptions;

/**
 * ConnectionConfig and ExecuteOptions validate in pure PHP, with no
 * Extension and no Round trip: a bad config is a ConfigurationException, a
 * bad option for one Execution a UsageException.
 */
final class OptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function invalidValues(): iterable
    {
        yield 'fetchSize 0' => ['fetchSize', 0];
        yield 'fetchSize -1' => ['fetchSize', -1];
        yield 'timeout 0' => ['timeout', 0];
        yield 'timeout -0.5' => ['timeout', -0.5];
        yield 'timeout NAN' => ['timeout', NAN];
        yield 'timeout INF' => ['timeout', INF];
    }

    /**
     * Session features 4: a Fetch size of 0 or less fails, as does a
     * timeout that is not a positive number of seconds.
     */
    #[Test]
    #[DataProvider('invalidValues')]
    public function execute_options_reject_invalid_values_with_usage_exception(string $field, int|float $value): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage($field);

        // @phpstan-ignore argument.type (the named field goes through as given)
        new ExecuteOptions(...[$field => $value]);
    }

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function invalidConfigValues(): iterable
    {
        yield 'fetchSize 0' => ['fetchSize', 0];
        yield 'fetchSize -1' => ['fetchSize', -1];
        yield 'callTimeout 0' => ['callTimeout', 0];
        yield 'callTimeout INF' => ['callTimeout', INF];
        yield 'connectTimeout 0' => ['connectTimeout', 0];
        yield 'connectTimeout -1' => ['connectTimeout', -1];
        yield 'acquireTimeout 0' => ['acquireTimeout', 0];
        yield 'acquireTimeout NAN' => ['acquireTimeout', NAN];
        yield 'maxConnections 0' => ['maxConnections', 0];
        yield 'maxConnections -1' => ['maxConnections', -1];
        yield 'statementCacheSize -1' => ['statementCacheSize', -1];
    }

    #[Test]
    #[DataProvider('invalidConfigValues')]
    public function connection_config_rejects_invalid_values_with_configuration_exception(string $field, int|float $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($field);

        // @phpstan-ignore argument.type (the named field goes through as given)
        new ConnectionConfig(...['dsn' => 'localhost/FREEPDB1', 'user' => 'u', 'password' => 'p', $field => $value]);
    }

    /**
     * The checks run in pure PHP: a process without the Extension gets the
     * same ConfigurationException, before anything could reach it.
     */
    #[Test]
    public function connection_config_validates_without_the_extension(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = <<<'PHP'
            require $argv[1];
            $fields = ['dsn' => 'localhost/FREEPDB1', 'user' => 'u', 'password' => 'p'];
            $messages = [];
            foreach ([['maxConnections' => 0], ['statementCacheSize' => -1], ['connectTimeout' => 0]] as $invalid) {
                try {
                    new Rajska\Oracle\ConnectionConfig(...$fields, ...$invalid);
                    $messages[] = 'accepted';
                } catch (Rajska\Oracle\Exceptions\ConfigurationException $e) {
                    $messages[] = $e->getMessage();
                }
            }
            echo json_encode(['loaded' => extension_loaded('oracledb'), 'messages' => $messages]);
            PHP;
        $process = proc_open([\PHP_BINARY, '-r', $code, '--', $autoload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);

        /** @var array{loaded: bool, messages: list<string>} $result */
        $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        if ($result['loaded']) {
            self::markTestSkipped('This PHP loads oracledb from its php.ini: cannot run a process without it.');
        }
        self::assertSame([
            'ConnectionConfig: maxConnections must be at least 1, got 0.',
            'ConnectionConfig: statementCacheSize must be 0 (no Statement cache) or more, got -1.',
            'ConnectionConfig: connectTimeout must be a positive number of seconds, got 0.',
        ], $result['messages']);
    }

    #[Test]
    public function valid_values_are_kept(): void
    {
        $config = new ConnectionConfig('localhost/FREEPDB1', 'u', 'p', connectTimeout: 0.5, maxConnections: 1, acquireTimeout: 2, statementCacheSize: 0, callTimeout: 1.5, fetchSize: 1);
        $options = new ExecuteOptions(fetchSize: 1, timeout: 0.25);

        self::assertSame([0.5, 1, 2, 0, 1.5, 1], [$config->connectTimeout, $config->maxConnections, $config->acquireTimeout, $config->statementCacheSize, $config->callTimeout, $config->fetchSize]);
        self::assertSame([1, 0.25], [$options->fetchSize, $options->timeout]);
        self::assertNull(new ExecuteOptions()->timeout);
    }
}
