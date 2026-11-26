<?php

declare(strict_types = 1);

use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Statement\KeyCase;
use Rajska\Oracle\Statement\LobMode;
use Rajska\Oracle\Statement\Options;
use Rajska\Oracle\Statement\RowShape;
use Rajska\Oracle\Statement\ValueMapping;

// Where Core's time goes: the same work timed at three depths, plus the
// Round trips each costs.
//   native: the Extension's classes alone (Rust + the zval conversion);
//   driver: Core's Extension adapter (ExtensionManager / ExtensionSession);
//   core:   Core's public API, as the benchmark cases call it.
// Round trips are read from V$SESSTAT by an oci8 observer Session (needs the
// grants of fixtures/sql/grants.sql), so oci8 must be loaded too:
//   php -d extension=oci8 -d extension=<oracledb> layers.php [rounds=5]
// Medians over rounds × iterations in one process; read the proportions.
require __DIR__ . '/bootstrap.php';

use Rajska\Oracle\Bench\Stats;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Driver\ExtensionManager;
use Rajska\Oracle\Driver\ExtensionSession;
use Rajska\Oracle\Driver\Options as DriverOptions;
use Rajska\Oracle\Extension;
use Rajska\Oracle\Oracle;

parse_str(implode('&', array_slice($argv, 1)), $options);
$rounds = (int) ($options['rounds'] ?? 5);

$x = BenchContext::fromEnvironment();
$config = $x->config();
$pool = [
    'dsn' => $x->dsn, 'user' => $x->user, 'password' => $x->password, 'proxyUser' => null, 'walletLocation' => null,
    'walletPassword' => null, 'configDir' => null, 'connectTimeout' => 20_000, 'drcpConnectionClass' => null,
    'maxConnections' => 16, 'acquireTimeout' => 5_000, 'statementCacheSize' => 20, 'standalone' => false,
];
$session = ['tracing' => ['module' => null, 'action' => null, 'clientIdentifier' => null, 'clientInfo' => null], 'callTimeout' => null];
$nativeOptions = static fn (array $values = []): array => [
    'autocommit' => true, 'timeout' => null, 'fetchSize' => 100, 'numbers' => 'exact', 'temporals' => 'object',
    'lobs' => 'locator', 'rowShape' => 'assoc', 'keyCase' => 'native', ...$values,
];
$driverOptions = new DriverOptions(true, null, 100, new ValueMapping(), RowShape::Assoc, KeyCase::Native);

$native = Extension\Connection::connect($pool, $session);
// All three depths share one Session: a Session's server process can run
// on a slower core than another's, which would read as a layer's cost.
$driver = new ExtensionSession($native);
$core = new Connection($driver, $config);

$nativeRows = static function (array $result): int {
    $n = 0;
    while (($batch = $result['cursor']->fetchBatch()) !== []) {
        $n += count($batch);
    }

    return $n;
};
$nativeOutRows = static function (array $result, string $name): int {
    $n = 0;
    while (($batch = $result['outValues'][$name][0]->fetchBatch()) !== []) {
        $n += count($batch);
    }

    return $n;
};
$driverRows = static function ($cursor): int {
    $n = 0;
    while (($batch = $cursor->fetchBatch()) !== []) {
        $n += count($batch);
    }

    return $n;
};
$scalarBinds = static fn (int $i): array => [...scalar_in($i), 'p_date' => SCALAR_DATE, 'o_num' => new Out\Number(), 'o_str' => new Out\Varchar(), 'o_date' => new Out\Date()];

