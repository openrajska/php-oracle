<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test: the suite runs with the `rajska_oracle` Extension loaded.
 */
final class ExtensionTest extends TestCase
{
    #[Test]
    public function the_extension_is_loaded(): void
    {
        self::assertTrue(
            extension_loaded('rajska_oracle'),
            'rajska_oracle is not loaded: run PHPUnit with -d extension=/path/to/librajska_oracle.so',
        );
    }

    #[Test]
    public function the_extension_reports_a_semver_version(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/',
            (string) phpversion('rajska_oracle'),
        );
    }
}
