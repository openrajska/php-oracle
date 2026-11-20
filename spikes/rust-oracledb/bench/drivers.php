<?php
// The three acceptance workloads (#6) over oci8 and over rajska_oracle, with the
// same SQL and the same PHP values out. Throwaway code for the Benchmark ticket (#13).
//
//   (a) scalar:  one Call with 3 IN and 3 OUT binds (NUMBER, VARCHAR2, DATE)
//   (b) fetch:   10,000 rows x 9 mixed columns from fx_bench_rows
//   (c) cursor:  one Call returning a REF CURSOR of 1,000 rows (fx_cursor.items)
//
// Shapes:
//   plain: oci8's values (NUMBER / DATE / TIMESTAMP / BINARY_DOUBLE as strings);
//   typed: int for integer NUMBER columns, float for BINARY_DOUBLE,
//          DateTimeImmutable for DATE / TIMESTAMP. oci8 gets there with a PHP
//          conversion per value (what Core would do over oci8); rajska_oracle
//          converts natively.
//
// Fetch modes: 'row' fetches one row per PHP call (oci_fetch_array / fetch()),
// 'all' fetches every row in one call (oci_fetch_all / fetchAll()).

const SQL_SCALAR = "begin fx_scalar.echo_out(:p_num, :p_str, to_date(:p_date, 'YYYY-MM-DD HH24:MI:SS'), :o_num, :o_str, :o_date); end;";
const SQL_FETCH = 'select id, qty, amount, ratio, code, label, note, created_at, updated_at from fx_bench_rows';
const SQL_CURSOR = 'begin :rc := fx_cursor.items(:n); end;';
const CURSOR_ROWS = 1000;
const FETCH_ROWS = 10000;

/** The IN values of workload (a); call $i varies them so nothing is constant-folded. */
function scalar_in(int $i): array
{
    return ['p_num' => 1000 + $i, 'p_str' => 'Ação café 日本 ' . $i, 'p_date' => '2026-03-14 15:09:26'];
}

interface BenchDriver
{
    public function scalar(int $i, bool $typed): array;

    /** @return int rows fetched */
    public function fetch(string $mode, bool $typed, int $arraysize, ?callable $sink = null): int;

    /** @return int rows fetched */
    public function cursor(string $mode, bool $typed, ?callable $sink = null): int;
}

function oci8_session_formats($c): void
{
    $s = oci_parse($c, "alter session set nls_date_format = 'YYYY-MM-DD HH24:MI:SS' nls_timestamp_format = 'YYYY-MM-DD HH24:MI:SS.FF6' nls_numeric_characters = '.,'");
    oci_execute($s);
}

final class Oci8Driver implements BenchDriver
{
    private $c;

    /**
     * Mode of the scalar Call's oci_execute. oci8's default commits on
     * success: free when no transaction is open (workloads a-c), but it would
     * commit a pending INSERT, so the ZTS scalar-txn run uses OCI_NO_AUTO_COMMIT.
     */
    public int $executeMode = OCI_COMMIT_ON_SUCCESS;

    public function __construct(string $dsn, string $user, string $password, bool $persistent = false)
    {
        $this->c = $persistent
            ? oci_pconnect($user, $password, $dsn, 'AL32UTF8')
            : oci_new_connect($user, $password, $dsn, 'AL32UTF8');
        if (!$this->c) {
            throw new RuntimeException('oci8 connect: ' . json_encode(oci_error()));
        }
        oci8_session_formats($this->c);
    }

    public function connection()
    {
        return $this->c;
    }

    public function scalar(int $i, bool $typed): array
    {
        $in = scalar_in($i);
        $s = oci_parse($this->c, SQL_SCALAR);
        oci_bind_by_name($s, ':p_num', $in['p_num']);
        oci_bind_by_name($s, ':p_str', $in['p_str']);
        oci_bind_by_name($s, ':p_date', $in['p_date']);
        oci_bind_by_name($s, ':o_num', $num, 64);
        oci_bind_by_name($s, ':o_str', $str, 4000);
        oci_bind_by_name($s, ':o_date', $date, 32);
        if (!oci_execute($s, $this->executeMode)) {
            throw new RuntimeException(json_encode(oci_error($s)));
        }
        if ($typed) {
            // fx_scalar.echo_out's NUMBER is unconstrained: an exact string, as rajska_oracle does.
            $date = $date === null ? null : new DateTimeImmutable($date);
        }
        return ['o_num' => $num, 'o_str' => $str, 'o_date' => $date];
    }

    public function fetch(string $mode, bool $typed, int $arraysize, ?callable $sink = null): int
    {
        $s = oci_parse($this->c, SQL_FETCH);
        if ($arraysize > 0) {
            oci_set_prefetch($s, $arraysize);
        }
        oci_execute($s);
        return $this->drain($s, $mode, $typed, $sink);
    }

