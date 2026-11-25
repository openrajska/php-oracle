<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\SuiteRulesTest;

use RuntimeException;

test('exceptions keep their arguments', function (): void {
    $throw = static function (string $argument): never {
        throw new RuntimeException('boom');
    };

    try {
        $throw('kept');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getTrace()[0]['args'] ?? null)->toBe(['kept']);

        return;
    }
});
test('traces show string arguments whole', function (): void {
    $throw = static function (string $argument): never {
        throw new RuntimeException('boom');
    };

    try {
        $throw('a string longer than fifteen bytes');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getTraceAsString())->toContain("('a string longer than fifteen bytes')");

        return;
    }
});
/**
 * @return iterable<string, array{string, string}>
 */
dataset('issues', function () {
    yield 'E_WARNING' => ['emits_a_warning', '1 test triggered 1 PHP warning'];
    yield 'E_NOTICE' => ['emits_a_notice', '1 test triggered 1 PHP notice'];
    yield 'E_DEPRECATED' => ['emits_a_deprecation', '1 test triggered 1 PHP deprecation'];
});
test('an issue fails the run', function (string $probe, string $reported): void {
    [$exitCode, $output] = runProbe($probe);

    expect($output)->toContain($reported)
        ->and($exitCode)->toBe(1, $output);
})->with('issues');
test('a clean test passes the run', function (): void {
    [$exitCode, $output] = runProbe('emits_nothing');

    expect($output)->toContain('OK (1 test, 1 assertion)')
        ->and($exitCode)->toBe(0, $output);
});
/**
 * Runs one test of IssueProbe in a separate PHPUnit process, with this
 * suite's configuration.
 *
 * @return array{int, string}
 */
function runProbe(string $method): array
{
    $root = \dirname(__DIR__, 2);
    $command = [
        \PHP_BINARY,
        $root . '/vendor/bin/phpunit',
        '--configuration', $root . '/phpunit.xml.dist',
        '--no-progress',
        '--colors=never',
        '--do-not-record-test-run-history',
        '--filter', $method,
        __DIR__ . '/Probes/IssueProbe.php',
    ];

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}
