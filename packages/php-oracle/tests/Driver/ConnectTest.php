<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Driver\ConnectTest;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\ExtensionManager;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Driver\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakMap;

uses(TestCase::class);

/** An address nothing answers at: a connect to it can only time out. */
const NOWHERE = '192.0.2.1:1521/FREEPDB1';

/** The pass phrase of the encrypted wallet key (fixtures/tls/make.sh). */
const WALLET_PASSWORD = 'rajska_wallet1';

beforeAll(function (): void {
    schema()->run('create table drcp_rows (id number(10) primary key, code varchar2(10))');
});

beforeEach(function (): void {
    $this->tnsAdmin = getenv('TNS_ADMIN');
    $this->directories = [];
});

afterEach(function (): void {
    putenv($this->tnsAdmin === false ? 'TNS_ADMIN' : "TNS_ADMIN=$this->tnsAdmin");

    /** @var list<string> $directories */
    $directories = $this->directories;
    foreach ($directories as $directory) {
        foreach (glob("$directory/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

/**
 * @return iterable<string, array{string, string}>
 */
dataset('easyConnectPlusParameters', function () {
    yield 'transport_connect_timeout' => ['?transport_connect_timeout=5', 'connectTimeout'];
    yield 'connect_timeout' => ['?connect_timeout=5', 'connectTimeout'];
    yield 'wallet_location' => ['?wallet_location=/tmp', 'walletLocation'];
    yield 'pool_connection_class' => ['?pool_connection_class=MINE', 'drcpConnectionClass'];
    yield 'pool_purity' => ['?pool_purity=self', 'no purity setting'];
    yield 'ssl_server_dn_match' => ['?ssl_server_dn_match=on', 'host name'];
    yield 'an unknown one' => ['?expire_time=1', 'ConnectionConfig fields'];
});

test('easy connect plus parameters are rejected before any network io', function (string $parameters, string $instead): void {
    $exception = rejected(NOWHERE . $parameters);

    expect($exception->getMessage())->toContain('Easy Connect Plus')
        ->toContain($instead);
})->with('easyConnectPlusParameters');

/**
 * @return iterable<string, array{string, string, string}>
 */
dataset('rejectedKeys', function () {
    yield 'WALLET_LOCATION' => ['(SECURITY=(WALLET_LOCATION=/tmp))', 'WALLET_LOCATION', 'walletLocation'];
    yield 'SSL_SERVER_DN_MATCH' => ['(SECURITY=(ssl_server_dn_match = yes))', 'SSL_SERVER_DN_MATCH', 'host name'];
    yield 'SSL_SERVER_CERT_DN' => ['(SECURITY=(SSL_SERVER_CERT_DN="CN=oracle"))', 'SSL_SERVER_CERT_DN', 'host name'];
    yield 'POOL_PURITY' => ['(CONNECT_DATA=(POOL_PURITY=SELF))', 'POOL_PURITY', 'no purity setting'];
    yield 'TRANSPORT_CONNECT_TIMEOUT' => ['(TRANSPORT_CONNECT_TIMEOUT=5)', 'TRANSPORT_CONNECT_TIMEOUT', 'connectTimeout'];
});

test('a descriptor key the backend would ignore is rejected before any network io', function (string $setting, string $key, string $instead): void {
    $exception = rejected(descriptor(NOWHERE, $setting));

    expect($exception->getMessage())->toContain("The dsn sets $key")
        ->toContain($instead);
})->with('rejectedKeys');

test('a tns alias whose entry sets a rejected key is rejected before any network io', function (bool $fromTnsAdmin, string $alias, string $entry, string $message): void {
    $directory = tnsnames([$alias => $entry]);
    if ($fromTnsAdmin) {
        putenv("TNS_ADMIN=$directory");
    }

    $exception = $fromTnsAdmin ? rejected(strtoupper($alias)) : rejected($alias, configDir: $directory);

    expect($exception->getMessage())->toContain(str_replace('{directory}', $directory, $message));
})->with([
    'a descriptor from configDir' => [false, 'nowhere_timeout', descriptor(NOWHERE, '(TRANSPORT_CONNECT_TIMEOUT=5)'), "The TNS alias 'nowhere_timeout' (in {directory}/tnsnames.ora) sets TRANSPORT_CONNECT_TIMEOUT"],
    'Easy Connect Plus from $TNS_ADMIN' => [true, 'nowhere_plus', NOWHERE . '?transport_connect_timeout=5', 'Easy Connect Plus'],
]);

test('every dsn form connects', function (string $form): void {
    $easy = TestCase::config()->dsn;
    $fields = match ($form) {
        'easy' => ['dsn' => $easy],
        'descriptor' => ['dsn' => descriptor($easy)],
        'configDir' => ['dsn' => 'rajska_test', 'configDir' => tnsnames(['rajska_test' => descriptor($easy)])],
        default => ['dsn' => 'rajska_test'],
    };
    if ($form === 'TNS_ADMIN') {
        putenv('TNS_ADMIN=' . tnsnames(['other, RAJSKA_TEST' => $easy]));
    }

    $connection = $this->connect(...[...$fields, 'standalone' => true]);

    expect($this->value($connection, 'select 1 from dual'))->toBe(1);
})->with([
    'Easy Connect' => ['easy'],
    'descriptor' => ['descriptor'],
    'TNS alias from configDir' => ['configDir'],
    'TNS alias from $TNS_ADMIN' => ['TNS_ADMIN'],
]);

/**
 * @return iterable<string, array{bool}>
 */
dataset('pooledAndStandalone', function () {
    yield 'pooled' => [false];
    yield 'standalone' => [true];
});

test('an unroutable host fails within connect timeout', function (bool $standalone): void {
    $thrown = null;
    $started = microtime(true);

    try {
        $this->connect(dsn: NOWHERE, connectTimeout: 1.5, standalone: $standalone);
    } catch (ConnectionException $connectionException) {
        $thrown = $connectionException;
    }

    expect($thrown?->getCode())->toBe(12170)
        ->and($thrown?->getMessage())->toContain('connectTimeout of 1500 ms')
        ->and(microtime(true) - $started)->toBeGreaterThanOrEqual(1.4)->toBeLessThan(4.0);
})->with('pooledAndStandalone');

test('tls through a private ca connects with its pem wallet', function (bool $standalone): void {
    $dsn = variable('ORACLE_TLS_CONNECT_STRING');

    $connection = $this->connect(dsn: $dsn, walletLocation: wallet('ca'), standalone: $standalone, connectTimeout: $this->ownPool()['connectTimeout']);

    expect(protocol($connection))->toBe('tcps');
})->with('pooledAndStandalone');

/**
 * mTLS, with the client's certificate and key in ewallet.pem (and the
 * private CA).
 *
 * @return iterable<string, array{string, ?string, bool}>
 */
dataset('mtlsWallets', function () {
    yield 'plain key, pooled' => ['mtls', null, false];
    yield 'plain key, standalone' => ['mtls', null, true];
    yield 'encrypted key, pooled' => ['mtls-encrypted', WALLET_PASSWORD, false];
    yield 'encrypted key, standalone' => ['mtls-encrypted', WALLET_PASSWORD, true];
});

test('mtls connects with the clients pem wallet', function (string $wallet, string | null $walletPassword, bool $standalone): void {
    $dsn = variable('ORACLE_MTLS_CONNECT_STRING');

    $connection = $this->connect(
        dsn: $dsn,
        walletLocation: wallet($wallet),
        walletPassword: $walletPassword,
        standalone: $standalone,
        connectTimeout: $this->ownPool()['connectTimeout'],
    );

    expect(protocol($connection))->toBe('tcps');
})->with('mtlsWallets');

test('the server certificate is verified against the roots and the host name', function (bool $withTheRoots, string $message): void {
    $parts = parts(variable('ORACLE_TLS_CONNECT_STRING'));
    // The certificate names 127.0.0.1; any other loopback address reaches
    // the same listener on Linux.
    $address = gethostbyname($parts['host']);
    $host = $withTheRoots ? ($address === '127.0.0.1' ? '127.0.0.2' : $address) : $parts['host'];
    $fields = ['dsn' => "tcps://$host:{$parts['port']}/{$parts['service']}", 'standalone' => true, ...($withTheRoots ? ['walletLocation' => wallet('ca')] : [])];
    $thrown = null;

    try {
        $this->connect(...$fields);
    } catch (ConnectionException $connectionException) {
        $thrown = $connectionException;
    }

    expect($thrown?->getMessage())->toContain($message);
})->with([
    'a server signed by a root it was not given' => [false, 'UnknownIssuer'],
    'a host name the certificate does not name' => [true, 'certificate not valid for name'],
]);

test('a wallet that is not pem fails with conversion instructions', function (string $wallet): void {
    $exception = rejected('tcps://' . NOWHERE, walletLocation: wallet($wallet));

    expect($exception->getMessage())->toContain('has no ewallet.pem')
        ->toContain('openssl pkcs12 -in')
        ->toContain('-out ');
})->with(['cwallet.sso' => ['sso'], 'ewallet.p12' => ['p12']]);

test('a wallet location without any wallet is a configuration error', function (): void {
    $exception = rejected('tcps://' . NOWHERE, walletLocation: tnsnames([]));

    expect($exception->getMessage())->toContain('No ewallet.pem');
});

test('a wrong wallet password is a configuration error that shows no secret', function (): void {
    $walletPassword = 'Wa11et-' . bin2hex(random_bytes(6));
    $config = $this->config(
        dsn: variable('ORACLE_MTLS_CONNECT_STRING'),
        walletLocation: wallet('mtls-encrypted'),
        walletPassword: $walletPassword,
        standalone: true,
    );

    $exception = failedConnect($config);

    expect($exception)->toBeInstanceOf(ConfigurationException::class)
        ->and(strtolower($exception->getMessage()))->toContain('wallet')
        ->and([$exception->getMessage(), (string) $exception, $exception->getTraceAsString()])->each->not->toContain($config->password)->not->toContain($walletPassword);
});

test('proxy user connects as the proxy client', function (bool $standalone): void {
    $user = strtoupper($this->config()->user);
    $client = "{$user}_CLIENT";

    $connection = $this->connect(proxyUser: $client, standalone: $standalone, connectTimeout: $this->ownPool()['connectTimeout']);

    expect($this->rows(
        $connection,
        "select sys_context('userenv', 'session_user') as session_user, sys_context('userenv', 'proxy_user') as proxy_user from dual",
    ))->toBe([['SESSION_USER' => $client, 'PROXY_USER' => $user]]);
})->with('pooledAndStandalone');

test('two processes without a class share one drcp connection class', function (): void {
    $user = strtoupper($this->config()->user);
    $before = drcpClasses($user);
    $classes = [];

    for ($process = 0; $process < 2; $process++) {
        runChild($this->config()->dsn . ':pooled');
        $after = drcpClasses($user);
        $classes[] = array_keys(array_filter($after, static fn (int $requests, string $class): bool => $requests > ($before[$class] ?? 0), ARRAY_FILTER_USE_BOTH));
        $before = $after;
    }

    $osUser = $this->config()->user;
    expect($classes)->toBe([["$user.RAJSKA:$osUser"], ["$user.RAJSKA:$osUser"]]);
});

test('drcp connection class names the class', function (): void {
    $user = strtoupper($this->config()->user);
    $before = drcpClasses($user);
    $connection = $this->connect(dsn: $this->config()->dsn . ':pooled', drcpConnectionClass: 'RAJSKA41', standalone: true);

    $this->value($connection, 'select 1 from dual');

    expect(drcpClasses($user)["$user.RAJSKA41"] ?? 0)->toBeGreaterThan($before["$user.RAJSKA41"] ?? 0);
});

test('a unique violation through drcp carries no ora 03301 line and no bound value', function (): void {
    $connection = $this->connect(dsn: $this->config()->dsn . ':pooled', connectTimeout: $this->ownPool()['connectTimeout']);
    $insert = 'insert into drcp_rows (id, code) values (:id, :code)';
    $connection->execute($insert, ['id' => 41, 'code' => 'DRCP41a'], $this->options(autocommit: false));
    $thrown = null;

    try {
        $connection->execute($insert, ['id' => 41, 'code' => 'DRCP41b'], $this->options(autocommit: false));
    } catch (UniqueViolationException $uniqueViolationException) {
        $thrown = $uniqueViolationException;
    }

    expect($thrown?->getMessage())->toStartWith('ORA-00001')
        ->not->toContain('ORA-03301')
        ->not->toContain('DRCP41');
});

test('secrets never appear in dumps messages or traces', function (): void {
    $password = 'Pa55-' . bin2hex(random_bytes(6));
    $walletPassword = 'Wa11et-' . bin2hex(random_bytes(6));
    $config = $this->config(password: $password, walletPassword: $walletPassword, standalone: true);

    ob_start();
    var_dump($config);
    $dumped = ob_get_clean() . print_r($config, true);
    $exception = failedConnect($config);

    expect($dumped)->toContain('SensitiveParameterValue')->toContain($config->user)
        ->and($exception->getCode())->toBe(1017)
        ->and([$dumped, $exception->getMessage(), (string) $exception, $exception->getTraceAsString()])->each->not->toContain($password)->not->toContain($walletPassword);
});

test('the adapter holds a configs password no longer than the config', function (): void {
    $password = 'Pa55-' . bin2hex(random_bytes(6));
    $config = $this->config(password: $password, standalone: true);
    failedConnect($config);
    $whileTheConfigLives = adapterHolds($password);

    unset($config);
    gc_collect_cycles();

    expect($whileTheConfigLives)->toBeTrue()
        ->and(adapterHolds($password))->toBeFalse();
});

function adapterHolds(string $secret): bool
{
    $holds = static function (mixed $value) use (&$holds, $secret): bool {
        if ($value === $secret) {
            return true;
        }
        if (\is_array($value) || $value instanceof WeakMap) {
            foreach ($value as $element) {
                if ($holds($element)) {
                    return true;
                }
            }
        }

        return false;
    };

    return $holds(new ReflectionClass(ExtensionManager::class)->getStaticProperties());
}

function failedConnect(ConnectionConfig $config): Throwable
{
    try {
        new ExtensionManager()->connect($config)->close();
    } catch (Throwable $throwable) {
        return $throwable;
    }

    throw new RuntimeException('connect() succeeded.');
}

function rejected(string $dsn, mixed ...$fields): ConfigurationException
{
    $started = microtime(true);

    try {
        test()->connect(...['dsn' => $dsn, 'standalone' => true, ...$fields]);
    } catch (ConfigurationException $configurationException) {
        expect(microtime(true) - $started)->toBeLessThan(1.0, 'The check waited for the network.');

        return $configurationException;
    }

    throw new RuntimeException("connect() accepted the dsn $dsn.");
}

/**
 * A new directory holding a tnsnames.ora with these entries.
 *
 * @param array<string, string> $entries
 */
function tnsnames(array $entries): string
{
    $directory = sys_get_temp_dir() . '/rajska-tns-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $test = test();
    $test->directories = [...$test->directories, $directory];

    $text = "# Written by ConnectTest.\n";
    foreach ($entries as $names => $value) {
        $text .= "$names =\n  $value\n\n";
    }
    file_put_contents("$directory/tnsnames.ora", $text);

    return $directory;
}

function protocol(Session $connection): mixed
{
    return test()->value($connection, "select sys_context('userenv', 'network_protocol') from dual");
}

/**
 * A connect target from the environment; the test is skipped when it is not
 * set.
 */
function variable(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        PHPUnitTestCase::markTestSkipped("Set $name to run this test.");
    }

    return $value;
}

/**
 * A client wallet directory of fixtures/tls/client: ca, mtls,
 * mtls-encrypted, p12 or sso.
 */
function wallet(string $name): string
{
    $path = \dirname(__DIR__, 4) . '/fixtures/tls/client/' . $name;
    if (!is_dir($path)) {
        throw new RuntimeException("No client wallet at $path.");
    }

    return $path;
}

/**
 * The host, port and service of an Easy Connect string such as
 * `oracle:1521/FREEPDB1` or `tcps://oracle:2484/FREEPDB1`.
 *
 * @return array{protocol: string, host: string, port: int, service: string}
 */
function parts(string $easyConnect): array
{
    if (preg_match('~^(?:(\w+)://)?([^:/]+)(?::(\d+))?/([\w.]+)$~', $easyConnect, $m) !== 1) {
        throw new RuntimeException("Not a plain Easy Connect string: $easyConnect");
    }

    return [
        'protocol' => $m[1] !== '' ? $m[1] : 'tcp',
        'host' => $m[2],
        'port' => $m[3] !== '' ? (int) $m[3] : 1521,
        'service' => $m[4],
    ];
}

/**
 * The same target as a full descriptor, with extra text inside DESCRIPTION.
 */
function descriptor(string $easyConnect, string $extra = ''): string
{
    $parts = parts($easyConnect);

    return \sprintf(
        '(DESCRIPTION=%s(ADDRESS=(PROTOCOL=%s)(HOST=%s)(PORT=%d))(CONNECT_DATA=(SERVICE_NAME=%s)))',
        $extra,
        strtoupper($parts['protocol']),
        $parts['host'],
        $parts['port'],
        $parts['service'],
    );
}

/**
 * Requests per DRCP connection class of this user, read as the admin user
 * from the CDB root, where DRCP keeps them.
 *
 * @return array<string, int>
 */
function drcpClasses(string $user): array
{
    $admin = Oracle::connect(new ConnectionConfig(
        dsn: variable('ORACLE_ADMIN_CONNECT_STRING'),
        user: variable('ORACLE_ADMIN_USER'),
        password: variable('ORACLE_ADMIN_PASSWORD'),
        standalone: true,
    ));

    try {
        $rows = $admin->select('select cclass_name, num_requests from v$cpool_cc_stats where cclass_name like :prefix', ['prefix' => "$user.%"])->all();
    } finally {
        $admin->close();
    }

    $classes = [];
    foreach ($rows as $row) {
        ['CCLASS_NAME' => $class, 'NUM_REQUESTS' => $requests] = $row;
        \assert(\is_string($class) && \is_int($requests));
        $classes[$class] = $requests;
    }

    return $classes;
}

/**
 * Connects in a child PHP process, as a second FPM worker would, and runs
 * one query there.
 */
function runChild(string $dsn): void
{
    $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
    $code = <<<'PHP'
        require $argv[1];
        $config = Rajska\Oracle\Tests\TestCase::config(dsn: $argv[2]);
        $connection = new Rajska\Oracle\Driver\ExtensionManager()->connect($config);
        $connection->execute('select 1 from dual', [], new Rajska\Oracle\Driver\Options(true, null, 100, new Rajska\Oracle\Statement\ValueMapping(), Rajska\Oracle\Statement\RowShape::Assoc, Rajska\Oracle\Statement\KeyCase::Native))->cursor()?->fetchBatch();
        $connection->close();
        echo 'connected';
        PHP;
    $process = proc_open([\PHP_BINARY, '-r', $code, $autoload, $dsn], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert(\is_resource($process));
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $output)
        ->and($output)->toBe('connected');
}
