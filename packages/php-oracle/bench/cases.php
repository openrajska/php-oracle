<?php

declare(strict_types=1);

// The benchmark's cases: each one is a workload run either through Core's
// public API or through oci8, and the comparisons the summary reads.
//
// A case's `make` receives the BenchContext and returns the iteration: a
// closure (int $i): array|int giving the values it read (an array, compared
// across drivers by crosscheck.php) or a row count. `after`, when present,
// runs untimed after every iteration (cleanup).
//
// oci8 never sees Core's classes and Core never calls oci8: each case runs
// in a process that loads only its own extension (see run.php).
use Rajska\Oracle\Bench\Oci8Values;
use Rajska\Oracle\Bind\Cursor as CursorBind;
use Rajska\Oracle\Bind\Date;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Connection;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Cursor;
use Rajska\Oracle\ExecuteOptions;
use Rajska\Oracle\Execution;
use Rajska\Oracle\LobMode;
use Rajska\Oracle\NumberMode;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\TemporalMode;
use Rajska\Oracle\ValueMapping;

const SQL_SCALAR = "begin fx_scalar.echo_out(:p_num, :p_str, to_date(:p_date, 'YYYY-MM-DD HH24:MI:SS'), :o_num, :o_str, :o_date); end;";
const SQL_FETCH = 'select id, qty, amount, ratio, code, label, note, created_at, updated_at from fx_bench_rows';
const SQL_NUMBERS = 'select id, qty, amount, ratio from fx_bench_rows';
const SQL_DATES = 'select created_at, updated_at from fx_bench_rows';
const SQL_CURSOR = 'begin :rc := fx_cursor.items(:n); end;';
const SQL_LOBS = 'select id, body, data from fx_bench_lobs order by id';
const SQL_LARGE_LOB = 'select binary_value from fx_lobs where id = 4';
const SQL_INSERT = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, :qty)';
const SQL_FORALL = 'begin forall i in indices of :ids insert into fx_batch_rows (id, code, qty) values (:ids(i), :codes(i), :qtys(i)); end;';
const SQL_CLEANUP = 'delete from fx_batch_rows where id between 700001 and 701000';
const SQL_DUAL = 'select 1 as one from dual';
const SCALAR_DATE = '2026-03-14 15:09:26';
const FETCH_ROWS = 10_000;
const CURSOR_ROWS = 1_000;
const LOB_ROWS = 1_000;
const INSERT_ROWS = 1_000;
const LARGE_LOB_BYTES = 16 * 1024 * 1024;

/**
 * oci8 gets the session formats that make its DATE and TIMESTAMP text equal
 * to Core's `TemporalMode::String` text, once per Connection.
 */
const OCI8_FORMATS = "alter session set nls_date_format = 'YYYY-MM-DD HH24:MI:SS' nls_timestamp_format = 'YYYY-MM-DD HH24:MI:SS.FF9' nls_numeric_characters = '.,'";

final class BenchContext
{
    private ?Connection $core = null;

    /** @var resource|null */
    private $oci8;

    public function __construct(
        public readonly string $dsn,
        public readonly string $user,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            getenv('ORACLE_CONNECT_STRING') ?: 'oracle:1521/FREEPDB1',
            getenv('ORACLE_USER') ?: 'rajska',
            getenv('ORACLE_PASSWORD') ?: 'rajska',
        );
    }

    public function config(mixed ...$fields): ConnectionConfig
    {
        return new ConnectionConfig(...['dsn' => $this->dsn, 'user' => $this->user, 'password' => $this->password, ...$fields]);
    }

    /** One Core Connection for the whole process, from the Pool. */
    public function core(): Connection
    {
        return $this->core ??= Oracle::connect($this->config());
    }

    /** One oci8 Connection for the whole process, with the session formats. */
    public function oci8()
    {
        if ($this->oci8 === null) {
            $this->oci8 = oci_new_connect($this->user, $this->password, $this->dsn, 'AL32UTF8')
                ?: throw new RuntimeException('oci8 connect: ' . json_encode(oci_error()));
            oci8_formats($this->oci8);
        }

        return $this->oci8;
    }
}

function oci8_formats($connection): void
{
    $statement = oci_parse($connection, OCI8_FORMATS);
    oci_execute($statement);
}

