<?php
// Throwaway oci8 cross-check for the REF CURSOR / Implicit results probe (#8).
// oci8 is only the baseline: it shows what the established client returns for
// the same Calls. Case ids match src/bin/cursor.rs.
// Run: ddev exec bash -s <<< 'NLS_LANG=.AL32UTF8 php spikes/rust-oracledb/probe/oci8_cursor_crosscheck.php'
$c = oci_connect(getenv('ORACLE_USER'), getenv('ORACLE_PASSWORD'), getenv('ORACLE_CONNECT_STRING'), 'AL32UTF8');

/** Drains a statement or cursor: [column names, row count, first row, last row]. */
function drain($s): string {
    $cols = [];
    for ($i = 1; $i <= oci_num_fields($s); $i++) { $cols[] = oci_field_name($s, $i); }
    $n = 0; $first = $last = null;
    while (($r = oci_fetch_array($s, OCI_NUM + OCI_RETURN_NULLS)) !== false) {
        $n++; $first ??= $r; $last = $r;
    }
    return sprintf('cols=%s rows=%d first=%s last=%s', implode(',', $cols), $n, json_encode($first, JSON_UNESCAPED_UNICODE), json_encode($last, JSON_UNESCAPED_UNICODE));
}

function line(string $id, string $v): void { printf("%-5s %s\n", $id, $v); }

oci_execute(oci_parse($c, "alter session set nls_date_format = 'YYYY-MM-DD HH24:MI:SS'"));
oci_execute(oci_parse($c, "alter session set nls_numeric_characters = '.,'"));

// C1 / C2 / C3: two REF CURSOR OUT parameters and a scalar OUT in one Call.
$s = oci_parse($c, 'begin fx_cursor.two_cursors(:p_limit, :o_items, :o_categories, :o_total); end;');
$limit = 250; $items = oci_new_cursor($c); $cats = oci_new_cursor($c); $total = null;
oci_bind_by_name($s, ':p_limit', $limit);
oci_bind_by_name($s, ':o_items', $items, -1, OCI_B_CURSOR);
oci_bind_by_name($s, ':o_categories', $cats, -1, OCI_B_CURSOR);
oci_bind_by_name($s, ':o_total', $total, 40);
oci_execute($s);
oci_execute($items); oci_execute($cats);
line('C1', drain($items));
line('C2', drain($cats));
line('C3', var_export($total, true));

// C11: REF CURSOR OUT never opened by PL/SQL.
$s = oci_parse($c, 'declare x sys_refcursor; begin :o := x; end;');
$o = oci_new_cursor($c);
oci_bind_by_name($s, ':o', $o, -1, OCI_B_CURSOR);
oci_execute($s);
line('C11', @oci_execute($o) ? drain($o) : 'Err(' . oci_error($o)['message'] . ')');

// R1: REF CURSOR as a function return value.
$s = oci_parse($c, 'begin :o := fx_cursor.items(1000); end;');
$o = oci_new_cursor($c);
oci_bind_by_name($s, ':o', $o, -1, OCI_B_CURSOR);
oci_execute($s); oci_execute($o);
line('R1', drain($o));

// K1: nested CURSOR() column.
$s = oci_parse($c, 'select c.id, c.name, cursor(select i.id, i.name from fx_items i where i.category_id = c.id and i.id <= 20 order by i.id) as items from fx_categories c order by c.id');
oci_execute($s);
$out = [];
while (($r = oci_fetch_assoc($s)) !== false) {
    oci_execute($r['ITEMS']);
    $ids = [];
    while (($i = oci_fetch_assoc($r['ITEMS'])) !== false) { $ids[] = (int) $i['ID']; }
    $out[] = $r['ID'] . ':' . $r['NAME'] . json_encode($ids);
}
line('K1', json_encode($out, JSON_UNESCAPED_UNICODE));

// I1: Implicit results, read natively with oci_get_implicit_resultset.
$s = oci_parse($c, 'begin fx_cursor.implicit_results(5); end;');
oci_execute($s);
$k = 0;
while (($r = oci_get_implicit_resultset($s)) !== false) { line('I1.' . ++$k, drain($r)); }
