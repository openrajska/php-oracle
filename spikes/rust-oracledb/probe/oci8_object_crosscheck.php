<?php
// Throwaway oci8 cross-check for the object types / Collections probe (#9).
// oci8 is only the baseline: it shows what PHP users get today for the same
// Calls. Case ids match src/bin/object.rs.
// Run: ddev exec bash -s <<< 'NLS_LANG=.AL32UTF8 php spikes/rust-oracledb/probe/oci8_object_crosscheck.php'
$c = oci_connect(getenv('ORACLE_USER'), getenv('ORACLE_PASSWORD'), getenv('ORACLE_CONNECT_STRING'), 'AL32UTF8');
oci_execute(oci_parse($c, "alter session set nls_numeric_characters = '.,'"));

function line(string $id, string $v): void { printf("%-5s %s\n", $id, $v); }

/** Runs $f, turning warnings and errors into a one-line result. */
function attempt(callable $f): string {
    set_error_handler(function ($no, $msg) { throw new ErrorException($msg); });
    try { return json_encode($f(), JSON_UNESCAPED_UNICODE); }
    catch (Throwable $e) { return 'Err(' . strtok($e->getMessage(), "\n") . ')'; }
    finally { restore_error_handler(); }
}

/** Elements of an OCICollection. */
function elements(OCICollection $coll): array {
    $v = [];
    for ($i = 0; $i < $coll->size(); $i++) { $v[] = $coll->getElem($i); }
    return $v;
}

// F1: object and Collection columns in a fetched row.
line('F1', attempt(function () use ($c) {
    $s = oci_parse($c, 'select center, tags, outline from fx_shapes where id = 1');
    oci_execute($s);
    return oci_fetch_array($s, OCI_ASSOC + OCI_RETURN_NULLS);
}));

// N1: an object returned by a function in SQL.
line('N1', attempt(function () use ($c) {
    $s = oci_parse($c, "select fx_object.make_point(1.5, -2, 'x') from dual");
    oci_execute($s);
    return oci_fetch_array($s, OCI_NUM);
}));

// T1 / T2: pipelined functions in SQL.
line('T1', attempt(function () use ($c) {
    $s = oci_parse($c, 'select count(*), sum(column_value) from table(fx_pipe.numbers(:n))');
    $n = 1000; oci_bind_by_name($s, ':n', $n); oci_execute($s);
    return oci_fetch_array($s, OCI_NUM);
}));
line('T2', attempt(function () use ($c) {
    $s = oci_parse($c, 'select count(*), sum(y), max(label) keep (dense_rank last order by x) from table(fx_pipe.points(:n))');
    $n = 1000; oci_bind_by_name($s, ':n', $n); oci_execute($s);
    return oci_fetch_array($s, OCI_NUM);
}));

// TABLE(:ids) with a Collection IN bind (oci_new_collection).
line('X1', attempt(function () use ($c) {
    $ids = oci_new_collection($c, 'FX_NUMBER_LIST');
    foreach ([1, 5, 999, 1000, 2000] as $i) { $ids->append($i); }
    $s = oci_parse($c, 'select id from fx_items where id in (select column_value from table(:ids)) order by id');
    oci_bind_by_name($s, ':ids', $ids, -1, OCI_B_NTY);
    oci_execute($s);
    oci_fetch_all($s, $rows);
    return $rows['ID'];
}));

// Nested table IN, then OUT.
line('X2', attempt(function () use ($c) {
    $in = oci_new_collection($c, 'FX_NUMBER_LIST');
    foreach ([1, 2, 3] as $i) { $in->append($i); }
    $out = oci_new_collection($c, 'FX_NUMBER_LIST');
    $s = oci_parse($c, 'begin fx_object.reverse_numbers(:p, :o); end;');
    oci_bind_by_name($s, ':p', $in, -1, OCI_B_NTY);
    oci_bind_by_name($s, ':o', $out, -1, OCI_B_NTY);
    oci_execute($s);
    return elements($out);
}));

// VARRAY IN OUT with a multibyte element.
line('X3', attempt(function () use ($c) {
    $tags = oci_new_collection($c, 'FX_STRING_VARRAY');
    $tags->append('red'); $tags->append('café');
    $s = oci_parse($c, "begin fx_object.append_tag(:t, 'x'); end;");
    oci_bind_by_name($s, ':t', $tags, -1, OCI_B_NTY);
    oci_execute($s);
    return elements($tags);
}));

// Collection of objects (oci8 Collections hold scalars only).
line('X4', attempt(function () use ($c) {
    return oci_new_collection($c, 'FX_POINT_LIST') !== false;
}));

// Associative array IN (oci_bind_array_by_name, PL/SQL index-by of scalars).
line('X5', attempt(function () use ($c) {
    $s = oci_parse($c, 'begin :o := fx_object.sum_map(:m); end;');
    $m = [1, 2, 3.5]; $o = null;
    oci_bind_array_by_name($s, ':m', $m, 3, -1, SQLT_NUM);
    oci_bind_by_name($s, ':o', $o, 40);
    oci_execute($s);
    return $o;
}));
