<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\DetectionTest;

use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Tests\Support\ConnectTargets;

test('without the extension connect says how to install it and classes still load', function (): void {
    $result = child(extension: false);

    expect($result['classes'])->toBeGreaterThan(50, 'every Core class loads')
        ->and($result['phpversionBeforeConnect'])->toBe(0, 'no check at autoload or class load');
    foreach ($result['connects'] as $connect) {
        expect($connect['connected'])->toBeFalse()
            ->and($connect['class'] ?? null)->toBe(ConfigurationException::class)
            ->and($connect['message'] ?? null)->toBe('The oracledb extension is not loaded. Install it with `pie install openrajska/php-oracle-ext`, '
        . 'then check it is enabled for this SAPI (`php --ri oracledb`; CLI and FPM read different php.ini files).');
    }
    expect($result['phpversion'])->toBe(['oracledb'], 'checked once, on the first connect()');
});
/**
 * @return iterable<string, array{string}>
 */
dataset('outsideTheRange', function () {
    yield 'below the minimum' => ['0.0.9'];
    yield 'a pre-release of the minimum' => ['0.1.0-beta.1'];
    yield 'the next 0.x minor' => ['0.2.0'];
    yield 'a pre-release of the next 0.x minor' => ['0.2.0-beta.1'];
    yield 'the next major' => ['1.0.0'];
});
test('an extension outside the range is refused naming both versions the range and the upgrade command', function (string $version): void {
    $result = child(extension: true, reported: $version);

    foreach ($result['connects'] as $connect) {
        expect($connect['connected'])->toBeFalse()
            ->and($connect['class'] ?? null)->toBe(ConfigurationException::class)
            ->and($connect['message'] ?? '')->toMatch('/^oracledb ' . preg_quote($version, '/') . ' is loaded, but openrajska\/php-oracle \S+ needs '
        . '>= 0\.1\.0, < 0\.2\.0\. Upgrade it with `pie install openrajska\/php-oracle-ext:\^0\.1`\.$/');
    }
    expect($result['phpversion'])->toBe(['oracledb']);
})->with('outsideTheRange');
/**
 * @return iterable<string, array{string}>
 */
dataset('insideTheRange', function () {
    yield 'the minimum' => ['0.1.0'];
    yield 'a later patch' => ['0.1.17'];
});
test('an extension inside the range connects', function (string $version): void {
    $result = child(extension: true, reported: $version);

    foreach ($result['connects'] as $connect) {
        expect($connect['connected'])->toBeTrue($connect['message'] ?? '');
    }
})->with('insideTheRange');
test('the check runs once per process on the first connect', function (): void {
    $result = child(extension: true);

    expect($result['phpversionBeforeConnect'])->toBe(0)
        ->and($result['connects'][0]['connected'])->toBeTrue($result['connects'][0]['message'] ?? '')
        ->and($result['connects'][1]['connected'])->toBeTrue($result['connects'][1]['message'] ?? '')
        ->and($result['phpversion'])->toBe(['oracledb']);
});
test('core requires the extension from the minimum its upgrade command names', function (): void {
    $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);

    expect($manifest)->toBeArray()
        ->and($manifest['require'])->toBeArray()
        ->and($manifest['require']['ext-oracledb'] ?? null)->toBe('^0.1');
});
/**
 * @return array{
 *     loaded: bool,
 *     classes: int,
 *     phpversionBeforeConnect: int,
 *     connects: list<array{connected: bool, serverVersion?: string, class?: string, message?: string}>,
 *     phpversion: list<?string>,
 * }
 */
function child(bool $extension, string | null $reported = null): array
{
    $command = [\PHP_BINARY];
    if ($extension) {
        $command[] = '-d';
        $command[] = 'extension=' . ConnectTargets::extensionPath();
    }
    $command[] = __DIR__ . '/../Support/detect.php';
    $command[] = \dirname(__DIR__, 2) . '/vendor/autoload.php';
    if ($reported !== null) {
        $command[] = $reported;
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $output . $errors)
        ->and($errors)->toBeEmpty();

    /** @var array{loaded: bool, classes: int, phpversionBeforeConnect: int, connects: list<array{connected: bool, serverVersion?: string, class?: string, message?: string}>, phpversion: list<?string>} $result */
    $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
    if (!$extension && $result['loaded']) {
        test()->markTestSkipped('This PHP loads oracledb from its php.ini: cannot run a process without it.');
    }
    expect($result['loaded'])->toBe($extension)
        ->and($result['connects'])->toHaveCount(2);

    return $result;
}
