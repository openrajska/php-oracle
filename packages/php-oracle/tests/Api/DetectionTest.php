<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Tests\Support\ConnectTargets;

/**
 * How Core finds the Extension and checks its version, each case in a fresh
 * PHP process (tests/Support/detect.php).
 *
 * The declared minimum is 0.1.0 (`ext-oracledb: ^0.1`): the accepted
 * range is `>= 0.1.0, < 0.2.0`, Composer's caret for a 0.x version.
 */
final class DetectionTest extends TestCase
{
    #[Test]
    public function without_the_extension_connect_says_how_to_install_it_and_classes_still_load(): void
    {
        $result = self::child(extension: false);

        self::assertGreaterThan(50, $result['classes'], 'every Core class loads');
        self::assertSame(0, $result['phpversionBeforeConnect'], 'no check at autoload or class load');
        foreach ($result['connects'] as $connect) {
            self::assertFalse($connect['connected']);
            self::assertSame(ConfigurationException::class, $connect['class'] ?? null);
            self::assertSame(
                'The oracledb extension is not loaded. Install it with `pie install openrajska/php-oracle-ext`, '
                . 'then check it is enabled for this SAPI (`php --ri oracledb`; CLI and FPM read different php.ini files).',
                $connect['message'] ?? null,
            );
        }
        self::assertSame(['oracledb'], $result['phpversion'], 'checked once, on the first connect()');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outsideTheRange(): iterable
    {
        yield 'below the minimum' => ['0.0.9'];
        yield 'a pre-release of the minimum' => ['0.1.0-beta.1'];
        yield 'the next 0.x minor' => ['0.2.0'];
        yield 'a pre-release of the next 0.x minor' => ['0.2.0-beta.1'];
        yield 'the next major' => ['1.0.0'];
    }

    #[Test]
    #[DataProvider('outsideTheRange')]
    public function an_extension_outside_the_range_is_refused_naming_both_versions_the_range_and_the_upgrade_command(string $version): void
    {
        $result = self::child(extension: true, reported: $version);

        foreach ($result['connects'] as $connect) {
            self::assertFalse($connect['connected']);
            self::assertSame(ConfigurationException::class, $connect['class'] ?? null);
            self::assertMatchesRegularExpression(
                '/^oracledb ' . preg_quote($version, '/') . ' is loaded, but openrajska\/php-oracle \S+ needs '
                . '>= 0\.1\.0, < 0\.2\.0\. Upgrade it with `pie install openrajska\/php-oracle-ext:\^0\.1`\.$/',
                $connect['message'] ?? '',
            );
        }
        self::assertSame(['oracledb'], $result['phpversion']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function insideTheRange(): iterable
    {
        yield 'the minimum' => ['0.1.0'];
        yield 'a later patch' => ['0.1.17'];
    }

    #[Test]
    #[DataProvider('insideTheRange')]
    public function an_extension_inside_the_range_connects(string $version): void
    {
        $result = self::child(extension: true, reported: $version);

        foreach ($result['connects'] as $connect) {
            self::assertTrue($connect['connected'], $connect['message'] ?? '');
        }
    }

    #[Test]
    public function the_check_runs_once_per_process_on_the_first_connect(): void
    {
        $result = self::child(extension: true);

        self::assertSame(0, $result['phpversionBeforeConnect']);
        self::assertTrue($result['connects'][0]['connected'], $result['connects'][0]['message'] ?? '');
        self::assertTrue($result['connects'][1]['connected'], $result['connects'][1]['message'] ?? '');
        self::assertSame(['oracledb'], $result['phpversion']);
    }

    #[Test]
    public function core_requires_the_extension_from_the_minimum_its_upgrade_command_names(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertIsArray($manifest['require']);
        self::assertSame('^0.1', $manifest['require']['ext-oracledb'] ?? null);
    }

    /**
     * @return array{
     *     loaded: bool,
     *     classes: int,
     *     phpversionBeforeConnect: int,
     *     connects: list<array{connected: bool, serverVersion?: string, class?: string, message?: string}>,
     *     phpversion: list<?string>,
     * }
     */
    private static function child(bool $extension, ?string $reported = null): array
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
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertSame('', $errors);

        /** @var array{loaded: bool, classes: int, phpversionBeforeConnect: int, connects: list<array{connected: bool, serverVersion?: string, class?: string, message?: string}>, phpversion: list<?string>} $result */
        $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        if (!$extension && $result['loaded']) {
            self::markTestSkipped('This PHP loads oracledb from its php.ini: cannot run a process without it.');
        }
        self::assertSame($extension, $result['loaded']);
        self::assertCount(2, $result['connects']);

        return $result;
    }
}
