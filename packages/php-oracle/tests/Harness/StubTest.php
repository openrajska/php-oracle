<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Core's copy of the stub is for PHPStan only.
 */
final class StubTest extends TestCase
{
    #[Test]
    public function cores_copy_of_the_stub_matches_the_extensions(): void
    {
        self::assertFileEquals(
            __DIR__ . '/../../../php-oracle-ext/oracledb.stub.php',
            __DIR__ . '/../../stubs/oracledb.stub.php',
        );
    }
}
