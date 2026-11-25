<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Conformance\ConnectParametersTest;

use LogicException;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\Extension\ExtensionDriver;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Tests\Support\ConnectTargets;
use Rajska\Oracle\Tests\Support\TestDatabase;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WeakMap;

beforeEach(function (): void {
    $this->tnsAdmin = getenv('TNS_ADMIN');
    $this->directories = [];
});
afterEach(function (): void {
    putenv($this->tnsAdmin === false ? 'TNS_ADMIN' : "TNS_ADMIN=$this->tnsAdmin");
    foreach ($this->directories as $directory) {
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
    $nowhere = '192.0.2.1:1521/FREEPDB1';

    $exception = rejected($nowhere . $parameters);

    expect($exception->getMessage())->toContain('Easy Connect Plus')
        ->and($exception->getMessage())->toContain($instead);
})->with('easyConnectPlusParameters');
/**
 * @return iterable<string, array{string, string}>
 */
dataset('rejectedKeys', function () {
    yield 'WALLET_LOCATION' => ['(SECURITY=(WALLET_LOCATION=/tmp))', 'walletLocation'];
    yield 'SSL_SERVER_DN_MATCH' => ['(SECURITY=(ssl_server_dn_match = yes))', 'host name'];
    yield 'SSL_SERVER_CERT_DN' => ['(SECURITY=(SSL_SERVER_CERT_DN="CN=oracle"))', 'host name'];
    yield 'POOL_PURITY' => ['(CONNECT_DATA=(POOL_PURITY=SELF))', 'no purity setting'];
    yield 'TRANSPORT_CONNECT_TIMEOUT' => ['(TRANSPORT_CONNECT_TIMEOUT=5)', 'connectTimeout'];
});
test('a descriptor key the backend would ignore is rejected before any network io', function (string $setting, string $instead): void {
    $nowhere = '192.0.2.1:1521/FREEPDB1';

    $exception = rejected(ConnectTargets::descriptor($nowhere, $setting));

    $key = strtoupper((string) preg_replace('~^\((?:\w+=\()?\s*(\w+).*$~', '$1', $setting));
    expect($exception->getMessage())->toContain("The dsn sets $key")
        ->and($exception->getMessage())->toContain($instead);
})->with('rejectedKeys');
test('a tns alias whose entry sets a rejected key is rejected before any network io', function (): void {
    $nowhere = '192.0.2.1:1521/FREEPDB1';

    $directory = tnsnames([
        'nowhere_timeout' => ConnectTargets::descriptor($nowhere, '(TRANSPORT_CONNECT_TIMEOUT=5)'),
        'nowhere_plus' => $nowhere . '?transport_connect_timeout=5',
    ]);

    $exception = rejected('nowhere_timeout', configDir: $directory);
    expect($exception->getMessage())->toContain("The TNS alias 'nowhere_timeout' (in $directory/tnsnames.ora) sets TRANSPORT_CONNECT_TIMEOUT");

    putenv("TNS_ADMIN=$directory");
    $exception = rejected('NOWHERE_PLUS');
    expect($exception->getMessage())->toContain('Easy Connect Plus');
});
/**
 * @return iterable<string, array{string}>
 */
dataset('dsnForms', function () {
    yield 'Easy Connect' => ['easy'];
    yield 'descriptor' => ['descriptor'];
    yield 'TNS alias from configDir' => ['configDir'];
    yield 'TNS alias from $TNS_ADMIN' => ['TNS_ADMIN'];
});
test('every dsn form connects', function (string $form): void {
    $easy = TestDatabase::fromEnvironment()->connectString;
    $fields = match ($form) {
        'easy' => ['dsn' => $easy],
        'descriptor' => ['dsn' => ConnectTargets::descriptor($easy)],
        'configDir' => ['dsn' => 'rajska_test', 'configDir' => tnsnames(['rajska_test' => ConnectTargets::descriptor($easy)])],
        'TNS_ADMIN' => ['dsn' => 'rajska_test'],
        default => throw new LogicException("Unknown form $form."),
    };
    if ($form === 'TNS_ADMIN') {
        putenv('TNS_ADMIN=' . tnsnames(['other, RAJSKA_TEST' => $easy]));
    }

    $connection = $this->connect(...[...$fields, 'standalone' => true]);

    expect(self::value($connection, 'select 1 from dual'))->toBe(1);
})->with('dsnForms');
test('an unroutable host fails within connect timeout', function (bool $standalone): void {
    $nowhere = '192.0.2.1:1521/FREEPDB1';

    $started = microtime(true);
    try {
        $this->connect(dsn: $nowhere, connectTimeout: 1.5, standalone: $standalone);
        self::fail('connect() to an unroutable host succeeded.');
    } catch (ConnectionException $connectionException) {
        $elapsed = microtime(true) - $started;
        expect($connectionException->getCode())->toBe(12170, $connectionException->getMessage())
            ->and($connectionException->getMessage())->toContain('connectTimeout of 1500 ms')
            ->and($elapsed)->toBeGreaterThanOrEqual(1.4)
            ->toBeLessThan(4.0);
    }
})->with('pooledAndStandalone');
/**
 * @return iterable<string, array{bool}>
 */
dataset('pooledAndStandalone', function () {
    yield 'pooled' => [false];
    yield 'standalone' => [true];
});
test('tls through a private ca connects with its pem wallet', function (bool $standalone): void {
    $connection = $this->connect(
        dsn: ConnectTargets::variable('ORACLE_TLS_CONNECT_STRING'),
        walletLocation: ConnectTargets::wallet('ca'),
        standalone: $standalone,
        connectTimeout: self::ownPool()['connectTimeout'],
    );

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
    yield 'encrypted key, pooled' => ['mtls-encrypted', ConnectTargets::WALLET_PASSWORD, false];
    yield 'encrypted key, standalone' => ['mtls-encrypted', ConnectTargets::WALLET_PASSWORD, true];
});
test('mtls connects with the clients pem wallet', function (string $wallet, string | null $walletPassword, bool $standalone): void {
    $connection = $this->connect(
        dsn: ConnectTargets::variable('ORACLE_MTLS_CONNECT_STRING'),
        walletLocation: ConnectTargets::wallet($wallet),
        walletPassword: $walletPassword,
        standalone: $standalone,
        connectTimeout: self::ownPool()['connectTimeout'],
    );

    expect(protocol($connection))->toBe('tcps');
})->with('mtlsWallets');
test('the server certificate is verified against the roots and the host name', function (): void {
    $dsn = ConnectTargets::variable('ORACLE_TLS_CONNECT_STRING');
    try {
        $this->connect(dsn: $dsn, standalone: true);
        self::fail('TLS to a private CA connected without its wallet.');
    } catch (ConnectionException $connectionException) {
        expect($connectionException->getMessage())->toContain('UnknownIssuer');
    }

    $parts = ConnectTargets::parts($dsn);
    $address = gethostbyname($parts['host']);

    // The certificate names 127.0.0.1; any other loopback address
    // reaches the same listener on Linux.
    $address = $address === '127.0.0.1' ? '127.0.0.2' : $address;
    try {
        $this->connect(
            dsn: "tcps://$address:{$parts['port']}/{$parts['service']}",
            walletLocation: ConnectTargets::wallet('ca'),
            standalone: true,
        );
        self::fail('TLS connected to a host name the certificate does not name.');
    } catch (ConnectionException $connectionException) {
        expect($connectionException->getMessage())->toContain('certificate not valid for name');
    }
});
/**
 * @return iterable<string, array{string}>
 */
dataset('unreadableWallets', function () {
    yield 'cwallet.sso' => ['sso'];
    yield 'ewallet.p12' => ['p12'];
});
test('a wallet that is not pem fails with conversion instructions', function (string $wallet): void {
    $nowhere = '192.0.2.1:1521/FREEPDB1';

    $exception = rejected('tcps://' . $nowhere, walletLocation: ConnectTargets::wallet($wallet));

    expect($exception->getMessage())->toContain('has no ewallet.pem')
        ->and($exception->getMessage())->toContain('openssl pkcs12 -in')
        ->and($exception->getMessage())->toContain('-out ');
})->with('unreadableWallets');
test('a wallet location without any wallet is a configuration error', function (): void {
    $nowhere = '192.0.2.1:1521/FREEPDB1';

    $exception = rejected('tcps://' . $nowhere, walletLocation: tnsnames([]));

    expect($exception->getMessage())->toContain('No ewallet.pem');
});
test('proxy user connects as the proxy client', function (bool $standalone): void {
    $user = strtoupper(TestDatabase::fromEnvironment()->user);
    $client = "{$user}_CLIENT";

    $connection = $this->connect(proxyUser: $client, standalone: $standalone, connectTimeout: self::ownPool()['connectTimeout']);

    expect(self::rows(
        $connection,
        "select sys_context('userenv', 'session_user') as session_user, sys_context('userenv', 'proxy_user') as proxy_user from dual",
    ))->toBe([['SESSION_USER' => $client, 'PROXY_USER' => $user]]);
})->with('pooledAndStandalone');
/**
 * @return iterable<string, array{string, bool}>
 */
dataset('drcpTargets', function () {
    yield ':pooled, pooled' => [':pooled', false];
    yield ':pooled, standalone' => [':pooled', true];
    yield '(SERVER=POOLED), pooled' => ['(SERVER=POOLED)', false];
    yield '(SERVER=POOLED), standalone' => ['(SERVER=POOLED)', true];
});
test('drcp connects through the dsn', function (string $how, bool $standalone): void {
    $easy = TestDatabase::fromEnvironment()->connectString;
    $dsn = $how === ':pooled'
        ? "$easy:pooled"
        : str_replace('(SERVICE_NAME=', '(SERVER=POOLED)(SERVICE_NAME=', ConnectTargets::descriptor($easy));

    $connection = $this->connect(dsn: $dsn, standalone: $standalone, connectTimeout: self::ownPool()['connectTimeout']);

    expect(self::value($connection, "select server from v\$session where sid = sys_context('userenv', 'sid')"))->toBe('POOLED');
    try {
        $connection->execute('insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)', ['id' => 41, 'code' => 'DRCP41a'], self::options(autocommit: false));
        $connection->execute('insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)', ['id' => 41, 'code' => 'DRCP41b'], self::options(autocommit: false));
        self::fail('The duplicate insert succeeded.');
    } catch (UniqueViolationException $uniqueViolationException) {
        expect($uniqueViolationException->getMessage())->not->toContain('ORA-03301')
            ->and($uniqueViolationException->getMessage())->not->toContain('DRCP41');
    }
})->with('drcpTargets');
test('two processes without a class share one drcp connection class', function (): void {
    $user = strtoupper(TestDatabase::fromEnvironment()->user);
    $before = drcpClasses($user);

    $classes = [];
    for ($process = 0; $process < 2; $process++) {
        runChild(TestDatabase::fromEnvironment()->connectString . ':pooled');
        $after = drcpClasses($user);
        $classes[] = array_keys(array_filter($after, static fn(int $requests, string $class): bool => $requests > ($before[$class] ?? 0), ARRAY_FILTER_USE_BOTH));
        $before = $after;
    }

    expect($classes)->toBe([["$user.RAJSKA:{user()}"], ["$user.RAJSKA:{user()}"]]);
});
test('drcp connection class names the class', function (): void {
    $user = strtoupper(TestDatabase::fromEnvironment()->user);
    $before = drcpClasses($user);

    $connection = $this->connect(dsn: TestDatabase::fromEnvironment()->connectString . ':pooled', drcpConnectionClass: 'RAJSKA41', standalone: true);
    self::value($connection, 'select 1 from dual');

    $after = drcpClasses($user);
    expect($after["$user.RAJSKA41"] ?? 0)->toBeGreaterThan($before["$user.RAJSKA41"] ?? 0);
});
test('secrets never appear in dumps messages or traces', function (): void {
    $password = 'Pa55-' . bin2hex(random_bytes(6));
    $walletPassword = 'Wa11et-' . bin2hex(random_bytes(6));
    $config = TestDatabase::fromEnvironment()->config(password: $password, walletPassword: $walletPassword, standalone: true);

    ob_start();
    var_dump($config);
    $dumped = ob_get_clean() . print_r($config, true);

    expect($dumped)->toContain('SensitiveParameterValue')
        ->toContain(TestDatabase::fromEnvironment()->user);

    $exception = failedConnect($config);
    expect($exception->getCode())->toBe(1017)->and([$dumped, $exception->getMessage(), (string) $exception, $exception->getTraceAsString()])->each->not->toContain($password)->not->toContain($walletPassword);
});
test('the adapter holds a configs password no longer than the config', function (): void {
    $password = 'Pa55-' . bin2hex(random_bytes(6));
    $config = TestDatabase::fromEnvironment()->config(password: $password, standalone: true);
    expect(failedConnect($config)->getCode())->toBe(1017)
        ->and(adapterHolds($password))->toBeTrue('kept while the config lives');

    unset($config);
    gc_collect_cycles();

    expect(adapterHolds($password))->toBeFalse();
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

    return $holds(new ReflectionClass(ExtensionDriver::class)->getStaticProperties());
}
test('a wrong wallet password is a configuration error that shows no secret', function (): void {
    $password = TestDatabase::fromEnvironment()->password;
    $walletPassword = 'Wa11et-' . bin2hex(random_bytes(6));

    $exception = failedConnect(TestDatabase::fromEnvironment()->config(
        dsn: ConnectTargets::variable('ORACLE_MTLS_CONNECT_STRING'),
        walletLocation: ConnectTargets::wallet('mtls-encrypted'),
        walletPassword: $walletPassword,
        standalone: true,
    ));

    expect($exception)->toBeInstanceOf(ConfigurationException::class);
    $this->assertStringContainsStringIgnoringCase('wallet', $exception->getMessage());
    expect([$exception->getMessage(), (string) $exception, $exception->getTraceAsString()])->each->not->toContain($password)->not->toContain($walletPassword);
});
function failedConnect(ConnectionConfig $config): Throwable
{
    try {
        test()->driver()->connect($config)->close();
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
        throw new RuntimeException("connect() accepted the dsn $dsn.");
    } catch (ConfigurationException $configurationException) {
        expect(microtime(true) - $started)->toBeLessThan(1.0, 'The check waited for the network.');

        return $configurationException;
    }
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

    $text = "# Written by ConnectParametersTest.\n";
    foreach ($entries as $names => $value) {
        $text .= "$names =\n  $value\n\n";
    }
    file_put_contents("$directory/tnsnames.ora", $text);

    return $directory;
}
function user(): string
{
    return TestDatabase::fromEnvironment()->user;
}
function protocol(DriverConnection $connection): mixed
{
    return test()->value($connection, "select sys_context('userenv', 'network_protocol') from dual");
}
/**
 * Requests per DRCP connection class of this user, read from the CDB
 * root.
 *
 * @return array<string, int>
 */
function drcpClasses(string $user): array
{
    $classes = [];
    foreach (ConnectTargets::admin('select cclass_name, num_requests from v$cpool_cc_stats where cclass_name like :prefix', ['prefix' => "$user.%"]) as $row) {
        $classes[(string) $row['CCLASS_NAME']] = (int) $row['NUM_REQUESTS'];
    }

    return $classes;
}
/**
 * Connects in a child PHP process, as a second FPM worker would, and
 * runs one query there.
 */
function runChild(string $dsn): void
{
    $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
    $code = <<<'PHP'
            require $argv[1];
            $config = Rajska\Oracle\Tests\Support\TestDatabase::fromEnvironment()->config(dsn: $argv[2]);
            $connection = new Rajska\Oracle\Driver\Extension\ExtensionDriver()->connect($config);
            $connection->execute('select 1 from dual', [], new Rajska\Oracle\Driver\DriverOptions(true, null, 100, new Rajska\Oracle\ValueMapping(), Rajska\Oracle\RowShape::Assoc, Rajska\Oracle\KeyCase::Native))->cursor()?->fetchBatch();
            $connection->close();
            echo 'connected';
            PHP;
    $command = [\PHP_BINARY, '-d', 'extension=' . ConnectTargets::extensionPath(), '-r', $code, $autoload, $dsn];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $output)
        ->and($output)->toBe('connected');
}
