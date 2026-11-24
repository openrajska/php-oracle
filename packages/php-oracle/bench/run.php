<?php

declare(strict_types=1);

// Runs the benchmark matrix and writes output/raw-<tag>.jsonl and
// output/summary-<tag>.md.
//   php run.php extension=/abs/librajska_oracle.so [rounds=10] [tag=nts]
//               [only=call,fetch] [php=php] [scale=1]
//
// - Every (case, round) is a fresh PHP process started with bench/php.ini
//   alone and only its own driver, so neither driver's libraries count in
//   the other's memory and both run under the same settings.
// - Rounds interleave the cases of a scenario, forward on odd rounds and
//   backward on even ones, so drift (thermal, database caches) hits both
//   drivers alike.
// - The median pools every timed iteration of every round; the per-round
//   medians give the spread.
// - Speedup = oci8 median / Core median: above 1, Core is faster.
// - scale multiplies warm-up and iterations (0.2 for a quick look).
require __DIR__ . '/bootstrap.php';

use Rajska\Oracle\Bench\Stats;

parse_str(implode('&', array_slice($argv, 1)), $options);
$tag = (string) ($options['tag'] ?? 'nts');
$php = (string) ($options['php'] ?? PHP_BINARY);
$extension = (string) ($options['extension'] ?? throw new InvalidArgumentException('Pass extension=/abs/path/to/librajska_oracle.so'));
$rounds = (int) ($options['rounds'] ?? 10);
$scale = (float) ($options['scale'] ?? 1);
$only = isset($options['only']) ? explode(',', (string) $options['only']) : null;

$cases = array_filter(bench_cases(), static fn (array $case): bool => $only === null || in_array($case['scenario'], $only, true));
$scenarios = [];
foreach ($cases as $id => $case) {
    $scenarios[$case['scenario']][] = $id;
}

@mkdir(__DIR__ . '/output');
$rawFile = __DIR__ . "/output/raw-$tag.jsonl";
file_put_contents($rawFile, '');
/** @var array<string, list<array>> $results case => one result per round */
$results = [];
$started = time();
$load = static fn (): string => implode(' ', array_slice(explode(' ', (string) @file_get_contents('/proc/loadavg')), 0, 3));
$loadStart = $load();
for ($round = 1; $round <= $rounds; $round++) {
    foreach ($scenarios as $ids) {
        foreach ($round % 2 === 1 ? $ids : array_reverse($ids) as $id) {
            $case = $cases[$id];
            $command = sprintf(
                'PHP_INI_SCAN_DIR= %s -c %s -d extension=%s %s case=%s warmup=%d iters=%d 2>&1',
                escapeshellarg($php),
                escapeshellarg(__DIR__ . '/php.ini'),
                escapeshellarg($case['driver'] === 'core' ? $extension : 'oci8'),
                escapeshellarg(__DIR__ . '/bench.php'),
                escapeshellarg($id),
                max(1, (int) round($case['warmup'] * $scale)),
                max(3, (int) round($case['iters'] * $scale)),
            );
            // One retry: a shared database can refuse a logon now and then.
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $output = (string) shell_exec($command);
                $result = json_decode(trim($output), true);
                if (is_array($result)) {
                    break;
                }
                fwrite(STDERR, "attempt $attempt failed: $id\n" . substr($output, 0, 500) . "\n");
            }
            if (!is_array($result)) {
                fwrite(STDERR, "FAILED: $command\n$output\n");
                exit(1);
            }
            $result['round'] = $round;
            file_put_contents($rawFile, json_encode($result) . "\n", FILE_APPEND);
            $results[$id][] = $result;
            fwrite(STDERR, sprintf("round %d/%d %-34s median %s\n", $round, $rounds, $id, duration(Stats::median($result['samples_ns']))));
        }
    }
}

function duration(float $ns): string
{
    return match (true) {
        $ns >= 1e6 => sprintf('%.2f ms', $ns / 1e6),
        default => sprintf('%.1f µs', $ns / 1e3),
    };
}