/** The IN values of the scalar Call; call $i varies them so nothing is constant-folded. */
function scalar_in(int $i): array
{
    return ['p_num' => 1000 + $i, 'p_str' => 'Ação café 日本 ' . $i];
}

/** @return list<array{id: int, code: string, qty: int}> */
function insert_rows(): array
{
    $rows = [];
    for ($n = 1; $n <= INSERT_ROWS; $n++) {
        $rows[] = ['id' => 700_000 + $n, 'code' => 'r' . (700_000 + $n), 'qty' => $n];
    }

    return $rows;
}

function fast(): ValueMapping
{
    return new ValueMapping(numbers: NumberMode::Float, temporals: TemporalMode::String);
}

/** Every row of a Core Cursor, one foreach step at a time; the row count. */
function core_each(Cursor $cursor): int
{
    $n = 0;
    foreach ($cursor as $row) {
        $n++;
    }

    return $n;
}

/**
 * oci8's per-column conversions to Core's values, decided once per
 * statement from its columns: `exact` gives `NumberMode::Exact` and
 * `TemporalMode::Object`, `float` gives `NumberMode::Float` and
 * `TemporalMode::String` (oci8's text already is that string).
 *
 * @return array<string, Closure(string): mixed>
 */
function oci8_converters($statement, string $mapping): array
{
    $converters = [];
    $int = static fn(string $v): int => (int) $v;
    $float = static fn(string $v): float => (float) $v;
    $number = $mapping === 'exact' ? Oci8Values::exact(...) : Oci8Values::float(...);
    $date = Oci8Values::dateTime(...);
    for ($i = 1, $n = oci_num_fields($statement); $i <= $n; $i++) {
        $name = oci_field_name($statement, $i);
        $type = oci_field_type($statement, $i);
        if ($type === 'NUMBER') {
            $precision = oci_field_precision($statement, $i);
            $converters[$name] = oci_field_scale($statement, $i) === 0 && $precision >= 1 && $precision <= 18 ? $int : $number;
        } elseif ($type === 101 || $type === 100) { // SQLT_IBDOUBLE / SQLT_IBFLOAT: oci8 has no name for them
            $converters[$name] = $float;
        } elseif ($mapping === 'exact' && ($type === 'DATE' || str_starts_with((string) $type, 'TIMESTAMP'))) {
            $converters[$name] = $date;
        }
    }

    return $converters;
}

/**
 * Every row of an executed oci8 statement: 'all' is one oci_fetch_all,
 * 'row' one oci_fetch_array per row. $mapping is 'raw' (oci8's strings),
 * 'exact' or 'float' (see oci8_converters()). 'all' returns the rows, 'row'
 * their count.
 */
function oci8_drain($statement, string $mode, string $mapping, int $flags = OCI_ASSOC): array|int
{
    $converters = $mapping === 'raw' ? [] : oci8_converters($statement, $mapping);
    if ($mode === 'all') {
        oci_fetch_all($statement, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + $flags);
        if ($converters !== []) {
            foreach ($rows as &$row) {
                foreach ($converters as $column => $convert) {
                    if ($row[$column] !== null) {
                        $row[$column] = $convert($row[$column]);
                    }
                }
            }
            unset($row);
        }

        return $rows;
    }
    $n = 0;
    while (($row = oci_fetch_array($statement, $flags + OCI_RETURN_NULLS)) !== false) {
        foreach ($converters as $column => $convert) {
            if ($row[$column] !== null) {
                $row[$column] = $convert($row[$column]);
            }
        }
        $n++;
    }

    return $n;
}

function oci8_query($connection, string $sql, int $prefetch = 0)
{
    $statement = oci_parse($connection, $sql);
    if ($prefetch > 0) {
        oci_set_prefetch($statement, $prefetch);
    }
    oci_execute($statement);

    return $statement;
}