// name => [iterations, [layer => closure(int $i): mixed]]
$work = [
    'Call 3 IN + 3 OUT (block)' => [2000, [
        'native' => static fn (int $i): array => $native->execute(SQL_SCALAR, $scalarBinds($i), $nativeOptions()),
        'driver' => static fn (int $i): array => $driver->execute(SQL_SCALAR, $scalarBinds($i), $driverOptions)->outValues(),
        'core run()' => static function (int $i) use ($core, $scalarBinds): array {
            $e = $core->run(SQL_SCALAR, $scalarBinds($i));

            return [$e->out('o_num')->int(), $e->out('o_str')->string(), $e->out('o_date')->dateTime()];
        },
        'core procedure()' => static function (int $i) use ($core): array {
            $e = $core->procedure('fx_scalar.echo_out', [...scalar_in($i), 'p_date' => new In\Date(new DateTimeImmutable(SCALAR_DATE)), 'o_num' => new Out\Number(), 'o_str' => new Out\Varchar(), 'o_date' => new Out\Date()]);

            return [$e->out('o_num')->int(), $e->out('o_str')->string(), $e->out('o_date')->dateTime()];
        },
        'native, autocommit off' => static fn (int $i): array => $native->execute(SQL_SCALAR, $scalarBinds($i), $nativeOptions(['autocommit' => false])),
        'native, no binds (begin null; end;)' => static fn (int $i): array => $native->execute('begin null; end;', [], $nativeOptions()),
    ]],
    'Fetch 10k × 9, default values' => [20, [
        'native' => static fn (): int => $nativeRows($native->execute(SQL_FETCH, [], $nativeOptions())),
        'driver' => static fn (): int => $driverRows($driver->execute(SQL_FETCH, [], $driverOptions)->cursor()),
        'core all()' => static fn (): int => count($core->select(SQL_FETCH)->all()),
        'core foreach' => static fn (): int => core_each($core->select(SQL_FETCH)),
        'driver, every row kept' => static function () use ($driver, $driverOptions): int {
            $cursor = $driver->execute(SQL_FETCH, [], $driverOptions)->cursor();
            $rows = [];
            while (($batch = $cursor->fetchBatch()) !== []) {
                array_push($rows, ...$batch);
            }

            return count($rows);
        },
        'native, fast opt-ins' => static fn (): int => $nativeRows($native->execute(SQL_FETCH, [], $nativeOptions(['numbers' => 'float', 'temporals' => 'string']))),
        'native, list' => static fn (): int => $nativeRows($native->execute(SQL_FETCH, [], $nativeOptions(['rowShape' => 'list']))),
        'native, fetchSize 1000' => static fn (): int => $nativeRows($native->execute(SQL_FETCH, [], $nativeOptions(['fetchSize' => 1000]))),
    ]],
    'Fetch 10k, one column each' => [20, [
        'native id NUMBER(10)' => static fn (): int => $nativeRows($native->execute('select id from fx_bench_rows', [], $nativeOptions())),
        'native amount NUMBER(12,2)' => static fn (): int => $nativeRows($native->execute('select amount from fx_bench_rows', [], $nativeOptions())),
        'native ratio BINARY_DOUBLE' => static fn (): int => $nativeRows($native->execute('select ratio from fx_bench_rows', [], $nativeOptions())),
        'native code VARCHAR2' => static fn (): int => $nativeRows($native->execute('select code from fx_bench_rows', [], $nativeOptions())),
        'native created_at DATE object' => static fn (): int => $nativeRows($native->execute('select created_at from fx_bench_rows', [], $nativeOptions())),
        'native created_at DATE string' => static fn (): int => $nativeRows($native->execute('select created_at from fx_bench_rows', [], $nativeOptions(['temporals' => 'string']))),
        'native updated_at TIMESTAMP object' => static fn (): int => $nativeRows($native->execute('select updated_at from fx_bench_rows', [], $nativeOptions())),
        'native updated_at TIMESTAMP string' => static fn (): int => $nativeRows($native->execute('select updated_at from fx_bench_rows', [], $nativeOptions(['temporals' => 'string']))),
        'native constant 1 (protocol floor)' => static fn (): int => $nativeRows($native->execute('select 1 from fx_bench_rows', [], $nativeOptions())),
    ]],
    'REF CURSOR 1k × 5' => [200, [
        'native' => static fn (): int => $nativeOutRows($native->execute(SQL_CURSOR, ['rc' => new Out\Cursor(), 'n' => CURSOR_ROWS], $nativeOptions()), 'rc'),
        'driver' => static fn (): int => $driverRows($driver->execute(SQL_CURSOR, ['rc' => new Out\Cursor(), 'n' => CURSOR_ROWS], $driverOptions)->outValues()['rc'][0]),
        'core run()' => static fn (): int => count($core->run(SQL_CURSOR, ['rc' => new Out\Cursor(), 'n' => CURSOR_ROWS])->out('rc')->cursor()->all()),
        'core function()' => static fn (): int => count($core->function('fx_cursor.items', ['p_limit' => CURSOR_ROWS])->cursor()->all()),
        'native, fast opt-ins' => static fn (): int => $nativeOutRows($native->execute(SQL_CURSOR, ['rc' => new Out\Cursor(), 'n' => CURSOR_ROWS], $nativeOptions(['numbers' => 'float', 'temporals' => 'string'])), 'rc'),
    ]],
    'LOBs 1k × (CLOB + BLOB)' => [20, [
        'native inline' => static fn (): int => $nativeRows($native->execute(SQL_LOBS, [], $nativeOptions(['lobs' => 'inline']))),
        'native locators, unread' => static fn (): int => $nativeRows($native->execute(SQL_LOBS, [], $nativeOptions())),
        'core inline' => static fn (): int => count(core_lobs($core, true)),
        'core Lob::contents()' => static fn (): int => count(core_lobs($core, false)),
    ]],
    'One 16 MiB BLOB' => [10, [
        'core contents()' => static fn (): int => strlen($core->select(SQL_LARGE_LOB)->value()->contents()),
        'core stream_get_contents(stream())' => static fn (): int => strlen(stream_get_contents($core->select(SQL_LARGE_LOB)->value()->stream())),
        'core inline' => static fn (): int => strlen($core->select(SQL_LARGE_LOB, options: new Options(values: new ValueMapping(lobs: LobMode::Inline)))->value()),
    ]],
    'Connect + close (Pool)' => [2000, [
        'native' => static fn () => Extension\Connection::connect($pool, $session)->close(),
        'driver' => static fn () => new ExtensionManager()->connect($config)->close(),
        'core' => static fn () => Oracle::connect($config)->close(),
        'core, ConnectionConfig built each time' => static fn () => Oracle::connect($x->config())->close(),
    ]],
    'Insert 1k rows' => [20, [
        'native executeMany' => static fn (): array => $native->executeMany(SQL_INSERT, insert_rows(), $nativeOptions()),
        'core runMany()' => static fn (): int => $core->runMany(SQL_INSERT, insert_rows())->affected(),
    ]],
];
$cleanup = ['Insert 1k rows' => static fn (): array => $native->execute(SQL_CLEANUP, [], $nativeOptions())];

