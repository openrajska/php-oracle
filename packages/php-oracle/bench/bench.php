<?php

declare(strict_types = 1);

// Times one case in this process and prints one JSON line.
//   php bench.php case=<id> [warmup=N] [iters=N]
// The Connection opens before warm-up; warm-up iterations are discarded;
// each timed iteration is measured on its own with hrtime(), including
// freeing what it returned. run.php starts one fresh process per case.
require __DIR__ . '/bootstrap.php';

parse_str(implode('&', array_slice($argv, 1)), $options);
$id = (string) ($options['case'] ?? '');
$case = bench_cases()[$id] ?? throw new InvalidArgumentException("Unknown case '$id'.");
$warmup = (int) ($options['warmup'] ?? $case['warmup']);
$iterations = (int) ($options['iters'] ?? $case['iters']);

$context = BenchContext::fromEnvironment();
$rssStart = proc_memory()['VmRSS'];
$case['driver'] === 'core' ? $context->core() : $context->oci8();
$rssConnected = proc_memory()['VmRSS'];

$run = $case['make']($context);
$after = $case['after'] ?? null;
$check = static function (array | int $result) use ($case, $id): void {
    $rows = is_int($result) ? $result : count($result);
    if ($rows !== $case['expect']) {
        fwrite(STDERR, "$id: $rows rows, expected {$case['expect']}\n");
        exit(1);
    }
};

for ($i = 0; $i < $warmup; $i++) {
    $check($run($i));
    $after?->__invoke($context);
}
$samples = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = hrtime(true);
    $result = $run($warmup + $i);
    $rows = is_int($result) ? $result : count($result);
    unset($result);
    $samples[] = hrtime(true) - $start;
    $check($rows);
    $after?->__invoke($context);
}
$end = proc_memory();

echo json_encode([
    'case' => $id,
    'driver' => $case['driver'],
    'rows' => $case['expect'],
    'php' => PHP_VERSION . (PHP_ZTS ? ' ZTS' : ' NTS') . (function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false) ? ', opcache' : ''),
    'samples_ns' => $samples,
    'mem_kb' => [
        'rss_start' => $rssStart,
        'rss_connected' => $rssConnected,
        'rss_end' => $end['VmRSS'],
        'hwm_end' => $end['VmHWM'],
        'zend_peak' => intdiv(memory_get_peak_usage(true), 1024),
    ],
]), "\n";