function oci8_scalar($connection, int $i, bool $typed, int $mode = OCI_COMMIT_ON_SUCCESS): array
{
    $in = scalar_in($i);
    $date = SCALAR_DATE;
    $statement = oci_parse($connection, SQL_SCALAR);
    oci_bind_by_name($statement, ':p_num', $in['p_num']);
    oci_bind_by_name($statement, ':p_str', $in['p_str']);
    oci_bind_by_name($statement, ':p_date', $date);
    oci_bind_by_name($statement, ':o_num', $number, 64);
    oci_bind_by_name($statement, ':o_str', $string, 4000);
    oci_bind_by_name($statement, ':o_date', $out, 32);
    if (!oci_execute($statement, $mode)) {
        throw new RuntimeException(json_encode(oci_error($statement)));
    }
    if ($typed) {
        $number = $number === null ? null : Oci8Values::exact($number);
        $out = $out === null ? null : Oci8Values::dateTime($out);
    }

    return ['o_num' => $number, 'o_str' => $string, 'o_date' => $out];
}

function oci8_cursor($connection, string $mode, string $mapping): array|int
{
    $statement = oci_parse($connection, SQL_CURSOR);
    $cursor = oci_new_cursor($connection);
    $n = CURSOR_ROWS;
    oci_bind_by_name($statement, ':rc', $cursor, -1, OCI_B_CURSOR);
    oci_bind_by_name($statement, ':n', $n);
    oci_execute($statement);
    oci_execute($cursor);
    $rows = oci8_drain($cursor, $mode, $mapping);
    oci_free_statement($cursor);

    return $rows;
}

/** @return list<array{ID: int, BODY: string, DATA: string}> */
function oci8_lobs($connection, bool $inline): array
{
    $statement = oci8_query($connection, SQL_LOBS);
    $rows = [];
    $flags = OCI_ASSOC + OCI_RETURN_NULLS + ($inline ? OCI_RETURN_LOBS : 0);
    while (($row = oci_fetch_array($statement, $flags)) !== false) {
        if (!$inline) {
            $body = $row['BODY'];
            $data = $row['DATA'];
            $row['BODY'] = $body->load();
            $row['DATA'] = $data->load();
            $body->free();
            $data->free();
        }
        $row['ID'] = (int) $row['ID'];
        $rows[] = $row;
    }

    return $rows;
}

