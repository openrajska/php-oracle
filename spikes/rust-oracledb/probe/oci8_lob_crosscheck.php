<?php
// Throwaway oci8 cross-check for the LOB probe (#10). oci8 is only the
// baseline: it shows what PHP users get today for the same reads and writes.
// Case ids match src/bin/lob.rs. Rolls everything back at the end.
// Run: ddev exec bash -s <<< 'NLS_LANG=.AL32UTF8 php -d memory_limit=2G spikes/rust-oracledb/probe/oci8_lob_crosscheck.php'
$c = oci_connect(getenv('ORACLE_USER'), getenv('ORACLE_PASSWORD'), getenv('ORACLE_CONNECT_STRING'), 'AL32UTF8');

function line(string $id, string $v): void { printf("%-5s %s\n", $id, $v); }

function row(string $sql, array $binds = [], int $mode = OCI_NUM | OCI_RETURN_NULLS): array {
    global $c;
    $s = oci_parse($c, $sql);
    foreach ($binds as $k => $v) { oci_bind_by_name($s, $k, $binds[$k]); }
    oci_execute($s, OCI_NO_AUTO_COMMIT);
    return oci_fetch_array($s, $mode);
}

/** The CLOB text_line(n) of the fixture. */
function text_line(int $n): string { return sprintf('%08d ação café 日本 %s', $n, str_repeat('x', 41)) . "\n"; }

/** Streams $lob in $chunk units, comparing with the generator; returns stats. */
function stream(OCILob $lob, int $chunk, callable $expected): string {
    $t = microtime(true);
    memory_reset_peak_usage();
    $bytes = 0; $calls = 0; $ok = true; $want = '';
    while (!$lob->eof()) {
        $data = $lob->read($chunk);
        $calls++;
        $bytes += strlen($data);
        $want .= $expected(strlen($data) + 1024);
        if ($ok && strncmp($data, $want, strlen($data)) !== 0) { $ok = false; }
        $want = substr($want, strlen($data));
    }
    $secs = microtime(true) - $t;
    return sprintf('(%d, %s) ~ %.2f s, %.0f MiB/s, %d reads, peak PHP memory %.1f MiB',
        $bytes, $ok ? 'true' : 'false', $secs, $bytes / 1048576 / $secs, $calls, memory_get_peak_usage() / 1048576);
}

function text_gen(): callable {
    $n = 1; $buf = '';
    return function (int $want) use (&$n, &$buf) {
        while (strlen($buf) < $want) { $buf .= text_line($n++); }
        $out = substr($buf, 0, $want); $buf = substr($buf, $want); return $out;
    };
}

function bin_gen(): callable {
    $k = 0; $block = '';
    for ($i = 0; $i < 256; $i++) { $block .= chr($i); }
    return function (int $want) use (&$k, $block) {
        $off = $k % 256;
        $out = substr(str_repeat($block, intdiv($want + $off, 256) + 1), $off, $want);
        $k += $want; return $out;
    };
}

// I1-I3: inline (OCI_RETURN_LOBS): small, empty, NULL.
foreach ([1 => 'I1', 2 => 'I2', 3 => 'I3'] as $id => $case) {
    $r = row('select text_value, binary_value from fx_lobs where id = :id', [':id' => $id], OCI_NUM | OCI_RETURN_NULLS | OCI_RETURN_LOBS);
    line($case, json_encode([$r[0], $r[1] === null ? null : strtoupper(bin2hex($r[1]))], JSON_UNESCAPED_UNICODE));
}

// L4: sizes through descriptors (CLOB size in characters).
$r = row('select text_value, binary_value from fx_lobs where id = 4');
line('L4', json_encode([$r[0]->size(), $r[1]->size()]));

// S1 / S2: 16 MiB streamed, 64 KiB (CLOB: 64 Ki characters per read).
$r = row('select text_value, binary_value from fx_lobs where id = 4');
line('S1', stream($r[0], 65536, text_gen()));
line('S2', stream($r[1], 65536, bin_gen()));

