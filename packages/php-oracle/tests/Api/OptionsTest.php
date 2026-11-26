<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\OptionsTest;

use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\Options;

/**
 * @return iterable<string, array{string, float|int}>
 */
dataset('invalidValues', function () {
    yield 'fetchSize 0' => ['fetchSize', 0];
    yield 'fetchSize -1' => ['fetchSize', -1];
    yield 'timeout 0' => ['timeout', 0];
    yield 'timeout -0.5' => ['timeout', -0.5];
    yield 'timeout NAN' => ['timeout', NAN];
    yield 'timeout INF' => ['timeout', INF];
});

test('execute options reject invalid values with usage exception', function (string $field, float | int $value): void {
    // @phpstan-ignore argument.type (the named field goes through as given)
    expect(fn (): Options => new Options(...[$field => $value]))->toThrow(UsageException::class, $field);
})->with('invalidValues');

/**
 * @return iterable<string, array{string, float|int}>
 */
dataset('invalidConfigValues', function () {
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
});

test('connection config rejects invalid values with configuration exception', function (string $field, float | int $value): void {
    // @phpstan-ignore argument.type (the named field goes through as given)
    expect(fn (): ConnectionConfig => new ConnectionConfig(...['dsn' => 'localhost/FREEPDB1', 'user' => 'u', 'password' => 'p', $field => $value]))->toThrow(ConfigurationException::class, $field);
})->with('invalidConfigValues');

test('connection config validates without the extension', function (): void {
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
    assert(\is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $output . $errors);

    /** @var array{loaded: bool, messages: list<string>} $result */
    $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
    if ($result['loaded']) {
        $this->markTestSkipped('This PHP loads oracledb from its php.ini: cannot run a process without it.');
    }
    expect($result['messages'])->toBe([
        'ConnectionConfig: maxConnections must be at least 1, got 0.',
        'ConnectionConfig: statementCacheSize must be 0 (no Statement cache) or more, got -1.',
        'ConnectionConfig: connectTimeout must be a positive number of seconds, got 0.',
    ]);
})->skip('Needs a PHP without the oracledb extension.');

test('valid values are kept', function (): void {
    $config = new ConnectionConfig('localhost/FREEPDB1', 'u', 'p', connectTimeout: 0.5, maxConnections: 1, acquireTimeout: 2, statementCacheSize: 0, callTimeout: 1.5, fetchSize: 1);
    $options = new Options(fetchSize: 1, timeout: 0.25);

    expect([$config->connectTimeout, $config->maxConnections, $config->acquireTimeout, $config->statementCacheSize, $config->callTimeout, $config->fetchSize])->toBe([0.5, 1, 2, 0, 1.5, 1])
        ->and([$options->fetchSize, $options->timeout])->toBe([1, 0.25])
        ->and(new Options()->timeout)->toBeNull();
});
