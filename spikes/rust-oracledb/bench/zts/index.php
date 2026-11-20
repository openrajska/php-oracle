<?php
// FrankenPHP worker for the ZTS concurrency run (#13). Each worker thread runs
// this script once and then serves requests in the loop below.
//   ?d=oci8|rajska|rajska-pool|rajska-pool-rb  &w=scalar|scalar-txn|cursor|fetch
// oci8 and rajska keep one Connection per worker thread for the worker's life;
// rajska-pool acquires from the process-wide Pool on every request and gives
// the Connection back when the request ends. scalar-txn runs an uncommitted
// INSERT before the Call, so the release has to roll back: oci8 and rajska
// roll back explicitly, rajska-pool leaves it to the Pool's release, which
// holds the Pool mutex during that round trip. rajska-pool-rb is the
// workaround: it rolls back explicitly before the release, outside the mutex.
require __DIR__ . '/../drivers.php';

$dsn = getenv('ORACLE_CONNECT_STRING');
$user = getenv('ORACLE_USER');
$password = getenv('ORACLE_PASSWORD');
$poolMax = (int) getenv('WORKERS');
$own = [];
$i = 0;

$handler = static function () use (&$own, &$i, $dsn, $user, $password, $poolMax): void {
    $d = $_GET['d'] ?? '';
    $w = $_GET['w'] ?? '';
    $i++;
    $driver = match ($d) {
        'oci8' => $own['oci8'] ??= new Oci8Driver($dsn, $user, $password),
        'rajska' => $own['rajska'] ??= RajskaDriver::connect($dsn, $user, $password),
        'rajska-pool', 'rajska-pool-rb' => new RajskaDriver(Rajska\Bench\Connection::pooled($dsn, $user, $password, $poolMax)),
    };
    $rows = match ($w) {
        'scalar' => count($driver->scalar($i, false)),
        'scalar-txn' => txn($driver, $d, $i),
        'cursor' => $driver->cursor('all', false),
        'fetch' => $driver->fetch('all', false, 0),
    };
    echo $rows;
};

/** An uncommitted INSERT with a random id, then the scalar Call, then the release. */
function txn(BenchDriver $driver, string $d, int $i): int
{
    $id = random_int(1, 999_999_999);
    $sql = 'insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)';
    if ($driver instanceof Oci8Driver) {
        $s = oci_parse($driver->connection(), $sql);
        $code = 'r' . $id;
        oci_bind_by_name($s, ':id', $id);
        oci_bind_by_name($s, ':code', $code);
        oci_execute($s, OCI_NO_AUTO_COMMIT);
        $driver->executeMode = OCI_NO_AUTO_COMMIT; // the default would commit the INSERT
        $n = count($driver->scalar($i, false));
        $driver->executeMode = OCI_COMMIT_ON_SUCCESS;
        oci_rollback($driver->connection());
        return $n;
    }
    $driver->connection()->execute($sql, ['id' => $id, 'code' => 'r' . $id]);
    $n = count($driver->scalar($i, false));
    if ($d === 'rajska' || $d === 'rajska-pool-rb') {
        $driver->connection()->rollback();
    }
    return $n; // rajska-pool: the Connection goes back to the Pool with the transaction open
}

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