// S8: CLOB of 200,000 emoji streamed in 21,845-character reads.
$s = oci_parse($c, "declare l clob; begin dbms_lob.createtemporary(l, true); for i in 1 .. 200 loop dbms_lob.append(l, to_clob(rpad(unistr('\\D83D\\DE00'), 1000, unistr('\\D83D\\DE00')))); end loop; :l := l; end;");
$l = oci_new_descriptor($c, OCI_D_LOB);
oci_bind_by_name($s, ':l', $l, -1, OCI_B_CLOB);
oci_execute($s, OCI_NO_AUTO_COMMIT);
$got = ''; $err = null;
while (!$l->eof()) { $d = $l->read(21845); if ($d === false) { $err = 'read false'; break; } $got .= $d; }
line('S8', json_encode(['bytes' => strlen($got), 'valid_utf8' => mb_check_encoding($got, 'UTF-8'), 'all_emoji' => $got === str_repeat('😀', strlen($got) / 4), 'err' => $err]));

// H0 / H4 / H5: 100 MiB reference row generated server-side, streamed with 1 MiB reads.
$t = microtime(true);
oci_execute(oci_parse($c, 'begin fx_lob.generate(5, 100); end;'), OCI_NO_AUTO_COMMIT);
line('H0', sprintf('%.1f s', microtime(true) - $t));
$r = row('select text_value, binary_value from fx_lobs where id = 5');
line('H4', stream($r[0], 1048576, text_gen()));
line('H5', stream($r[1], 1048576, bin_gen()));

// H1 / H2: 100 MiB written through a descriptor in ~1 MiB pieces, then compared server-side.
oci_execute(oci_parse($c, "insert into fx_lobs (id, label, text_value, binary_value) values (6, 'probe', empty_clob(), empty_blob())"), OCI_NO_AUTO_COMMIT);
$r = row('select text_value, binary_value from fx_lobs where id = 6 for update');
memory_reset_peak_usage();
$t = microtime(true);
for ($n = 1; $n <= 100 * 16384; $n += 16384) {
    $piece = ''; for ($i = $n; $i < $n + 16384; $i++) { $piece .= text_line($i); }
    $r[0]->write($piece);
}
line('H1', sprintf('%.2f s, peak PHP memory %.1f MiB', microtime(true) - $t, memory_get_peak_usage() / 1048576));
$t = microtime(true);
$gen = bin_gen();
for ($i = 0; $i < 100; $i++) { $r[1]->write($gen(1048576)); }
line('H2', sprintf('%.2f s', microtime(true) - $t));
line('H3', json_encode(row('select dbms_lob.getlength(a.text_value), dbms_lob.compare(a.text_value, b.text_value), dbms_lob.getlength(a.binary_value), dbms_lob.compare(a.binary_value, b.binary_value) from fx_lobs a, fx_lobs b where a.id = 6 and b.id = 5')));

// W6: a PHP string bound to a PL/SQL CLOB IN parameter, past 32767 bytes.
foreach ([32767, 32768] as $n) {
    $s = oci_parse($c, 'begin :n := fx_lob.text_length(:t); end;');
    $t = str_repeat('a', $n); $out = null;
    oci_bind_by_name($s, ':t', $t);
    oci_bind_by_name($s, ':n', $out, 40);
    $ok = @oci_execute($s, OCI_NO_AUTO_COMMIT);
    line('W6', "$n: " . ($ok ? $out : oci_error($s)['message']));
}
// W6 (oci8 way): a temporary LOB bound as OCI_B_CLOB.
$s = oci_parse($c, 'begin :n := fx_lob.text_length(:t); end;');
$lob = oci_new_descriptor($c, OCI_D_LOB);
oci_bind_by_name($s, ':t', $lob, -1, OCI_B_CLOB);
oci_bind_by_name($s, ':n', $out, 40);
$lob->writeTemporary(str_repeat('a', 16 << 20), OCI_TEMP_CLOB);
oci_execute($s, OCI_NO_AUTO_COMMIT);
line('W6t', "16 MiB temporary LOB bound OCI_B_CLOB: $out");

// I4 / I5: inline (OCI_RETURN_LOBS) 16 MiB and 100 MiB.
foreach ([4 => 'I4', 6 => 'I5'] as $id => $case) {
    memory_reset_peak_usage();
    $t = microtime(true);
    $r = row('select text_value, binary_value from fx_lobs where id = :id', [':id' => $id], OCI_NUM | OCI_RETURN_LOBS);
    line($case, sprintf('%d bytes, %.2f s, peak PHP memory %.1f MiB', strlen($r[0]) + strlen($r[1]), microtime(true) - $t, memory_get_peak_usage() / 1048576));
    unset($r);
}

oci_rollback($c);
