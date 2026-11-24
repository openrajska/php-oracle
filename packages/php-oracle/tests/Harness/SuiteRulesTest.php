<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The suite-wide rules of spec #32's Testing Decisions hold for every test.
 */
final class SuiteRulesTest extends TestCase
{
    #[Test]
    public function exceptions_keep_their_arguments(): void
    {
        $throw = static function (string $argument): never {
            throw new RuntimeException('boom');
        };

        try {
            $throw('kept');
        } catch (RuntimeException $exception) {
            self::assertSame(['kept'], $exception->getTrace()[0]['args'] ?? null);

            return;
        }
    }

    #[Test]
    public function traces_show_string_arguments_whole(): void
    {
        $throw = static function (string $argument): never {
            throw new RuntimeException('boom');
        };

        try {
            $throw('a string longer than fifteen bytes');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString("('a string longer than fifteen bytes')", $exception->getTraceAsString());

            return;
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function issues(): iterable
    {
        yield 'E_WARNING' => ['emits_a_warning', '1 test triggered 1 PHP warning'];
        yield 'E_NOTICE' => ['emits_a_notice', '1 test triggered 1 PHP notice'];
        yield 'E_DEPRECATED' => ['emits_a_deprecation', '1 test triggered 1 PHP deprecation'];
    }

    #[Test]
    #[DataProvider('issues')]
    public function an_issue_fails_the_run(string $probe, string $reported): void
    {
        [$exitCode, $output] = self::runProbe($probe);

        self::assertStringContainsString($reported, $output);
        self::assertSame(1, $exitCode, $output);
    }

    #[Test]
    public function a_clean_test_passes_the_run(): void
    {
        [$exitCode, $output] = self::runProbe('emits_nothing');

        self::assertStringContainsString('OK (1 test, 1 assertion)', $output);
        self::assertSame(0, $exitCode, $output);
    }

    /**
     * Runs one test of IssueProbe in a separate PHPUnit process, with this
     * suite's configuration.
     *
     * @return array{int, string}
     */
    private static function runProbe(string $method): array
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
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
