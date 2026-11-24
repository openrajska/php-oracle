<?php

declare(strict_types=1);

// Proves the comparisons marked `same` compare like with like: Core's case
// and oci8's case return identical PHP values (same keys, types and values).
// Array DML cases are checked through the rows they leave in the table.
// Needs both drivers loaded:
//   php -d extension=oci8 -d extension=<rajska_oracle> crosscheck.php
require __DIR__ . '/bootstrap.php';

use Rajska\Oracle\Bench\Oci8Values;

$context = BenchContext::fromEnvironment();
$cases = bench_cases();
$written = static function () use ($context): array {
    $statement = oci8_query($context->oci8(), 'select id, code, qty from fx_batch_rows where id between 700001 and 701000 order by id');

    return oci8_drain($statement, 'all', 'exact');
};
$valuesOf = static function (string $id) use ($cases, $context, $written): array|int {
    $case = $cases[$id];
    $result = $case['make']($context)(7);
    if ($case['scenario'] === 'insert') {
        $result = $written();
    }
    ($case['after'] ?? null)?->__invoke($context);

    return $result;
};

$failed = 0;
foreach (bench_comparisons() as $comparison) {
    if (!$comparison['same']) {
        continue;
    }
    $core = Oci8Values::canonical((array) $valuesOf($comparison['core']));
    $oci8 = Oci8Values::canonical((array) $valuesOf($comparison['oci8']));
    if ($core === $oci8) {
        printf("same   %s (%s = %s)\n", $comparison['label'], $comparison['core'], $comparison['oci8']);
        continue;
    }
    $failed++;
    $at = strspn($core ^ $oci8, "\0");
    printf("DIFFER %s (%s != %s)\n  core: …%s…\n  oci8: …%s…\n", $comparison['label'], $comparison['core'], $comparison['oci8'],
        substr($core, max(0, $at - 80), 200), substr($oci8, max(0, $at - 80), 200));
}
echo $failed === 0 ? "RESULT ok\n" : "RESULT $failed comparison(s) differ\n";
exit($failed === 0 ? 0 : 1);
