<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\StubTest;

test('cores copy of the stub matches the extensions', function (): void {
    $this->assertFileEquals(__DIR__ . '/../../../php-oracle-ext/oracledb.stub.php', __DIR__ . '/../../stubs/oracledb.stub.php');
});
