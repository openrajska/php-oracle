<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Tests\Support\ConnectTargets;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

/**
 * The other cases (detection, the version range, once per process) run in
 * fresh processes through the public API: tests/Api/DetectionTest.
 */
final class DetectionAndVersioningTest extends ConformanceTestCase
{
    #[Test]
    public function the_server_version_is_five_part_matches_version_full_and_costs_no_round_trip(): void
    {
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

        self::assertSame(0, $trips);
        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+\.\d+\.\d+$/', $version);
        self::assertSame(
            self::value($connection, "select version_full from product_component_version where product like 'Oracle%'"),
            $version,
        );
    }

    /**
     * The Extension under test is built from `EXTENSION_CARGO_TOML` when CI
     * runs the suite against an older Extension (the declared minimum), and
     * from the monorepo's packages/php-oracle-ext otherwise.
     */
    #[Test]
    public function phpversion_and_php_ri_report_the_cargo_version(): void
    {
        $manifest = getenv('EXTENSION_CARGO_TOML');
        $manifest = \is_string($manifest) && $manifest !== '' ? $manifest : \dirname(__DIR__, 3) . '/php-oracle-ext/Cargo.toml';
        self::assertSame(1, preg_match('/^version\s*=\s*"([^"]+)"/m', (string) file_get_contents($manifest), $m), $manifest);
        $cargo = $m[1] ?? '';

        self::assertSame($cargo, phpversion('oracledb'));

        $process = proc_open(
            [\PHP_BINARY, '-d', 'extension=' . ConnectTargets::extensionPath(), '--ri', 'oracledb'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $info = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $info);
        self::assertSame("\noracledb\n\nVersion => $cargo\n", $info);
    }
}
