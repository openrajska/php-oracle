<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\DetectionAndVersioningTest;

use Rajska\Oracle\Tests\Support\ConnectTargets;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

test('the server version is five part matches version full and costs no round trip', function (): void {
    $connection = $this->connect();
    $sid = self::sid($connection);
    $counter = RoundTripCounter::open();

    try {
        $version = null;
        $trips = $counter->during($sid, static function () use ($connection, &$version): void {
            $version = $connection->serverVersion();
        });
    } finally {
        $counter->close();
    }

    expect($trips)->toBe(0)
        ->and($version)->toBeString()
        ->toMatch('/^\d+\.\d+\.\d+\.\d+\.\d+$/')
        ->toBe(self::value($connection, "select version_full from product_component_version where product like 'Oracle%'"));
});
test('phpversion and php ri report the cargo version', function (): void {
    $manifest = getenv('EXTENSION_CARGO_TOML');
    $manifest = \is_string($manifest) && $manifest !== '' ? $manifest : \dirname(__DIR__, 3) . '/php-oracle-ext/Cargo.toml';

    expect(preg_match('/^version\s*=\s*"([^"]+)"/m', (string) file_get_contents($manifest), $m))->toBe(1, $manifest);
    $cargo = $m[1] ?? '';

    expect(phpversion('oracledb'))->toBe($cargo);

    $process = proc_open(
        [\PHP_BINARY, '-d', 'extension=' . ConnectTargets::extensionPath(), '--ri', 'oracledb'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect($process)->toBeResource();
    $info = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $info)
        ->and($info)->toBe("\noracledb\n\nVersion => $cargo\n");
});
