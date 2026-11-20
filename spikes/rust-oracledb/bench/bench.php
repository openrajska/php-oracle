<?php
// Times one driver on one workload in a fresh process and prints one JSON line.
//   php bench.php driver=oci8|rajska workload=scalar|fetch|cursor mode=row|all
//                 typed=0|1 arraysize=0 warmup=N iters=N
// Every iteration is timed on its own with hrtime(); warm-up iterations are
// run first and discarded. The Connection is opened once, before warm-up.
require __DIR__ . '/drivers.php';

parse_str(implode('&', array_slice($argv, 1)), $o);
$driver = $o['driver'];
$workload = $o['workload'];
$mode = $o['mode'] ?? 'row';
$typed = (bool) ($o['typed'] ?? 0);
$arraysize = (int) ($o['arraysize'] ?? 0);
$warmup = (int) $o['warmup'];
$iters = (int) $o['iters'];

/** kB values from /proc/self/status (VmRSS, VmHWM). */
function proc_mem(): array
{
    $m = [];
    foreach (file('/proc/self/status') as $line) {
        if (preg_match('/^(VmRSS|VmHWM):\s+(\d+) kB/', $line, $x)) {
            $m[$x[1]] = (int) $x[2];
        }
    }
    return $m;
}

$rssStart = proc_mem()['VmRSS'];
$d = make_driver($driver);
$rssConnected = proc_mem()['VmRSS'];

$run = match ($workload) {
    'scalar' => fn (int $i) => count($d->scalar($i, $typed)) === 3 ? 1 : 0,
    'fetch' => fn (int $i) => $d->fetch($mode, $typed, $arraysize),
    'cursor' => fn (int $i) => $d->cursor($mode, $typed),
};
$expect = match ($workload) { 'scalar' => 1, 'fetch' => FETCH_ROWS, 'cursor' => CURSOR_ROWS };

for ($i = 0; $i < $warmup; $i++) {
    $run($i);
}
$samples = [];
for ($i = 0; $i < $iters; $i++) {
    $t = hrtime(true);
    $rows = $run($i);
    $samples[] = hrtime(true) - $t;
    if ($rows !== $expect) {
        fwrite(STDERR, "wrong row count $rows (expected $expect)\n");
        exit(1);
    }
}
$end = proc_mem();

echo json_encode([
    'driver' => $driver,
    'workload' => $workload,
    'mode' => $workload === 'scalar' ? '-' : $mode,
    'typed' => $typed,
    'arraysize' => $arraysize,
    'rows' => $expect,
    'php' => PHP_VERSION . (PHP_ZTS ? ' ZTS' : ' NTS'),
    'samples_ns' => $samples,
    'mem_kb' => [
        'rss_start' => $rssStart,
        'rss_connected' => $rssConnected,
        'rss_end' => $end['VmRSS'],
        'hwm_end' => $end['VmHWM'],
        'zend_peak' => intdiv(memory_get_peak_usage(true), 1024),
    ],
]), "\n";
