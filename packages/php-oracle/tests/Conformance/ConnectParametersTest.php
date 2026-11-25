<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Driver\DriverConnection;
use Rajska\Oracle\Driver\Extension\ExtensionDriver;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Tests\Support\ConnectTargets;
use Rajska\Oracle\Tests\Support\TestDatabase;

/**
 * Conformance catalog, the connect parameters (#41, spec #32 §11): Pool and
 * lifecycle 8–12, Scope 7, Errors 9 (connect timeout) and 10.
 *
 * "No network I/O" is shown with a host that never answers
 * (192.0.2.1, RFC 5737) and the default connectTimeout of 20 s: a check that
 * failed only after trying to connect would take 20 s.
 */
final class ConnectParametersTest extends ConformanceTestCase
{
    private const string NOWHERE = '192.0.2.1:1521/FREEPDB1';

    /** @var list<string> */
    private array $directories = [];

    private string|false $tnsAdmin = false;

    protected function setUp(): void
    {
        $this->tnsAdmin = getenv('TNS_ADMIN');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv($this->tnsAdmin === false ? 'TNS_ADMIN' : "TNS_ADMIN=$this->tnsAdmin");
        foreach ($this->directories as $directory) {
            foreach (glob("$directory/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function easyConnectPlusParameters(): iterable
    {
        yield 'transport_connect_timeout' => ['?transport_connect_timeout=5', 'connectTimeout'];
        yield 'connect_timeout' => ['?connect_timeout=5', 'connectTimeout'];
        yield 'wallet_location' => ['?wallet_location=/tmp', 'walletLocation'];
        yield 'pool_connection_class' => ['?pool_connection_class=MINE', 'drcpConnectionClass'];
        yield 'pool_purity' => ['?pool_purity=self', 'no purity setting'];
        yield 'ssl_server_dn_match' => ['?ssl_server_dn_match=on', 'host name'];
        yield 'an unknown one' => ['?expire_time=1', 'ConnectionConfig fields'];
    }

    /**
     * Pool and lifecycle 8 and Errors 10: Easy Connect Plus `?params` fail
     * before any network I/O, saying what to use instead.
     */
    #[Test]
    #[DataProvider('easyConnectPlusParameters')]
    public function easy_connect_plus_parameters_are_rejected_before_any_network_io(string $parameters, string $instead): void
    {
        $exception = $this->rejected(self::NOWHERE . $parameters);

        self::assertStringContainsString('Easy Connect Plus', $exception->getMessage());
        self::assertStringContainsString($instead, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedKeys(): iterable
    {
        yield 'WALLET_LOCATION' => ['(SECURITY=(WALLET_LOCATION=/tmp))', 'walletLocation'];
        yield 'SSL_SERVER_DN_MATCH' => ['(SECURITY=(ssl_server_dn_match = yes))', 'host name'];
        yield 'SSL_SERVER_CERT_DN' => ['(SECURITY=(SSL_SERVER_CERT_DN="CN=oracle"))', 'host name'];
        yield 'POOL_PURITY' => ['(CONNECT_DATA=(POOL_PURITY=SELF))', 'no purity setting'];
        yield 'TRANSPORT_CONNECT_TIMEOUT' => ['(TRANSPORT_CONNECT_TIMEOUT=5)', 'connectTimeout'];
    }

    /**
     * Pool and lifecycle 8 and Errors 10: a descriptor that sets a key the
     * Backend would ignore fails before any network I/O, naming the key and
     * what to use instead.
     */
    #[Test]
    #[DataProvider('rejectedKeys')]
    public function a_descriptor_key_the_backend_would_ignore_is_rejected_before_any_network_io(string $setting, string $instead): void
    {
        $exception = $this->rejected(ConnectTargets::descriptor(self::NOWHERE, $setting));

        $key = strtoupper((string) preg_replace('~^\((?:\w+=\()?\s*(\w+).*$~', '$1', $setting));
        self::assertStringContainsString("The dsn sets $key", $exception->getMessage());
        self::assertStringContainsString($instead, $exception->getMessage());
    }

    /**
     * Pool and lifecycle 8: the same check applies to the entry a TNS alias
     * names, from configDir and from $TNS_ADMIN.
     */
    #[Test]
    public function a_tns_alias_whose_entry_sets_a_rejected_key_is_rejected_before_any_network_io(): void
    {
        $directory = $this->tnsnames([
            'nowhere_timeout' => ConnectTargets::descriptor(self::NOWHERE, '(TRANSPORT_CONNECT_TIMEOUT=5)'),
            'nowhere_plus' => self::NOWHERE . '?transport_connect_timeout=5',
        ]);

        $exception = $this->rejected('nowhere_timeout', configDir: $directory);
        self::assertStringContainsString("The TNS alias 'nowhere_timeout' (in $directory/tnsnames.ora) sets TRANSPORT_CONNECT_TIMEOUT", $exception->getMessage());

        putenv("TNS_ADMIN=$directory");
        $exception = $this->rejected('NOWHERE_PLUS');
        self::assertStringContainsString('Easy Connect Plus', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dsnForms(): iterable
    {
        yield 'Easy Connect' => ['easy'];
        yield 'descriptor' => ['descriptor'];
        yield 'TNS alias from configDir' => ['configDir'];
        yield 'TNS alias from $TNS_ADMIN' => ['TNS_ADMIN'];
    }

    /**
     * Pool and lifecycle 8: Easy Connect, a descriptor and a TNS alias all
     * connect.
     */
    #[Test]
    #[DataProvider('dsnForms')]
    public function every_dsn_form_connects(string $form): void
    {
        $easy = TestDatabase::fromEnvironment()->connectString;
        $fields = match ($form) {
            'easy' => ['dsn' => $easy],
            'descriptor' => ['dsn' => ConnectTargets::descriptor($easy)],
            'configDir' => ['dsn' => 'rajska_test', 'configDir' => $this->tnsnames(['rajska_test' => ConnectTargets::descriptor($easy)])],
            'TNS_ADMIN' => ['dsn' => 'rajska_test'],
            default => throw new \LogicException("Unknown form $form."),
        };
        if ($form === 'TNS_ADMIN') {
            putenv('TNS_ADMIN=' . $this->tnsnames(['other, RAJSKA_TEST' => $easy]));
        }

        $connection = $this->connect(...[...$fields, 'standalone' => true]);

        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    /**
     * Pool and lifecycle 8 and Errors 9: a host that never answers fails
     * with ConnectionException (ORA-12170) once connectTimeout has passed,
     * pooled and standalone. The crate waits its retry delay (1 s) after
     * the last attempt too, hence the margin.
     */
    #[Test]
    #[DataProvider('pooledAndStandalone')]
    public function an_unroutable_host_fails_within_connect_timeout(bool $standalone): void
    {
        $started = microtime(true);
        try {
            $this->connect(dsn: self::NOWHERE, connectTimeout: 1.5, standalone: $standalone);
            self::fail('connect() to an unroutable host succeeded.');
        } catch (ConnectionException $e) {
            $elapsed = microtime(true) - $started;
            self::assertSame(12170, $e->getCode(), $e->getMessage());
            self::assertStringContainsString('connectTimeout of 1500 ms', $e->getMessage());
            self::assertGreaterThanOrEqual(1.4, $elapsed);
            self::assertLessThan(4.0, $elapsed);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function pooledAndStandalone(): iterable
    {
        yield 'pooled' => [false];
        yield 'standalone' => [true];
    }

    /**
     * Pool and lifecycle 9 and Scope 7: one-way TLS to a server whose
     * certificate a private CA signed, with that CA in ewallet.pem.
     */
    #[Test]
    #[DataProvider('pooledAndStandalone')]
    public function tls_through_a_private_ca_connects_with_its_pem_wallet(bool $standalone): void
    {
        $connection = $this->connect(
            dsn: ConnectTargets::variable('ORACLE_TLS_CONNECT_STRING'),
            walletLocation: ConnectTargets::wallet('ca'),
            standalone: $standalone,
            connectTimeout: self::ownPool()['connectTimeout'],
        );

        self::assertSame('tcps', self::protocol($connection));
    }

    /**
     * Pool and lifecycle 9: mTLS, with the client's certificate and key in
     * ewallet.pem (and the private CA), the key plain or encrypted with
     * walletPassword.
     *
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function mtlsWallets(): iterable
    {
        yield 'plain key, pooled' => ['mtls', null, false];
        yield 'plain key, standalone' => ['mtls', null, true];
        yield 'encrypted key, pooled' => ['mtls-encrypted', ConnectTargets::WALLET_PASSWORD, false];
        yield 'encrypted key, standalone' => ['mtls-encrypted', ConnectTargets::WALLET_PASSWORD, true];
    }

    #[Test]
    #[DataProvider('mtlsWallets')]
    public function mtls_connects_with_the_clients_pem_wallet(string $wallet, ?string $walletPassword, bool $standalone): void
    {
        $connection = $this->connect(
            dsn: ConnectTargets::variable('ORACLE_MTLS_CONNECT_STRING'),
            walletLocation: ConnectTargets::wallet($wallet),
            walletPassword: $walletPassword,
            standalone: $standalone,
            connectTimeout: self::ownPool()['connectTimeout'],
        );

        self::assertSame('tcps', self::protocol($connection));
    }

    /**
     * Pool and lifecycle 9: the server certificate is verified. Without the
     * private CA (no wallet: the bundled webpki roots only) the connect
     * fails, and so does a host the certificate does not name (its IP
     * address).
     */
    #[Test]
    public function the_server_certificate_is_verified_against_the_roots_and_the_host_name(): void
    {
        $dsn = ConnectTargets::variable('ORACLE_TLS_CONNECT_STRING');
        try {
            $this->connect(dsn: $dsn, standalone: true);
            self::fail('TLS to a private CA connected without its wallet.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('UnknownIssuer', $e->getMessage());
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
        } catch (ConnectionException $e) {
            self::assertStringContainsString('certificate not valid for name', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableWallets(): iterable
    {
        yield 'cwallet.sso' => ['sso'];
        yield 'ewallet.p12' => ['p12'];
    }

    /**
     * Pool and lifecycle 9 and Errors 10: a wallet without ewallet.pem
     * fails before any network I/O, with conversion instructions.
     */
    #[Test]
    #[DataProvider('unreadableWallets')]
    public function a_wallet_that_is_not_pem_fails_with_conversion_instructions(string $wallet): void
    {
        $exception = $this->rejected('tcps://' . self::NOWHERE, walletLocation: ConnectTargets::wallet($wallet));

        self::assertStringContainsString('has no ewallet.pem', $exception->getMessage());
        self::assertStringContainsString('openssl pkcs12 -in', $exception->getMessage());
        self::assertStringContainsString('-out ', $exception->getMessage());
    }

    #[Test]
    public function a_wallet_location_without_any_wallet_is_a_configuration_error(): void
    {
        $exception = $this->rejected('tcps://' . self::NOWHERE, walletLocation: $this->tnsnames([]));

        self::assertStringContainsString('No ewallet.pem', $exception->getMessage());
    }

    /**
     * Pool and lifecycle 10 and Scope 7: with proxyUser, `user` logs on and
     * the Session belongs to the proxy client, pooled and standalone.
     */
    #[Test]
    #[DataProvider('pooledAndStandalone')]
    public function proxy_user_connects_as_the_proxy_client(bool $standalone): void
    {
        $user = strtoupper(TestDatabase::fromEnvironment()->user);
        $client = "{$user}_CLIENT";

        $connection = $this->connect(proxyUser: $client, standalone: $standalone, connectTimeout: self::ownPool()['connectTimeout']);

        self::assertSame([['SESSION_USER' => $client, 'PROXY_USER' => $user]], self::rows(
            $connection,
            "select sys_context('userenv', 'session_user') as session_user, sys_context('userenv', 'proxy_user') as proxy_user from dual",
        ));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function drcpTargets(): iterable
    {
        yield ':pooled, pooled' => [':pooled', false];
        yield ':pooled, standalone' => [':pooled', true];
        yield '(SERVER=POOLED), pooled' => ['(SERVER=POOLED)', false];
        yield '(SERVER=POOLED), standalone' => ['(SERVER=POOLED)', true];
    }

    /**
     * Scope 7: DRCP through the dsn. The Session runs on a pooled server and
     * gets the Session setup (no ORA-03301 line carrying values in
     * ORA-00001; spec #32 amendment to §11 / §12).
     */
    #[Test]
    #[DataProvider('drcpTargets')]
    public function drcp_connects_through_the_dsn(string $how, bool $standalone): void
    {
        $easy = TestDatabase::fromEnvironment()->connectString;
        $dsn = $how === ':pooled'
            ? "$easy:pooled"
            : str_replace('(SERVICE_NAME=', '(SERVER=POOLED)(SERVICE_NAME=', ConnectTargets::descriptor($easy));

        $connection = $this->connect(dsn: $dsn, standalone: $standalone, connectTimeout: self::ownPool()['connectTimeout']);

        self::assertSame('POOLED', self::value($connection, "select server from v\$session where sid = sys_context('userenv', 'sid')"));
        try {
            $connection->execute('insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)', ['id' => 41, 'code' => 'DRCP41a'], self::options(autocommit: false));
            $connection->execute('insert into fx_batch_rows (id, code, qty) values (:id, :code, 1)', ['id' => 41, 'code' => 'DRCP41b'], self::options(autocommit: false));
            self::fail('The duplicate insert succeeded.');
        } catch (UniqueViolationException $e) {
            self::assertStringNotContainsString('ORA-03301', $e->getMessage());
            self::assertStringNotContainsString('DRCP41', $e->getMessage());
        }
    }

    /**
     * Pool and lifecycle 11: two processes with no drcpConnectionClass
     * report the same connection class in V$CPOOL_CC_STATS: one derived
     * from the user, not a random one per process.
     */
    #[Test]
    public function two_processes_without_a_class_share_one_drcp_connection_class(): void
    {
        $user = strtoupper(TestDatabase::fromEnvironment()->user);
        $before = self::drcpClasses($user);

        $classes = [];
        for ($process = 0; $process < 2; $process++) {
            self::runChild(TestDatabase::fromEnvironment()->connectString . ':pooled');
            $after = self::drcpClasses($user);
            $classes[] = array_keys(array_filter($after, static fn(int $requests, string $class): bool => $requests > ($before[$class] ?? 0), ARRAY_FILTER_USE_BOTH));
            $before = $after;
        }

        self::assertSame([["$user.RAJSKA:{$this->user()}"], ["$user.RAJSKA:{$this->user()}"]], $classes);
    }

    /**
     * Pool and lifecycle 11: drcpConnectionClass, when given, is the class.
     */
    #[Test]
    public function drcp_connection_class_names_the_class(): void
    {
        $user = strtoupper(TestDatabase::fromEnvironment()->user);
        $before = self::drcpClasses($user);

        $connection = $this->connect(dsn: TestDatabase::fromEnvironment()->connectString . ':pooled', drcpConnectionClass: 'RAJSKA41', standalone: true);
        self::value($connection, 'select 1 from dual');

        $after = self::drcpClasses($user);
        self::assertGreaterThan($before["$user.RAJSKA41"] ?? 0, $after["$user.RAJSKA41"] ?? 0);
    }

    /**
     * Pool and lifecycle 12: `password` and `walletPassword` never appear in
     * `var_dump()` or `print_r()` of the config, nor in the message, string
     * or trace of a failing connect.
     */
    #[Test]
    public function secrets_never_appear_in_dumps_messages_or_traces(): void
    {
        $password = 'Pa55-' . bin2hex(random_bytes(6));
        $walletPassword = 'Wa11et-' . bin2hex(random_bytes(6));
        $config = TestDatabase::fromEnvironment()->config(password: $password, walletPassword: $walletPassword, standalone: true);

        ob_start();
        var_dump($config);
        $dumped = ob_get_clean() . print_r($config, true);

        self::assertStringContainsString('SensitiveParameterValue', $dumped);
        self::assertStringContainsString(TestDatabase::fromEnvironment()->user, $dumped);
        $exception = $this->failedConnect($config);
        self::assertSame(1017, $exception->getCode());

        foreach ([$dumped, $exception->getMessage(), (string) $exception, $exception->getTraceAsString()] as $text) {
            self::assertStringNotContainsString($password, $text);
            self::assertStringNotContainsString($walletPassword, $text);
        }
    }

    /**
     * Pool and lifecycle 12: the adapter keeps what it built from a config
     * only while the config lives, so its password is not held for the rest
     * of the worker's life once the application lets the config go.
     */
    #[Test]
    public function the_adapter_holds_a_configs_password_no_longer_than_the_config(): void
    {
        $password = 'Pa55-' . bin2hex(random_bytes(6));
        $config = TestDatabase::fromEnvironment()->config(password: $password, standalone: true);
        self::assertSame(1017, $this->failedConnect($config)->getCode());
        self::assertTrue(self::adapterHolds($password), 'kept while the config lives');

        unset($config);
        gc_collect_cycles();

        self::assertFalse(self::adapterHolds($password));
    }

    /**
     * Whether any static property of the adapter holds `$secret`, in an
     * array or a WeakMap, at any depth.
     */
    private static function adapterHolds(string $secret): bool
    {
        $holds = static function (mixed $value) use (&$holds, $secret): bool {
            if ($value === $secret) {
                return true;
            }
            if (\is_array($value) || $value instanceof \WeakMap) {
                foreach ($value as $element) {
                    if ($holds($element)) {
                        return true;
                    }
                }
            }

            return false;
        };

        return $holds(new \ReflectionClass(ExtensionDriver::class)->getStaticProperties());
    }

    /**
     * Pool and lifecycle 9 and 12: a wrong walletPassword is a configuration
     * error, and neither secret shows in it.
     */
    #[Test]
    public function a_wrong_wallet_password_is_a_configuration_error_that_shows_no_secret(): void
    {
        $password = TestDatabase::fromEnvironment()->password;
        $walletPassword = 'Wa11et-' . bin2hex(random_bytes(6));

        $exception = $this->failedConnect(TestDatabase::fromEnvironment()->config(
            dsn: ConnectTargets::variable('ORACLE_MTLS_CONNECT_STRING'),
            walletLocation: ConnectTargets::wallet('mtls-encrypted'),
            walletPassword: $walletPassword,
            standalone: true,
        ));

        self::assertInstanceOf(ConfigurationException::class, $exception);
        self::assertStringContainsStringIgnoringCase('wallet', $exception->getMessage());
        foreach ([$exception->getMessage(), (string) $exception, $exception->getTraceAsString()] as $text) {
            self::assertStringNotContainsString($password, $text);
            self::assertStringNotContainsString($walletPassword, $text);
        }
    }

    private function failedConnect(ConnectionConfig $config): \Throwable
    {
        try {
            $this->driver()->connect($config)->close();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('connect() succeeded.');
    }

    /**
     * Connects and expects a ConfigurationException before any network I/O.
     */
    private function rejected(string $dsn, mixed ...$fields): ConfigurationException
    {
        $started = microtime(true);
        try {
            $this->connect(...['dsn' => $dsn, 'standalone' => true, ...$fields]);
            self::fail("connect() accepted the dsn $dsn.");
        } catch (ConfigurationException $e) {
            self::assertLessThan(1.0, microtime(true) - $started, 'The check waited for the network.');

            return $e;
        }
    }

    /**
     * A new directory holding a tnsnames.ora with these entries.
     *
     * @param array<string, string> $entries
     */
    private function tnsnames(array $entries): string
    {
        $directory = sys_get_temp_dir() . '/rajska-tns-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $this->directories[] = $directory;
        $text = "# Written by ConnectParametersTest.\n";
        foreach ($entries as $names => $value) {
            $text .= "$names =\n  $value\n\n";
        }
        file_put_contents("$directory/tnsnames.ora", $text);

        return $directory;
    }

    private function user(): string
    {
        return TestDatabase::fromEnvironment()->user;
    }

    private static function protocol(DriverConnection $connection): mixed
    {
        return self::value($connection, "select sys_context('userenv', 'network_protocol') from dual");
    }

    /**
     * Requests per DRCP connection class of this user, read from the CDB
     * root.
     *
     * @return array<string, int>
     */
    private static function drcpClasses(string $user): array
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
    private static function runChild(string $dsn): void
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
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
        self::assertSame('connected', $output);
    }
}