/** @return list<array{ID: int, BODY: string, DATA: string}> */
function core_lobs(Connection $connection, bool $inline): array
{
    if ($inline) {
        return $connection->select(SQL_LOBS, options: new ExecuteOptions(values: new ValueMapping(lobs: LobMode::Inline)))->all();
    }
    $rows = [];
    foreach ($connection->select(SQL_LOBS) as $row) {
        $row['BODY'] = $row['BODY']->contents();
        $row['DATA'] = $row['DATA']->contents();
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @return array<string, array{scenario: string, driver: 'core'|'oci8', warmup: int, iters: int, expect: int, make: Closure(BenchContext): Closure, after?: Closure(BenchContext): void}>
 */
function bench_cases(): array
{
    $cases = [];
    $add = static function (string $id, string $driver, int $warmup, int $iters, int $expect, Closure $make, ?Closure $after = null) use (&$cases): void {
        $cases[$id] = ['scenario' => explode('.', $id)[0], 'driver' => $driver, 'warmup' => $warmup, 'iters' => $iters, 'expect' => $expect, 'make' => $make] + ($after instanceof \Closure ? ['after' => $after] : []);
    };

    // (a) A Call with 3 scalar IN and 3 OUT binds: fx_scalar.echo_out.
    $add('call.core.procedure', 'core', 1000, 10_000, 3, static function (BenchContext $x): Closure {
        $c = $x->core();
        $date = Date::in(new DateTimeImmutable(SCALAR_DATE));

        return static function (int $i) use ($c, $date): array {
            $e = $c->procedure('fx_scalar.echo_out', [
                ...scalar_in($i),
                'p_date' => $date,
                'o_num' => Number::out(),
                'o_str' => Varchar::out(),
                'o_date' => Date::out(),
            ]);

            return ['o_num' => $e->out('o_num')->int(), 'o_str' => $e->out('o_str')->string(), 'o_date' => $e->out('o_date')->dateTime()];
        };
    });
    $add('call.core.run', 'core', 1000, 10_000, 3, static function (BenchContext $x): Closure {
        $c = $x->core();

        return static function (int $i) use ($c): array {
            $e = $c->run(SQL_SCALAR, [
                ...scalar_in($i),
                'p_date' => SCALAR_DATE,
                'o_num' => Number::out(),
                'o_str' => Varchar::out(),
                'o_date' => Date::out(),
            ]);

            return ['o_num' => $e->out('o_num')->int(), 'o_str' => $e->out('o_str')->string(), 'o_date' => $e->out('o_date')->dateTime()];
        };
    });
    $add('call.oci8.typed', 'oci8', 1000, 10_000, 3, static fn(BenchContext $x): Closure => static fn(int $i): array => oci8_scalar($x->oci8(), $i, true));
    $add('call.oci8.raw', 'oci8', 1000, 10_000, 3, static fn(BenchContext $x): Closure => static fn(int $i): array => oci8_scalar($x->oci8(), $i, false));

    // (b) A query of 10k rows x 9 mixed columns: fx_bench_rows.
    foreach (['all', 'row'] as $mode) {
        $add("fetch.core.$mode", 'core', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => $mode === 'all'
            ? static fn(): array => $x->core()->select(SQL_FETCH)->all()
            : static fn(): int => core_each($x->core()->select(SQL_FETCH)));
        $add("fetch.core.fast-$mode", 'core', 5, 50, FETCH_ROWS, static function (BenchContext $x) use ($mode): Closure {
            $options = new ExecuteOptions(values: fast());

            return $mode === 'all'
                ? static fn(): array => $x->core()->select(SQL_FETCH, options: $options)->all()
                : static fn(): int => core_each($x->core()->select(SQL_FETCH, options: $options));
        });
        foreach ($mode === 'all' ? ['raw', 'exact', 'float'] : ['raw', 'exact'] as $mapping) {
            $add("fetch.oci8.$mapping-$mode", 'oci8', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array|int => oci8_drain(oci8_query($x->oci8(), SQL_FETCH), $mode, $mapping));
        }
    }
    $add('fetch.core.list-all', 'core', 5, 50, FETCH_ROWS, static function (BenchContext $x): Closure {
        $options = new ExecuteOptions(rowShape: RowShape::List);

        return static fn(): array => $x->core()->select(SQL_FETCH, options: $options)->all();
    });
    $add('fetch.oci8.raw-num-all', 'oci8', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_drain(oci8_query($x->oci8(), SQL_FETCH), 'all', 'raw', OCI_NUM));
    $add('fetch.core.size1000-all', 'core', 5, 50, FETCH_ROWS, static function (BenchContext $x): Closure {
        $options = new ExecuteOptions(fetchSize: 1000);

        return static fn(): array => $x->core()->select(SQL_FETCH, options: $options)->all();
    });
    $add('fetch.oci8.exact-prefetch1000-all', 'oci8', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_drain(oci8_query($x->oci8(), SQL_FETCH, 1000), 'all', 'exact'));

    // Numbers only (10k x 4: two NUMBER(10), a NUMBER(12,2), a BINARY_DOUBLE).
    $add('numbers.core.exact', 'core', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => $x->core()->select(SQL_NUMBERS)->all());
    $add('numbers.core.float', 'core', 5, 50, FETCH_ROWS, static function (BenchContext $x): Closure {
        $options = new ExecuteOptions(values: new ValueMapping(numbers: NumberMode::Float));

        return static fn(): array => $x->core()->select(SQL_NUMBERS, options: $options)->all();
    });
    foreach (['raw', 'exact', 'float'] as $mapping) {
        $add("numbers.oci8.$mapping", 'oci8', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_drain(oci8_query($x->oci8(), SQL_NUMBERS), 'all', $mapping));
    }

    // Dates only (10k x 2: a DATE, a TIMESTAMP(6)).
    $add('dates.core.object', 'core', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => $x->core()->select(SQL_DATES)->all());
    $add('dates.core.string', 'core', 5, 50, FETCH_ROWS, static function (BenchContext $x): Closure {
        $options = new ExecuteOptions(values: new ValueMapping(temporals: TemporalMode::String));

        return static fn(): array => $x->core()->select(SQL_DATES, options: $options)->all();
    });
    $add('dates.oci8.raw', 'oci8', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_drain(oci8_query($x->oci8(), SQL_DATES), 'all', 'raw'));
    $add('dates.oci8.exact', 'oci8', 5, 50, FETCH_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_drain(oci8_query($x->oci8(), SQL_DATES), 'all', 'exact'));

    // (c) A Call returning a REF CURSOR of 1k rows x 5 columns: fx_cursor.items.
    $add('cursor.core.function-all', 'core', 50, 300, CURSOR_ROWS, static fn(BenchContext $x): Closure => static fn(): array => $x->core()->function('fx_cursor.items', ['p_limit' => CURSOR_ROWS])->cursor()->all());
    $add('cursor.core.function-row', 'core', 50, 300, CURSOR_ROWS, static fn(BenchContext $x): Closure => static fn(): int => core_each($x->core()->function('fx_cursor.items', ['p_limit' => CURSOR_ROWS])->cursor()));
    $add('cursor.core.run-all', 'core', 50, 300, CURSOR_ROWS, static fn(BenchContext $x): Closure => static fn(): array => $x->core()->run(SQL_CURSOR, ['rc' => CursorBind::out(), 'n' => CURSOR_ROWS])->out('rc')->cursor()->all());
    $add('cursor.core.fast-all', 'core', 50, 300, CURSOR_ROWS, static function (BenchContext $x): Closure {
        $options = new ExecuteOptions(values: fast());

        return static fn(): array => $x->core()->function('fx_cursor.items', ['p_limit' => CURSOR_ROWS], $options)->cursor()->all();
    });
    foreach (['raw-all', 'exact-all', 'float-all', 'raw-row', 'exact-row'] as $variant) {
        [$mapping, $mode] = explode('-', $variant);
        $add("cursor.oci8.$variant", 'oci8', 50, 300, CURSOR_ROWS, static fn(BenchContext $x): Closure => static fn(): array|int => oci8_cursor($x->oci8(), $mode, $mapping));
    }

    // LOBs: 1k rows with a 1,000-character CLOB and a 1,000-byte BLOB each, read whole.
    $add('lobs.core.inline', 'core', 5, 50, LOB_ROWS, static fn(BenchContext $x): Closure => static fn(): array => core_lobs($x->core(), true));
    $add('lobs.core.locator', 'core', 5, 50, LOB_ROWS, static fn(BenchContext $x): Closure => static fn(): array => core_lobs($x->core(), false));
    $add('lobs.oci8.inline', 'oci8', 5, 50, LOB_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_lobs($x->oci8(), true));
    $add('lobs.oci8.locator', 'oci8', 5, 50, LOB_ROWS, static fn(BenchContext $x): Closure => static fn(): array => oci8_lobs($x->oci8(), false));
    // One 16 MiB BLOB, read whole.
    $add('largelob.core.contents', 'core', 3, 30, 1, static fn(BenchContext $x): Closure => static fn(): array => [$x->core()->select(SQL_LARGE_LOB)->value()->contents()]);
    $add('largelob.oci8.load', 'oci8', 3, 30, 1, static fn(BenchContext $x): Closure => static function () use ($x): array {
        $row = oci_fetch_array(oci8_query($x->oci8(), SQL_LARGE_LOB), OCI_NUM);
        $bytes = $row[0]->load();
        $row[0]->free();

        return [$bytes];
    });

    // Array DML: 1k rows into fx_batch_rows, committed; deleted again untimed.
    $add('insert.core.runmany', 'core', 5, 50, INSERT_ROWS, static function (BenchContext $x): Closure {
        $rows = insert_rows();

        return static fn(): int => $x->core()->runMany(SQL_INSERT, $rows)->affected();
    }, static fn(BenchContext $x): Execution => $x->core()->run(SQL_CLEANUP));
    $add('insert.oci8.loop', 'oci8', 5, 50, INSERT_ROWS, static function (BenchContext $x): Closure {
        $rows = insert_rows();

        return static function () use ($x, $rows): int {
            $statement = oci_parse($x->oci8(), SQL_INSERT);
            ['id' => $id, 'code' => $code, 'qty' => $qty] = $rows[0];
            oci_bind_by_name($statement, ':id', $id, -1, SQLT_INT);
            oci_bind_by_name($statement, ':code', $code, 10);
            oci_bind_by_name($statement, ':qty', $qty, -1, SQLT_INT);
            foreach ($rows as ['id' => $id, 'code' => $code, 'qty' => $qty]) {
                oci_execute($statement, OCI_NO_AUTO_COMMIT) ?: throw new RuntimeException(json_encode(oci_error($statement)));
            }
            oci_commit($x->oci8());

            return \count($rows);
        };
    }, static fn(BenchContext $x): bool => oci_execute(oci_parse($x->oci8(), SQL_CLEANUP)));
    $add('insert.oci8.forall', 'oci8', 5, 50, INSERT_ROWS, static function (BenchContext $x): Closure {
        $rows = insert_rows();

        return static function () use ($x, $rows): int {
            $ids = array_column($rows, 'id');
            $codes = array_column($rows, 'code');
            $quantities = array_column($rows, 'qty');
            $statement = oci_parse($x->oci8(), SQL_FORALL);
            oci_bind_array_by_name($statement, ':ids', $ids, \count($ids), -1, SQLT_INT);
            oci_bind_array_by_name($statement, ':codes', $codes, \count($codes), 10, SQLT_CHR);
            oci_bind_array_by_name($statement, ':qtys', $quantities, \count($quantities), -1, SQLT_INT);
            oci_execute($statement) ?: throw new RuntimeException(json_encode(oci_error($statement)));

            return \count($rows);
        };
    }, static fn(BenchContext $x): bool => oci_execute(oci_parse($x->oci8(), SQL_CLEANUP)));

    // Connecting: a Session from the Pool and back, against oci_pconnect.
    $add('connect.core.pool', 'core', 200, 5000, 1, static function (BenchContext $x): Closure {
        $config = $x->config();

        return static function () use ($config): int {
            Oracle::connect($config)->close();

            return 1;
        };
    });
    $add('connect.oci8.pconnect', 'oci8', 200, 5000, 1, static fn(BenchContext $x): Closure => static function () use ($x): int {
        $connection = oci_pconnect($x->user, $x->password, $x->dsn, 'AL32UTF8');
        oci_close($connection);

        return 1;
    });
    // A request's worth: connect, one single-row query, Release.
    $add('request.core.pool', 'core', 200, 5000, 1, static function (BenchContext $x): Closure {
        $config = $x->config();

        return static function () use ($config): int {
            $connection = Oracle::connect($config);
            $one = $connection->select(SQL_DUAL)->value();
            $connection->close();

            return $one;
        };
    });
    $add('request.oci8.pconnect', 'oci8', 200, 5000, 1, static fn(BenchContext $x): Closure => static function () use ($x): int {
        $connection = oci_pconnect($x->user, $x->password, $x->dsn, 'AL32UTF8');
        $row = oci_fetch_array(oci8_query($connection, SQL_DUAL), OCI_NUM);
        oci_close($connection);

        return (int) $row[0];
    });
    // A new Session every time: logon and logoff. 20 ms apart, untimed: the
    // listener refuses logons that come faster for long (ORA-12516).
    $pause = static fn(): null => usleep(20_000);
    $add('logon.core.standalone', 'core', 3, 30, 1, static function (BenchContext $x): Closure {
        $config = $x->config(standalone: true);

        return static function () use ($config): int {
            Oracle::connect($config)->close();

            return 1;
        };
    }, $pause);
    $add('logon.oci8.new', 'oci8', 3, 30, 1, static fn(BenchContext $x): Closure => static function () use ($x): int {
        oci_close(oci_new_connect($x->user, $x->password, $x->dsn, 'AL32UTF8'));

        return 1;
    }, $pause);

    return $cases;
}

/**
 * What the summary reads: Core case against oci8 case. `same` means both
 * return identical PHP values (crosscheck.php proves it before timing).
 * `spike` is the matching ratio of the rust-oracledb spike (FINDINGS.md).
 *
 * @return list<array{label: string, core: string, oci8: string, same: bool, spike: ?string}>
 */
function bench_comparisons(): array
{
    $c = static fn(string $label, string $core, string $oci8, bool $same, ?string $spike = null): array => ['label' => $label, 'core' => $core, 'oci8' => $oci8, 'same' => $same, 'spike' => $spike];

    return [
        $c('Call 3 IN + 3 OUT, `procedure()`', 'call.core.procedure', 'call.oci8.typed', true, '1.28× (typed)'),
        $c('Call 3 IN + 3 OUT, `run()` block', 'call.core.run', 'call.oci8.typed', true, '1.28× (typed)'),
        $c('Call, `procedure()` vs oci8 strings', 'call.core.procedure', 'call.oci8.raw', false, '1.28× (plain)'),
        $c('Fetch 10k, `all()`', 'fetch.core.all', 'fetch.oci8.exact-all', true, '1.73× (typed all)'),
        $c('Fetch 10k, `foreach`', 'fetch.core.row', 'fetch.oci8.exact-row', true, '1.75× (typed row)'),
        $c('Fetch 10k, `all()` vs oci8 strings', 'fetch.core.all', 'fetch.oci8.raw-all', false),
        $c('Fetch 10k, fast opt-ins, `all()`', 'fetch.core.fast-all', 'fetch.oci8.float-all', true),
        $c('Fetch 10k, fast opt-ins vs oci8 strings, `all()`', 'fetch.core.fast-all', 'fetch.oci8.raw-all', false, '1.69× (plain all)'),
        $c('Fetch 10k, fast opt-ins vs oci8 strings, `foreach`', 'fetch.core.fast-row', 'fetch.oci8.raw-row', false, '1.73× (plain row)'),
        $c('Fetch 10k, `RowShape::List` vs oci8 `OCI_NUM` strings', 'fetch.core.list-all', 'fetch.oci8.raw-num-all', false),
        $c('Fetch 10k, fetchSize / prefetch 1000', 'fetch.core.size1000-all', 'fetch.oci8.exact-prefetch1000-all', true),
        $c('Numbers 10k × 4, `Exact`', 'numbers.core.exact', 'numbers.oci8.exact', true),
        $c('Numbers 10k × 4, `Float`', 'numbers.core.float', 'numbers.oci8.float', true),
        $c('Numbers 10k × 4, `Float` vs oci8 strings', 'numbers.core.float', 'numbers.oci8.raw', false),
        $c('Dates 10k × 2, `Object`', 'dates.core.object', 'dates.oci8.exact', true),
        $c('Dates 10k × 2, `String`', 'dates.core.string', 'dates.oci8.raw', true),
        $c('REF CURSOR 1k, `function()->cursor()->all()`', 'cursor.core.function-all', 'cursor.oci8.exact-all', true, '1.21× (typed all)'),
        $c('REF CURSOR 1k, `foreach`', 'cursor.core.function-row', 'cursor.oci8.exact-row', true, '1.27× (typed row)'),
        $c('REF CURSOR 1k, `run()` block', 'cursor.core.run-all', 'cursor.oci8.exact-all', true),
        $c('REF CURSOR 1k, fast opt-ins', 'cursor.core.fast-all', 'cursor.oci8.float-all', true),
        $c('REF CURSOR 1k, fast opt-ins vs oci8 strings', 'cursor.core.fast-all', 'cursor.oci8.raw-all', false, '1.22× (plain all)'),
        $c('LOBs 1k × (CLOB + BLOB), `Inline` vs `OCI_RETURN_LOBS`', 'lobs.core.inline', 'lobs.oci8.inline', true),
        $c('LOBs 1k × (CLOB + BLOB), `Lob::contents()` vs `load()`', 'lobs.core.locator', 'lobs.oci8.locator', true),
        $c('One 16 MiB BLOB, `contents()` vs `load()`', 'largelob.core.contents', 'largelob.oci8.load', true),
        $c('Insert 1k rows, `runMany()` vs an oci8 loop', 'insert.core.runmany', 'insert.oci8.loop', true),
        $c('Insert 1k rows, `runMany()` vs oci8 FORALL', 'insert.core.runmany', 'insert.oci8.forall', true),
        $c('Connect + close, Pool vs `oci_pconnect`', 'connect.core.pool', 'connect.oci8.pconnect', true),
        $c('Connect + 1-row query + close, Pool vs `oci_pconnect`', 'request.core.pool', 'request.oci8.pconnect', true),
        $c('Logon + logoff, `standalone` vs `oci_new_connect`', 'logon.core.standalone', 'logon.oci8.new', true),
    ];
}
