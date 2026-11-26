<?php

declare(strict_types = 1);


// FrankenPHP worker of the concurrency run. Each worker thread runs this
// script once, then serves requests in the loop at the end.
//   ?d=oci8|core|core-held  &w=scalar|scalar-txn|cursor|fetch
//
// - oci8: one oci8 Connection per worker thread, for the worker's life, the
//   way a long-running oci8 worker keeps one.
// - core: Oracle::connect() on every request and close() when it ends: a
//   Session from the process's Pool and back (the usage Core documents).
// - core-held: one Core Connection per worker thread, like oci8.
//
// scalar-txn opens a transaction with an INSERT of a random id, runs the
// scalar Call, and ends the request with the transaction still open: oci8
// rolls back explicitly; Core's close() rolls back as part of the Release.
require __DIR__ . '/../bootstrap.php';

use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Oracle;

$context = BenchContext::fromEnvironment();
$config = $context->config(maxConnections: (int) getenv('WORKERS'));
// A Pool of its own (the config is the Pool key): the held Sessions must not
// starve the per-request driver's Pool.
$heldConfig = $context->config(maxConnections: (int) getenv('WORKERS') + 1);
$held = null;
$i = 0;

function core_scalar(Connection $connection, int $i): int
{
    $execution = $connection->procedure('fx_scalar.echo_out', [
        ...scalar_in($i),
        'p_date' => new In\Date(new DateTimeImmutable(SCALAR_DATE)),
        'o_num' => new Out\Number(),
        'o_str' => new Out\Varchar(),
        'o_date' => new Out\Date(),
    ]);

    return count([$execution->out('o_num')->int(), $execution->out('o_str')->string(), $execution->out('o_date')->dateTime()]);
}

$handler = static function () use ($context, $config, $heldConfig, &$held, &$i): void {
    $driver = $_GET['d'] ?? '';
    $workload = $_GET['w'] ?? '';
    $i++;
    $id = random_int(1, 999_999_999);
    if ($driver === 'oci8') {
        $connection = $context->oci8();
        echo match ($workload) {
            'scalar' => count(oci8_scalar($connection, $i, true)),
            'scalar-txn' => (static function () use ($connection, $id, $i): int {
                $statement = oci_parse($connection, SQL_INSERT);
                $code = 'r' . $id % 1_000_000_000;
                $quantity = 1;
                oci_bind_by_name($statement, ':id', $id, -1, SQLT_INT);
                oci_bind_by_name($statement, ':code', $code, 10);
                oci_bind_by_name($statement, ':qty', $quantity, -1, SQLT_INT);
                oci_execute($statement, OCI_NO_AUTO_COMMIT);
                $n = count(oci8_scalar($connection, $i, true, OCI_NO_AUTO_COMMIT));
                oci_rollback($connection);

                return $n;
            })(),
            'cursor' => count(oci8_cursor($connection, 'all', 'exact')),
            'fetch' => count(oci8_drain(oci8_query($connection, SQL_FETCH), 'all', 'exact')),
        };

        return;
    }
    $connection = $driver === 'core-held' ? ($held ??= Oracle::connect($heldConfig)) : Oracle::connect($config);
    echo match ($workload) {
        'scalar' => core_scalar($connection, $i),
        'scalar-txn' => (static function () use ($connection, $id, $i): int {
            $connection->begin();
            $connection->run(SQL_INSERT, ['id' => $id, 'code' => 'r' . $id % 1_000_000_000, 'qty' => 1]);

            return core_scalar($connection, $i);
        })(),
        'cursor' => count($connection->function('fx_cursor.items', ['p_limit' => CURSOR_ROWS])->cursor()->all()),
        'fetch' => count($connection->select(SQL_FETCH)->all()),
    };
    if ($driver === 'core-held') {
        if ($workload === 'scalar-txn') {
            $connection->rollback();
        }

        return;
    }
    $connection->close(); // the Release: rolls back scalar-txn's open transaction
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