function verdict(float $speedup): string
{
    return match (true) {
        $speedup >= 2.0 => '**≥ 2×, goal met**',
        $speedup >= 1.0 => 'faster, below 2×',
        $speedup >= 0.5 => '**slower than oci8**',
        default => '**below the 2×-slower floor**',
    };
}

/** @return list<int|float> */
function pooled(array $runs): array
{
    return array_merge(...array_map(static fn (array $run): array => $run['samples_ns'], $runs));
}

$first = reset($results)[0];
$md = "# Benchmark summary: $tag\n\n";
$md .= sprintf(
    "PHP %s; %d rounds (%s); each (case, round) is a fresh process with `bench/php.ini` and one driver. A sample is one iteration: the Call or query, every row read into PHP, and freeing it. Speedup = oci8 median / Core median (> 1: Core is faster). Run took %d min; load average %s at the start, %s at the end.\n\n",
    $first['php'], $rounds, $scale == 1 ? 'full iterations' : "iterations × $scale", intdiv(time() - $started, 60), $loadStart, $load(),
);
$md .= "## Core against oci8\n\n`same values`: both sides return identical PHP values (`crosscheck.php`). Otherwise oci8 returns its strings, the work it does by default.\n\n";
$md .= "| Comparison | Core | oci8 | Core median (per-round spread) | oci8 median (per-round spread) | Speedup | Spike | Verdict |\n|---|---|---|---|---|---|---|---|\n";
foreach (bench_comparisons() as $comparison) {
    if (!isset($results[$comparison['core']], $results[$comparison['oci8']])) {
        continue;
    }
    $cell = static function (string $id) use ($results): string {
        [$low, $high] = Stats::spread(array_map(static fn (array $run): array => $run['samples_ns'], $results[$id]));

        return sprintf('%s (%s–%s)', duration(Stats::median(pooled($results[$id]))), duration($low), duration($high));
    };
    $speedup = Stats::median(pooled($results[$comparison['oci8']])) / Stats::median(pooled($results[$comparison['core']]));
    $md .= sprintf(
        "| %s%s | `%s` | `%s` | %s | %s | %.2f× | %s | %s |\n",
        $comparison['label'], $comparison['same'] ? ' (same values)' : '',
        $comparison['core'], $comparison['oci8'], $cell($comparison['core']), $cell($comparison['oci8']),
        $speedup, $comparison['spike'] ?? '–', verdict($speedup),
    );
}

$md .= "\n## Every case\n\n| Case | Driver | Iterations × rounds | Median | p95 | Per-round medians | Rows/s |\n|---|---|---|---|---|---|---|\n";
foreach ($results as $id => $runs) {
    $samples = pooled($runs);
    $median = Stats::median($samples);
    [$low, $high] = Stats::spread(array_map(static fn (array $run): array => $run['samples_ns'], $runs));
    $md .= sprintf(
        "| `%s` | %s | %d × %d | %s | %s | %s–%s | %s |\n",
        $id, $runs[0]['driver'], count($runs[0]['samples_ns']), count($runs), duration($median), duration(Stats::percentile($samples, 95)),
        duration($low), duration($high), $runs[0]['rows'] >= 1000 ? number_format($runs[0]['rows'] / ($median / 1e9)) : '–',
    );
}

$md .= "\n## Memory (kB, median over rounds)\n\n`rss_start` is before connecting, `rss_connected` after, `hwm_end` the process's peak RSS (Rust or Instant Client heaps included), `zend_peak` PHP's own allocator peak.\n\n";
$md .= "| Case | Driver | rss_start | rss_connected | hwm_end | zend_peak |\n|---|---|---|---|---|---|\n";
foreach ($results as $id => $runs) {
    $memory = static fn (string $key): int => (int) Stats::median(array_map(static fn (array $run): int => $run['mem_kb'][$key], $runs));
    $md .= sprintf("| `%s` | %s | %d | %d | %d | %d |\n", $id, $runs[0]['driver'], $memory('rss_start'), $memory('rss_connected'), $memory('hwm_end'), $memory('zend_peak'));
}

file_put_contents(__DIR__ . "/output/summary-$tag.md", $md);
echo $md;