    public function cursor(string $mode, bool $typed, ?callable $sink = null): int
    {
        $s = oci_parse($this->c, SQL_CURSOR);
        $rc = oci_new_cursor($this->c);
        $n = CURSOR_ROWS;
        oci_bind_by_name($s, ':rc', $rc, -1, OCI_B_CURSOR);
        oci_bind_by_name($s, ':n', $n);
        oci_execute($s);
        oci_execute($rc);
        $rows = $this->drain($rc, $mode, $typed, $sink);
        oci_free_statement($rc);
        return $rows;
    }

    private function drain($s, string $mode, bool $typed, ?callable $sink): int
    {
        $conv = $typed ? self::converters($s) : null;
        $n = 0;
        if ($mode === 'all') {
            oci_fetch_all($s, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
            if ($conv) {
                foreach ($rows as &$row) {
                    foreach ($conv as $col => $f) {
                        if ($row[$col] !== null) {
                            $row[$col] = $f($row[$col]);
                        }
                    }
                }
                unset($row);
            }
            $n = count($rows);
            if ($sink) {
                $sink($rows);
            }
            return $n;
        }
        while (($row = oci_fetch_array($s, OCI_ASSOC + OCI_RETURN_NULLS)) !== false) {
            if ($conv) {
                foreach ($conv as $col => $f) {
                    if ($row[$col] !== null) {
                        $row[$col] = $f($row[$col]);
                    }
                }
            }
            $n++;
            if ($sink) {
                $sink([$row]);
            }
        }
        return $n;
    }

    /** Per-column converters for the typed shape, decided once per statement. */
    private static function converters($s): array
    {
        $conv = [];
        $int = static fn (string $v): int => (int) $v;
        $float = static fn (string $v): float => (float) $v;
        $dt = static fn (string $v): DateTimeImmutable => new DateTimeImmutable($v);
        for ($i = 1, $n = oci_num_fields($s); $i <= $n; $i++) {
            $name = oci_field_name($s, $i);
            $type = oci_field_type($s, $i);
            if ($type === 'NUMBER' && oci_field_scale($s, $i) === 0 && oci_field_precision($s, $i) >= 1 && oci_field_precision($s, $i) <= 18) {
                $conv[$name] = $int;
            } elseif ($type === 101 || $type === 100) { // SQLT_IBDOUBLE / SQLT_IBFLOAT: oci8 has no name for them
                $conv[$name] = $float;
            } elseif ($type === 'DATE' || str_starts_with($type, 'TIMESTAMP')) {
                $conv[$name] = $dt;
            }
        }
        return $conv;
    }
}

final class RajskaDriver implements BenchDriver
{
    private const SCALAR_OUT = ['o_num' => 'number', 'o_str' => 'varchar', 'o_date' => 'date'];

    public function __construct(private Rajska\Bench\Connection $c)
    {
    }

    public static function connect(string $dsn, string $user, string $password): self
    {
        return new self(new Rajska\Bench\Connection($dsn, $user, $password));
    }

    public function connection(): Rajska\Bench\Connection
    {
        return $this->c;
    }

    public function scalar(int $i, bool $typed): array
    {
        return $this->c->call(SQL_SCALAR, scalar_in($i), self::SCALAR_OUT, $typed);
    }

    public function fetch(string $mode, bool $typed, int $arraysize, ?callable $sink = null): int
    {
        return $this->drain($this->c->query(SQL_FETCH, [], $typed, $arraysize), $mode, $sink);
    }

    public function cursor(string $mode, bool $typed, ?callable $sink = null): int
    {
        return $this->drain($this->c->cursor(SQL_CURSOR, ['n' => CURSOR_ROWS], 'rc', $typed), $mode, $sink);
    }

    private function drain(Rajska\Bench\Statement $s, string $mode, ?callable $sink): int
    {
        if ($mode === 'all') {
            $rows = $s->fetchAll();
            if ($sink) {
                $sink($rows);
            }
            return count($rows);
        }
        $n = 0;
        while (($row = $s->fetch()) !== null) {
            $n++;
            if ($sink) {
                $sink([$row]);
            }
        }
        return $n;
    }
}

function make_driver(string $driver): BenchDriver
{
    $dsn = getenv('ORACLE_CONNECT_STRING') ?: 'oracle:1521/FREEPDB1';
    $user = getenv('ORACLE_USER') ?: 'rajska';
    $password = getenv('ORACLE_PASSWORD') ?: 'rajska';
    return match ($driver) {
        'oci8' => new Oci8Driver($dsn, $user, $password),
        'rajska' => RajskaDriver::connect($dsn, $user, $password),
    };
}
