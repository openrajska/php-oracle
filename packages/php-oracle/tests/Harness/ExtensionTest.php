<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtensionTest extends TestCase
{
    #[Test]
    public function the_extension_is_loaded(): void
    {
        self::assertTrue(
            extension_loaded('oracledb'),
            'oracledb is not loaded: run PHPUnit with -d extension=/path/to/libphp_oracledb.so',
        );
    }

    #[Test]
    public function the_extension_reports_a_semver_version(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/',
            (string) phpversion('oracledb'),
        );
    }
}
