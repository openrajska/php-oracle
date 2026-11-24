<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Core's copy of the Extension's stub, used by PHPStan only, stays the same
 * as the Extension's.
 */
final class StubTest extends TestCase
{
    #[Test]
    public function cores_copy_of_the_stub_matches_the_extensions(): void
    {
        self::assertFileEquals(
            __DIR__ . '/../../../php-oracle-ext/rajska_oracle.stub.php',
            __DIR__ . '/../../stubs/rajska_oracle.stub.php',
        );
    }
}