$observer = oci_new_connect($x->user, $x->password, $x->dsn, 'AL32UTF8');
$sid = (int) $core->select("select sys_context('userenv', 'sid') as sid from dual")->value();
$roundTrips = static function (int $sid) use ($observer): int {
    $statement = oci_parse($observer, "select s.value from v\$sesstat s join v\$statname n on n.statistic# = s.statistic# where n.name = 'SQL*Net roundtrips to/from client' and s.sid = :sid");
    oci_bind_by_name($statement, ':sid', $sid);
    oci_execute($statement);

    return (int) oci_fetch_array($statement, OCI_NUM)[0];
};

foreach ($work as $name => [$iterations, $layers]) {
    printf("\n## %s (%d iterations × %d rounds)\n\n| Layer | Median | Round trips per iteration |\n|---|---|---|\n", $name, $iterations, $rounds);
    $samples = array_fill_keys(array_keys($layers), []);
    foreach ($layers as $layer => $run) {
        for ($i = 0; $i < max(3, intdiv($iterations, 10)); $i++) {
            $run($i);
            ($cleanup[$name] ?? null)?->__invoke();
        }
    }
    for ($round = 0; $round < $rounds; $round++) {
        foreach ($layers as $layer => $run) {
            for ($i = 0; $i < $iterations; $i++) {
                $start = hrtime(true);
                $result = $run($i);
                unset($result);
                $samples[$layer][] = hrtime(true) - $start;
                ($cleanup[$name] ?? null)?->__invoke();
            }
        }
    }
    foreach ($layers as $layer => $run) {
        $trips = 'n/a (new Session)';
        if (!str_starts_with($name, 'Connect')) {
            $before = $roundTrips($sid);
            $run(1);
            $trips = (string) ($roundTrips($sid) - $before);
            ($cleanup[$name] ?? null)?->__invoke();
        }
        $median = Stats::median($samples[$layer]);
        printf("| %s | %s | %s |\n", $layer, $median >= 1e6 ? sprintf('%.2f ms', $median / 1e6) : sprintf('%.1f µs', $median / 1e3), $trips);
    }
}
