<?php
// Throwaway oci8 cross-check for the scalar-binds probe (#7). oci8 is only the
// baseline: it shows what the established client returns for the same Calls.
// Run: ddev exec bash -s <<< 'NLS_LANG=.AL32UTF8 php spikes/rust-oracledb/probe/oci8_crosscheck.php'
$c = oci_connect(getenv('ORACLE_USER'), getenv('ORACLE_PASSWORD'), getenv('ORACLE_CONNECT_STRING'), 'AL32UTF8');
foreach ([
    "alter session set nls_numeric_characters = '.,'",
    "alter session set nls_date_format = 'YYYY-MM-DD HH24:MI:SS'",
    "alter session set nls_timestamp_format = 'YYYY-MM-DD HH24:MI:SS.FF9'",
    "alter session set nls_timestamp_tz_format = 'YYYY-MM-DD HH24:MI:SS.FF9 TZH:TZM'",
] as $sql) { oci_execute(oci_parse($c, $sql)); }

function out1($c, string $id, string $plsql, int $len = 4000, int $type = SQLT_CHR): void {
    $s = oci_parse($c, $plsql);
    $o = null;
    oci_bind_by_name($s, ':o', $o, $len, $type);
    $ok = @oci_execute($s);
    $v = $ok ? var_export($o, true) : 'Err(' . oci_error($s)['message'] . ')';
    printf("%-5s %s\n", $id, $ok && strlen($v) > 120 ? '<' . mb_strlen((string) $o) . ' chars>' : $v);
}

out1($c, 'N8', 'begin :o := 1/3; end;');
out1($c, 'N9', 'begin :o := 9.999999999999999999999999999999999999999e125; end;');
out1($c, 'N10', 'begin :o := 1e-130; end;');
$s = oci_parse($c, 'begin :o := :i; end;'); $i = 0.1; $o = null;
oci_bind_by_name($s, ':i', $i); oci_bind_by_name($s, ':o', $o, 100); oci_execute($s);
printf("%-5s %s\n", 'N7', var_export($o, true) . ' (PHP float 0.1 bound by oci8)');
out1($c, 'S2', "begin :o := unistr('A\\00e7\\00e3o Caf\\00e9 Stra\\00dfe \\65e5\\672c \\D83D\\DE00'); end;");
out1($c, 'S7', "begin fx_scalar.repeat_out('a', 32767, :o); end;", 32767);
out1($c, 'S7x', "begin fx_scalar.repeat_out('a', 32767, :o); end;", 4000);
out1($c, 'D5b', "declare d date; begin d := to_timestamp('2026-11-19 13:45:30.999999999', 'YYYY-MM-DD HH24:MI:SS.FF9'); :o := d; end;");
out1($c, 'T4', "begin :o := to_timestamp_tz('2026-11-19 13:45:30.5 -03:00', 'YYYY-MM-DD HH24:MI:SS.FF TZH:TZM'); end;");
out1($c, 'T5', "begin :o := to_timestamp_tz('2026-11-19 13:45:30 America/Sao_Paulo', 'YYYY-MM-DD HH24:MI:SS TZR'); end;");
out1($c, 'T5c', "begin :o := sessiontimezone || ' / ' || dbtimezone; end;");
$s = oci_parse($c, 'begin :v := :v || rpad(\'x\', 100, \'x\'); end;'); $v = null;
oci_bind_by_name($s, ':v', $v, 200); oci_execute($s);
printf("%-5s %s\n", 'S14b', 'len=' . strlen($v) . ' (NULL IN OUT, maxlength 200)');
$s = oci_parse($c, 'begin :r := fx_scalar.add_numbers(:a, 1); end;'); $a = '12345678901234567890123456789012345678'; $r = null;
oci_bind_by_name($s, ':a', $a); oci_bind_by_name($s, ':r', $r, 50); oci_execute($s);
printf("%-5s %s\n", 'F2', var_export($r, true));
