<?php

/**
 * Child process for the detection tests: a fresh PHP process, as a new FPM
 * worker or CLI run would be, with or without the Extension.
 *
 *     php [-d extension=…] detect.php <autoload.php> [<reported version>]
 *
 * It loads every Core class, builds a ConnectionConfig, then calls
 * Oracle::connect() twice, and prints what happened as JSON on stdout. Any
 * PHP warning, notice or deprecation fails the run.
 *
 * `phpversion()` is shadowed in the adapter's namespace (PHP resolves an
 * unqualified call there first), to count the calls and, with a second
 * argument, to report another version of oracledb than the one loaded:
 * a stand-in for an older or newer build of the Extension.
 */

declare(strict_types=1);

namespace Rajska\Oracle\Driver\Extension {
    function phpversion(?string $extension = null): string|false
    {
        \DetectRecord::$phpversion[] = $extension;

        return $extension === 'oracledb' && \DetectRecord::$reported !== null
            ? \DetectRecord::$reported
            : ($extension === null ? \phpversion() : \phpversion($extension));
    }
}

namespace {
    use Rajska\Oracle\Oracle;
    use Rajska\Oracle\Tests\Support\TestDatabase;

    set_error_handler(static function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    final class DetectRecord
    {
        /** @var list<?string> the argument of every phpversion() call in the adapter */
        public static array $phpversion = [];

        /** The version to report for oracledb; null for the real one. */
        public static ?string $reported = null;
    }

    /** @var list<string> $argv */
    DetectRecord::$reported = $argv[2] ?? null;

    require $argv[1];

    $classes = 0;
    $source = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
    foreach ($source as $file) {
        \assert($file instanceof SplFileInfo);
        $relative = substr($file->getPathname(), \strlen(dirname(__DIR__, 2) . '/src/'), -\strlen('.php'));
        $name = 'Rajska\\Oracle\\' . str_replace('/', '\\', $relative);
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
            throw new LogicException("$name did not load");
        }
        ++$classes;
    }
    $config = TestDatabase::fromEnvironment()->config();

    $result = [
        'loaded' => extension_loaded('oracledb'),
        'classes' => $classes,
        'phpversionBeforeConnect' => \count(DetectRecord::$phpversion),
        'connects' => [],
    ];
    foreach ([1, 2] as $attempt) {
        try {
            $connection = Oracle::connect($config);
            $result['connects'][] = ['connected' => true, 'serverVersion' => $connection->serverVersion()];
            $connection->close();
        } catch (Throwable $e) {
            $result['connects'][] = ['connected' => false, 'class' => $e::class, 'message' => $e->getMessage()];
        }
    }
    $result['phpversion'] = DetectRecord::$phpversion;

    echo json_encode($result, JSON_THROW_ON_ERROR);
}
