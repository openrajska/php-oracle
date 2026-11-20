<?php
// Splits rajska_oracle's time on workloads (b) and (c) into protocol + decoding
// (rows read and dropped in Rust, countRows()) and the row → zval conversion
// (fetchAll() minus countRows()). Prints medians over 10 rounds x 30 iterations.
//   php -n -d extension=/abs/librajska_oracle.so breakdown.php
require __DIR__ . '/drivers.php';

$c = new Rajska\Bench\Connection(getenv('ORACLE_CONNECT_STRING'), getenv('ORACLE_USER'), getenv('ORACLE_PASSWORD'));
// name => [open(bool $typed): Statement, expected rows]
$cases = [
    '(b) fetch 10k' => [fn (bool $t) => $c->query(SQL_FETCH, [], $t), FETCH_ROWS],
    '(b) fetch 10k arraysize 1000' => [fn (bool $t) => $c->query(SQL_FETCH, [], $t, 1000), FETCH_ROWS],
    '(c) cursor 1k' => [fn (bool $t) => $c->cursor(SQL_CURSOR, ['n' => CURSOR_ROWS], 'rc', $t), CURSOR_ROWS],
];
$median = function (array $v): float { sort($v); return $v[intdiv(count($v), 2)]; };
foreach ($cases as $name => [$open, $rows]) {
    $t = ['countRows' => [], 'fetchAll plain' => [], 'fetchAll typed' => []];
    for ($r = 0; $r < 10; $r++) {
        foreach ($t as $what => $_) {
            for ($i = 0; $i < 33; $i++) {
                $s0 = hrtime(true);
                $n = match ($what) {
                    'countRows' => $open(false)->countRows(),
                    'fetchAll plain' => count($open(false)->fetchAll()),
                    'fetchAll typed' => count($open(true)->fetchAll()),
                };
                if ($i >= 3) {
                    $t[$what][] = hrtime(true) - $s0;
                }
                if ($n !== $rows) {
                    exit("wrong row count $n\n");
                }
            }
        }
    }
    $base = $median($t['countRows']);
    printf("%s: countRows %.2f ms", $name, $base / 1e6);
    foreach (['fetchAll plain', 'fetchAll typed'] as $what) {
        $m = $median($t[$what]);
        printf(" | %s %.2f ms (conversion %.2f ms, %.0f%%)", $what, $m / 1e6, ($m - $base) / 1e6, 100 * ($m - $base) / $m);
    }
    echo "\n";
}
