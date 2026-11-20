<?php
// Checks that oci8 and rajska_oracle return identical PHP values on every
// workload, shape and fetch mode before anything is timed. Needs both extensions:
//   php -n -d extension=oci8.so -d extension=/abs/librajska_oracle.so crosscheck.php
require __DIR__ . '/drivers.php';

/** Values with their PHP types, DateTimeImmutable rendered with microseconds. */
function norm(mixed $v): mixed
{
    if (is_array($v)) {
        return array_map('norm', $v);
    }
    if ($v instanceof DateTimeImmutable) {
        return 'DateTimeImmutable(' . $v->format('Y-m-d H:i:s.u') . ')';
    }
    return get_debug_type($v) . '(' . var_export($v, true) . ')';
}

function collect(BenchDriver $d, string $what, string $mode, bool $typed): array
{
    $rows = [];
    $sink = function (array $batch) use (&$rows) {
        foreach ($batch as $r) {
            $rows[] = $r;
        }
    };
    match ($what) {
        'fetch' => $d->fetch($mode, $typed, 0, $sink),
        'cursor' => $d->cursor($mode, $typed, $sink),
    };
    usort($rows, fn ($a, $b) => (int) $a['ID'] <=> (int) $b['ID']);
    return norm($rows);
}

$oci = make_driver('oci8');
$rjs = make_driver('rajska');
$fail = 0;
$check = function (string $what, array $a, array $b) use (&$fail) {
    $ok = $a === $b;
    $detail = count($a) . ' values';
    if (!$ok) {
        $fail++;
        $flatA = explode("\n", var_export($a, true));
        $flatB = explode("\n", var_export($b, true));
        foreach ($flatA as $i => $line) {
            if (($flatB[$i] ?? null) !== $line) {
                $detail = "first diff at line $i: oci8 " . trim($line) . ' | rajska ' . trim($flatB[$i] ?? '(missing)');
                break;
            }
        }
    }
    echo $ok ? 'PASS ' : 'FAIL ', $what, ' => ', $detail, "\n";
};

foreach ([false, true] as $typed) {
    $shape = $typed ? 'typed' : 'plain';
    $check("(a) scalar $shape", norm([$oci->scalar(7, $typed), $oci->scalar(-3, $typed)]), norm([$rjs->scalar(7, $typed), $rjs->scalar(-3, $typed)]));
    foreach (['row', 'all'] as $mode) {
        $check("(b) fetch $shape $mode", collect($oci, 'fetch', $mode, $typed), collect($rjs, 'fetch', $mode, $typed));
        $check("(c) cursor $shape $mode", collect($oci, 'cursor', $mode, $typed), collect($rjs, 'cursor', $mode, $typed));
    }
}

// Show one row of each shape, for the record.
$rows = [];
$rjs->fetch('row', false, 0, function ($b) use (&$rows) { if (count($rows) < 1 || $b[0]['ID'] === '10000') $rows[] = $b[0]; });
echo 'OBS  plain rows: ', json_encode($rows, JSON_UNESCAPED_UNICODE), "\n";
echo 'OBS  scalar plain: ', json_encode($rjs->scalar(7, false), JSON_UNESCAPED_UNICODE), "\n";
echo 'OBS  scalar typed: ', json_encode(norm($rjs->scalar(7, true)), JSON_UNESCAPED_UNICODE), "\n";

echo $fail === 0 ? "RESULT ok\n" : "RESULT $fail failed\n";
exit($fail === 0 ? 0 : 1);
