<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Connection\DetectionTest;

use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

describe('a missing extension', function (): void {
    it('still loads every class and says how to install the extension on connect', function (): void {
        $result = child();

        expect($result['classes'])->toBeGreaterThan(50)
            ->and($result['connects'])->each->toBe([
                'connected' => false,
                'class' => ConfigurationException::class,
                'message' => 'The oracledb extension is not loaded. Install it with `pie install openrajska/php-oracle-ext`, '
                    . 'then check it is enabled for this SAPI (`php --ri oracledb`; CLI and FPM read different php.ini files).',
            ]);
    });
});

/**
 * A fresh PHP process without the Extension, as a new FPM worker missing it
 * would be: it loads every Core class, then connects twice, and prints what
 * happened as JSON. Any warning fails it.
 *
 *     php -n -r CHILD -- <autoload.php> <src>
 */
const CHILD = <<<'PHP'
    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });
    require $argv[1];

    $classes = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[2], FilesystemIterator::SKIP_DOTS)) as $file) {
        $name = 'Rajska\\Oracle\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($argv[2]) + 1, -4));
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
            throw new LogicException("$name did not load");
        }
        ++$classes;
    }
    $config = Rajska\Oracle\Tests\TestCase::config();

    $connects = [];
    foreach ([1, 2] as $attempt) {
        try {
            Rajska\Oracle\Oracle::connect($config)->close();
            $connects[] = ['connected' => true];
        } catch (Throwable $e) {
            $connects[] = ['connected' => false, 'class' => $e::class, 'message' => $e->getMessage()];
        }
    }

    echo json_encode(['classes' => $classes, 'connects' => $connects], JSON_THROW_ON_ERROR);
    PHP;

/**
 * Runs CHILD, after the schema is in place: the child inherits the marker
 * that keeps it from preparing the schema itself.
 *
 * @return array{classes: int, connects: list<array{connected: bool, class?: string, message?: string}>}
 */
function child(): array
{
    TestCase::config();
    $package = \dirname(__DIR__, 3);
    $command = [\PHP_BINARY, '-n', '-r', CHILD, '--', "$package/vendor/autoload.php", "$package/src"];

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert(\is_resource($process));
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $output . $errors)
        ->and($errors)->toBeEmpty();

    /** @var array{classes: int, connects: list<array{connected: bool, class?: string, message?: string}>} */
    return json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
}
