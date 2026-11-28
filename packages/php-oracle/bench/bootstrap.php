<?php

declare(strict_types = 1);

// Loads Core from src/ and the benchmark's helpers from bench/src/, without
// Composer, so the benchmark runs where vendor/ does not exist (the ZTS
// image) and with nothing but the driver under test loaded.
spl_autoload_register(static function (string $class): void {
    foreach (['Rajska\\Oracle\\Bench\\' => __DIR__ . '/src/', 'Rajska\\Oracle\\' => __DIR__ . '/../src/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

require __DIR__ . '/cases.php';

/** kB values from /proc/self/status: VmRSS (now) and VmHWM (peak). */
function proc_memory(): array
{
    $memory = ['VmRSS' => 0, 'VmHWM' => 0];
    foreach (@file('/proc/self/status') ?: [] as $line) {
        if (preg_match('/^(VmRSS|VmHWM):\s+(\d+) kB/', $line, $match)) {
            $memory[$match[1]] = (int) $match[2];
        }
    }

    return $memory;
}
