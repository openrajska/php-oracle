<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\DetectionTest;

use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Tests\Functional\TestCase;

uses(TestCase::class);

/**
 * A fresh PHP process, as a new FPM worker would be: it loads every Core
 * class, then connects twice, and prints what happened as JSON. Any warning
 * fails it. `phpversion()` is shadowed in the adapter's namespace (PHP
 * resolves an unqualified call there first) to report another version of
 * oracledb than the one loaded: a stand-in for an older or newer build.
 *
 *     php -r CHILD -- <autoload.php> <src> [<reported version>]
 */
const CHILD = <<<'PHP'
    namespace Rajska\Oracle\Driver {
        function phpversion(string | null $extension = null): false | string
        {
            return $extension === 'oracledb' && isset($GLOBALS['argv'][3]) ? $GLOBALS['argv'][3] : \phpversion(...func_get_args());
        }
    }

    namespace {
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
    }
    PHP;

test('without the extension classes still load and connect says how to install it', function (): void {
    $result = child(withExtension: false);

    expect($result['classes'])->toBeGreaterThan(50)
        ->and($result['connects'])->each->toBe([
            'connected' => false,
            'class' => ConfigurationException::class,
            'message' => 'The oracledb extension is not loaded. Install it with `pie install openrajska/php-oracle-ext`, '
                . 'then check it is enabled for this SAPI (`php --ri oracledb`; CLI and FPM read different php.ini files).',
        ]);
});

/**
 * @return iterable<string, array{string}>
 */
dataset('outsideTheRange', function () {
    yield 'below the minimum' => ['0.0.9'];
    yield 'a pre-release of the minimum' => ['0.1.0-beta.1'];
    yield 'the next 0.x minor' => ['0.2.0'];
    yield 'a pre-release of the next 0.x minor' => ['0.2.0-beta.1'];
    yield 'the next major' => ['1.0.0'];
});

test('an extension outside the range is refused naming both versions the range and the upgrade command', function (string $version): void {
    $result = child(reported: $version);

    foreach ($result['connects'] as $connect) {
        expect($connect['connected'])->toBeFalse()
            ->and($connect['class'] ?? null)->toBe(ConfigurationException::class)
            ->and($connect['message'] ?? '')->toMatch('/^oracledb ' . preg_quote($version, '/') . ' is loaded, but openrajska\/php-oracle \S+ needs '
                . '>= 0\.1\.0, < 0\.2\.0\. Upgrade it with `pie install openrajska\/php-oracle-ext:\^0\.1`\.$/');
    }
})->with('outsideTheRange');

test('an extension inside the range connects', function (string $version): void {
    $result = child(reported: $version);

    expect($result['connects'])->each->toBe(['connected' => true]);
})->with(['the minimum' => ['0.1.0'], 'a later patch' => ['0.1.17']]);

/**
 * Runs CHILD, after the schema is in place: the child inherits the marker
 * that keeps it from preparing the schema itself.
 *
 * @return array{classes: int, connects: list<array{connected: bool, class?: string, message?: string}>}
 */
function child(bool $withExtension = true, string | null $reported = null): array
{
    TestCase::config();
    $package = \dirname(__DIR__, 2);
    $command = [\PHP_BINARY, ...$withExtension ? [] : ['-n'], '-r', CHILD, '--', "$package/vendor/autoload.php", "$package/src", ...$reported === null ? [] : [$reported]];

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
