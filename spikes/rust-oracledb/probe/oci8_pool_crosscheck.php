<?php
// Throwaway oci8 cross-check for the Pool probe (#11). oci8 is only the
// baseline: it shows, against the same database, whether a DRCP pooled server
// goes back to the broker when the PHP side is idle, whether
// POOL_BOUNDARY=TRANSACTION (implicit connection pooling) releases it, and
// what a call timeout leaves behind. Case ids match src/bin/pool.rs.
// DRCP is observed as SYS AS SYSDBA on the CDB (v$cpool_stats).
// Run: ddev exec bash -s <<< 'php -d oci8.privileged_connect=1 -d oci8.connection_class=RAJSKA_PROBE spikes/rust-oracledb/probe/oci8_pool_crosscheck.php'
$user = getenv('ORACLE_USER');
$pass = getenv('ORACLE_PASSWORD');
$cs = getenv('ORACLE_CONNECT_STRING');               // oracle:1521/FREEPDB1
[$hp, $svc] = explode('/', $cs, 2);
[$host, $port] = explode(':', $hp, 2);
$pooled = "$cs:pooled";
$descriptor = fn (string $extra) => "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=$host)(PORT=$port))"
    . "(CONNECT_DATA=(SERVICE_NAME=$svc)(SERVER=POOLED)$extra))";

$o = oci_connect('sys', getenv('ORACLE_SYS_PASSWORD') ?: 'oracle', "$host:$port/FREE", "AL32UTF8", OCI_SYSDBA);

function line(string $id, string $v): void { printf("%-5s %s\n", $id, $v); }

function one($c, string $sql): mixed {
    $s = @oci_parse($c, $sql);
    if ($s === false) { return "Err(" . strtok(oci_error($c)["message"] ?? "?", "\n") . ")"; }
    if (!@oci_execute($s, OCI_NO_AUTO_COMMIT)) { return 'Err(' . (oci_error($s)['message'] ?? '?') . ')'; }
    $r = oci_fetch_array($s, OCI_NUM | OCI_RETURN_NULLS);
    return $r[0];
}

function cpool($o): array {
    $s = oci_parse($o, "select num_busy_servers, num_requests, num_hits, num_misses from v\$cpool_stats where pool_name = 'SYS_DEFAULT_CONNECTION_POOL'");
    oci_execute($s);
    return array_map('intval', oci_fetch_array($s, OCI_NUM));
}

function busy($o): int { usleep(300_000); return cpool($o)[0]; }

// D1b: a non-persistent connection over :pooled, idle, then closed.
$b0 = busy($o);
$c = oci_connect($user, $pass, $pooled);
$sum = one($c, 'select fx_scalar.add_numbers(2, 3) from dual');
$b1 = busy($o);
oci_close($c);
$b2 = busy($o);
line('D1b', "oci_connect :pooled, add_numbers(2,3) = $sum; busy (before, connected and idle, after close) = ($b0, $b1, $b2)");

// D3d: a persistent connection (what a PHP-FPM worker keeps) over :pooled.
// oci8 releases the pooled server at oci_close / end of request but keeps the
// connection cached.
$s0 = cpool($o);
$c = oci_pconnect($user, $pass, $pooled);
one($c, 'select 1 from dual');
$b1 = busy($o);
oci_close($c);
$b2 = busy($o);
$c = oci_pconnect($user, $pass, $pooled);
one($c, 'select 1 from dual');
oci_close($c);
$s1 = cpool($o);
line('D3d', "oci_pconnect :pooled: busy while held = $b1, after oci_close (connection still cached) = $b2; "
    . sprintf('requests/hits/misses delta over two pconnects = (%d, %d, %d)', $s1[1] - $s0[1], $s1[2] - $s0[2], $s1[3] - $s0[3]));

// D4: implicit connection pooling with POOL_BOUNDARY=TRANSACTION.
$b0 = busy($o);
$c = oci_connect($user, $pass, $descriptor('(POOL_BOUNDARY=TRANSACTION)'));
$sid1 = one($c, 'select fx_pool.session_id from dual');
$b1 = busy($o);
$sum = one($c, 'select fx_scalar.add_numbers(2, 3) from dual');
oci_close($c);
$b2 = busy($o);
line('D4', "POOL_BOUNDARY=TRANSACTION: add_numbers(2,3) = $sum; busy (before, connected and idle, after close) = ($b0, $b1, $b2)");

// C1 / C1d: call timeout, then is the same connection usable?
foreach ([
    'C1' => ['begin fx_pool.sleep(3); end;', 500],
    'C1c' => ['begin fx_pool.sleep(3); end;', 2000],
    'C1d' => ['select count(*) from all_objects a, all_objects b', 500],
] as $id => [$sql, $ms]) {
    $c = oci_new_connect($user, $pass, $cs);
    $sid0 = one($c, 'select fx_pool.session_id from dual');
    oci_set_call_timeout($c, $ms);
    $t = microtime(true);
    $st = oci_parse($c, $sql);
    $ok = @oci_execute($st);
    $el = microtime(true) - $t;
    $err = $ok ? 'no error' : (oci_error($st)['message'] ?? '?');
    $after = one($c, 'select 1 from dual');
    $same = one($c, 'select fx_pool.session_id from dual') == $sid0 ? 'true' : 'false';
    line($id, sprintf('timeout %d ms: %.3f s, %s; same connection after: select 1 = %s, same Session = %s',
        $ms, $el, strtok($err, "\n"), $after, $same));
    oci_close($c);
}
