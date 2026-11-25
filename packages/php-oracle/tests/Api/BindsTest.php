<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use Rajska\Oracle\Execution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Bind\Boolean;
use Rajska\Oracle\Bind\Number;
use Rajska\Oracle\Bind\PlsInteger;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\Binds\Priority;
use Rajska\Oracle\Tests\Support\TestDatabase;

/**
 * Plain PHP values bind with no ceremony; anything ambiguous fails early,
 * naming the fix.
 */
final class BindsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function plain_values_bind_by_name_case_insensitively(): void
    {
        self::assertSame(1, $this->connection->select(
            'select count(*) from fx_items where id = :Id and :price = 0.1 and :priority = 2 and :flag and :nothing is null',
            ['id' => 1, 'PRICE' => 0.1, 'priority' => Priority::High, 'flag' => true, 'nothing' => null],
        )->value());
    }

    #[Test]
    public function a_date_fails_naming_the_typed_binds(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("Bind 'at': a DateTimeInterface does not bind by itself; use Date::in() or Timestamp::in().");

        $this->connection->select('select :at from dual', ['at' => new \DateTimeImmutable()]);
    }

    /**
     * Errors 5, for the typed binds: a value a factory rejects never
     * appears in the exception's trace or string form, though the suite
     * keeps exceptions' arguments (zend.exception_ignore_args=Off).
     *
     * @return iterable<string, array{\Closure(string): mixed}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'Number::in(), not a decimal literal' => [static fn (#[\SensitiveParameter] string $secret): mixed => Number::in("1e5$secret")];
        yield 'Number::inOut(), not a decimal literal' => [static fn (#[\SensitiveParameter] string $secret): mixed => Number::inOut("1e5$secret")];
        // @phpstan-ignore argument.type (a caller without static analysis)
        yield 'Boolean::in(), a string' => [static fn (#[\SensitiveParameter] string $secret): mixed => Boolean::in($secret)];
        // @phpstan-ignore argument.type (a caller without static analysis)
        yield 'PlsInteger::inOut(), a string' => [static fn (#[\SensitiveParameter] string $secret): mixed => PlsInteger::inOut($secret)];
    }

    /**
     * @param \Closure(string): mixed $factory
     */
    #[Test]
    #[DataProvider('rejectedValues')]
    public function a_value_a_typed_bind_rejects_never_appears_in_its_exception(\Closure $factory): void
    {
        self::assertSame('0', \ini_get('zend.exception_ignore_args'), 'the suite keeps exceptions\' arguments');
        $secret = 'zq32secret';

        try {
            $factory($secret);
            self::fail('The value was accepted.');
        } catch (UsageException|\TypeError $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, $e->getTraceAsString());
            self::assertStringNotContainsString($secret, (string) $e);
        }
    }

    /**
     * Errors 5, for run(): a distinctive bound value never appears in the
     * message, `sql()`, the trace string or the exception as a string, for
     * a server error and for a bind refused before any Round trip.
     */
    #[Test]
    public function a_bound_value_never_appears_in_a_run_error(): void
    {
        self::assertSame('0', \ini_get('zend.exception_ignore_args'), 'the suite keeps exceptions\' arguments');
        $secret = 'zq32secret';
        $calls = [
            'server error' => fn (): Execution => $this->connection->run('begin :n := to_number(:text); end;', ['n' => Number::out(), 'text' => $secret]),
            'typed bind, server error' => fn (): Execution => $this->connection->run('begin :n := to_number(:text); end;', ['n' => Number::out(), 'text' => Varchar::in($secret)]),
            'refused bind' => fn (): Execution => $this->connection->run('begin :n := :text; end;', ['n' => Number::out(), 'text' => [$secret]]),
        ];
        foreach ($calls as $kind => $call) {
            try {
                $call();
                self::fail("$kind: '$secret' was taken for a number.");
            } catch (OracleException $e) {
                self::assertStringNotContainsString($secret, $e->getMessage(), $kind);
                self::assertStringNotContainsString($secret, (string) $e->sql(), $kind);
                self::assertStringNotContainsString($secret, $e->getTraceAsString(), $kind);
                self::assertStringNotContainsString($secret, (string) $e, $kind);
                if ($kind !== 'refused bind') {
                    self::assertInstanceOf(QueryException::class, $e, $kind);
                }
            }
        }
    }

    #[Test]
    public function a_list_of_binds_fails_naming_the_named_form(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("key every value by its placeholder name without ':'");

        // @phpstan-ignore argument.type (static analysis already flags a list)
        $this->connection->select('select * from fx_items where id = :id', [1]);
    }
}
