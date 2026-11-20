<?php
// Runs the benchmark matrix and writes output/raw-<tag>.jsonl and output/summary-<tag>.md.
//   php run.php tag=nts php=php oci8=oci8.so rajska=/abs/librajska_oracle.so rounds=5 [only=scalar]
//
// Methodology:
// - every (case, driver, round) is a fresh PHP process started with -n and only
//   its own extension, so neither driver's libraries sit in the other's RSS;
// - rounds interleave the drivers, oci8 first on odd rounds and rajska first on
//   even rounds, so drift (thermal, database caches) hits both alike;
// - the median pools every timed iteration of every round; the per-round
//   medians are reported as a min–max spread to show stability;
// - speedup = oci8 median / rajska median (> 1 means rajska_oracle is faster).
parse_str(implode('&', array_slice($argv, 1)), $o);
$tag = $o['tag'] ?? 'nts';
$php = $o['php'] ?? 'php';
$ext = ['oci8' => $o['oci8'] ?? 'oci8.so', 'rajska' => $o['rajska'] ?? '/var/cache/cargo-target/release/librajska_oracle.so'];
$rounds = (int) ($o['rounds'] ?? 5);
$only = $o['only'] ?? null;

// [workload, mode, typed, arraysize, warmup, iters, gated]
$cases = [
    ['scalar', '-', 0, 0, 1000, 10000, true],
    ['scalar', '-', 1, 0, 1000, 10000, false],
    ['fetch', 'row', 0, 0, 5, 50, true],
    ['fetch', 'all', 0, 0, 5, 50, true],
    ['fetch', 'row', 1, 0, 5, 50, false],
    ['fetch', 'all', 1, 0, 5, 50, false],
    ['fetch', 'row', 0, 1000, 5, 50, false],
    ['fetch', 'all', 0, 1000, 5, 50, false],
    ['cursor', 'row', 0, 0, 50, 300, true],
    ['cursor', 'all', 0, 0, 50, 300, true],
    ['cursor', 'row', 1, 0, 50, 300, false],
    ['cursor', 'all', 1, 0, 50, 300, false],
];
if ($only) {
    $cases = array_values(array_filter($cases, fn ($c) => $c[0] === $only));
}

@mkdir(__DIR__ . '/output');
$rawFile = __DIR__ . "/output/raw-$tag.jsonl";
file_put_contents($rawFile, '');
$results = [];
for ($r = 1; $r <= $rounds; $r++) {
    $order = $r % 2 ? ['oci8', 'rajska'] : ['rajska', 'oci8'];
    foreach ($cases as $ci => [$w, $m, $t, $a, $warm, $it]) {
        foreach ($order as $drv) {
            $cmd = sprintf('%s -n -d extension=%s %s driver=%s workload=%s mode=%s typed=%d arraysize=%d warmup=%d iters=%d 2>&1',
                $php, escapeshellarg($ext[$drv]), escapeshellarg(__DIR__ . '/bench.php'), $drv, $w, $m === '-' ? 'row' : $m, $t, $a, $warm, $it);
            $out = shell_exec($cmd);
            $j = json_decode(trim((string) $out), true);
            if (!$j) {
                fwrite(STDERR, "FAILED: $cmd\n$out\n");
                exit(1);
            }
            $j['round'] = $r;
            file_put_contents($rawFile, json_encode($j) . "\n", FILE_APPEND);
            $results[$ci][$drv][] = $j;
            fwrite(STDERR, sprintf("round %d %-6s %-6s %-3s %s as=%-4d median %.3f ms\n", $r, $drv, $w, $m, $t ? 'typed' : 'plain', $a, median($j['samples_ns']) / 1e6));
        }
    }
}

function median(array $v): float
{
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

function pct(array $v, float $p): float
{
    sort($v);
    return $v[(int) floor(($p / 100) * (count($v) - 1))];
}

function verdict(float $speedup): string
{
    return match (true) {
        $speedup < 0.5 => '**fail** (slower than 2× oci8)',
        $speedup < 1.0 => 'condition (between 2× slower and parity)',
        $speedup < 2.0 => 'pass',
        default => 'pass, **target met** (≥ 2×)',
    };
}

$first = $results[0]['oci8'][0] ?? reset($results)['oci8'][0];
$md = "# Benchmark summary: $tag\n\n";
$md .= sprintf("PHP %s; %d rounds; each (case, driver, round) is a fresh `php -n` process. Time per iteration is one Call (a) or one Call/query plus fetching every row (b, c), including the row → zval conversion. Speedup = oci8 median / rajska_oracle median.\n\n", $first['php'], $rounds);
$md .= "| Workload | Mode | Shape | arraysize | gated | oci8 median | rajska median | oci8 p95 | rajska p95 | rajska per-round medians | speedup | verdict |\n|---|---|---|---|---|---|---|---|---|---|---|---|\n";
$memRows = '';
foreach ($cases as $ci => [$w, $m, $t, $a, $warm, $it, $gated]) {
    $pool = fn ($drv) => array_merge(...array_map(fn ($j) => $j['samples_ns'], $results[$ci][$drv]));
    $o8 = $pool('oci8');
    $rj = $pool('rajska');
    $mo = median($o8);
    $mr = median($rj);
    $roundMed = array_map(fn ($j) => median($j['samples_ns']), $results[$ci]['rajska']);
    $unit = $w === 'scalar' ? 'µs' : 'ms';
    $div = $w === 'scalar' ? 1e3 : 1e6;
    $f = fn ($ns) => sprintf($w === 'scalar' ? '%.1f' : '%.2f', $ns / $div) . " $unit";
    $rows = $results[$ci]['oci8'][0]['rows'];
    $extra = $w === 'scalar' ? '' : sprintf(' (%s rows/s)', number_format($rows / ($mr / 1e9)));
    $extraO = $w === 'scalar' ? '' : sprintf(' (%s rows/s)', number_format($rows / ($mo / 1e9)));
    $sp = $mo / $mr;
    $md .= sprintf("| %s | %s | %s | %s | %s | %s%s | %s%s | %s | %s | %s–%s | %.2f× | %s |\n",
        $w, $m, $t ? 'typed' : 'plain', $a ?: 'default', $gated ? 'yes' : 'no',
        $f($mo), $extraO, $f($mr), $extra, $f(pct($o8, 95)), $f(pct($rj, 95)),
        $f(min($roundMed)), $f(max($roundMed)), $sp, $gated ? verdict($sp) : 'recorded');
    foreach (['oci8', 'rajska'] as $drv) {
        $mk = fn ($k) => median(array_map(fn ($j) => $j['mem_kb'][$k], $results[$ci][$drv]));
        $memRows .= sprintf("| %s | %s | %s | %s | %s | %d | %d | %d | %d |\n", $w, $m, $t ? 'typed' : 'plain', $a ?: 'default', $drv,
            $mk('rss_start'), $mk('rss_connected'), $mk('hwm_end'), $mk('zend_peak'));
    }
}
$md .= "\n## Memory (kB, median over rounds; recorded, not gated)\n\n`rss_start` is before connecting, `rss_connected` after, `hwm_end` is the process peak RSS (it includes Rust and OCI heaps), `zend_peak` is PHP's own allocator peak.\n\n";
$md .= "| Workload | Mode | Shape | arraysize | driver | rss_start | rss_connected | hwm_end | zend_peak |\n|---|---|---|---|---|---|---|---|---|\n" . $memRows;
file_put_contents(__DIR__ . "/output/summary-$tag.md", $md);
echo $md;
