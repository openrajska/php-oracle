<?php
// Smoke test for the rajska_oracle build probe (#12). Run with the extension loaded:
//   php -n -d extension=/abs/path/rajska_oracle.so smoke.php
// Exit code 0 only if every hard check passes. Lines: PASS / FAIL / OBS.

$fail = 0;
$check = function (string $what, bool $ok, string $detail) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL '), $what, ' => ', substr($detail, 0, 300), "\n";
    if (!$ok) $fail++;
};

$check('extension loaded', extension_loaded('rajska_oracle'), implode(',', array_filter(get_loaded_extensions(), fn ($e) => $e === 'rajska_oracle')));
echo 'OBS  php ', PHP_VERSION, ' zts=', PHP_ZTS ? 'yes' : 'no', ' os=', PHP_OS_FAMILY, ' arch=', php_uname('m'), "\n";
echo 'OBS  ', rajska_probe_info(), "\n";

// TLS stack (rustls on aws-lc-rs) runs: a self-signed certificate is rejected by verification.
$r = rajska_probe_query('tcps://self-signed.badssl.com:443/x', 'a', 'b', 'select 1 from dual');
$check('TLS rejects a self-signed certificate', str_contains($r, 'UnknownIssuer'), $r);
// A valid certificate passes the handshake; the peer then closes (it is not Oracle).
$r = rajska_probe_query('tcps://badssl.com:443/x', 'a', 'b', 'select 1 from dual');
$check('TLS handshake with a valid certificate', !str_contains($r, 'certificate') && str_starts_with($r, 'ERR:'), $r);
// Plain TCP to a closed port fails cleanly instead of crashing PHP.
$r = rajska_probe_query('127.0.0.1:1/x', 'a', 'b', 'select 1 from dual');
$check('connection refused is an error, not a crash', str_starts_with($r, 'ERR:'), $r);

// A real Call when a database is reachable (Linux jobs run Oracle Free in Docker).
$dsn = getenv('ORACLE_CONNECT_STRING');
if ($dsn) {
    // The database may still be starting: retry for up to 5 minutes.
    for ($i = 0; $i < 60; $i++) {
        $r = rajska_probe_query($dsn, getenv('ORACLE_USER'), getenv('ORACLE_PASSWORD'),
            "select banner_full || ' | ' || sys_context('userenv','session_user') from v\$version");
        if (!str_starts_with($r, 'ERR:')) break;
        sleep(5);
    }
    $check('real query against Oracle', !str_starts_with($r, 'ERR:'), $r);
} else {
    echo "OBS  no ORACLE_CONNECT_STRING: real query skipped\n";
}

echo $fail === 0 ? "RESULT ok\n" : "RESULT $fail failed\n";
exit($fail === 0 ? 0 : 1);
