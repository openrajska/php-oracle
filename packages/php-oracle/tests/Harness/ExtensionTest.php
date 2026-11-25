<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\ExtensionTest;

test('the extension is loaded', function (): void {
    expect(extension_loaded('oracledb'))->toBeTrue('oracledb is not loaded: run PHPUnit with -d extension=/path/to/libphp_oracledb.so');
});

test('the extension reports a semver version', function (): void {
    expect((string) phpversion('oracledb'))->toMatch('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/');
});
