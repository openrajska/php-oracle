<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Exceptions\BatchException;
use Rajska\Oracle\Exceptions\ConfigurationException;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Exceptions\ConnectionLostException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\PoolExhaustedException;
use Rajska\Oracle\Exceptions\QueryException;
use Rajska\Oracle\Exceptions\TimeoutException;
use Rajska\Oracle\Exceptions\UniqueViolationException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * Conformance catalog, Errors (#27).
 */
final class ErrorsTest extends ConformanceTestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function hierarchy(): iterable
    {
        yield 'QueryException' => [QueryException::class, \RuntimeException::class];
        yield 'UniqueViolationException' => [UniqueViolationException::class, QueryException::class];
        yield 'BatchException' => [BatchException::class, QueryException::class];
        yield 'ConnectionException' => [ConnectionException::class, \RuntimeException::class];
        yield 'PoolExhaustedException' => [PoolExhaustedException::class, ConnectionException::class];
        yield 'ConnectionLostException' => [ConnectionLostException::class, ConnectionException::class];
        yield 'TimeoutException' => [TimeoutException::class, \RuntimeException::class];
        yield 'UnsupportedException' => [UnsupportedException::class, \RuntimeException::class];
        yield 'ConfigurationException' => [ConfigurationException::class, \LogicException::class];
        yield 'UsageException' => [UsageException::class, \LogicException::class];
    }

    /**
     * Errors 1.
     *
     * @param class-string $class
     * @param class-string $parent
     */
    #[Test]
    #[DataProvider('hierarchy')]
    public function every_exception_implements_oracle_exception_under_its_spl_parent(string $class, string $parent): void
    {
        self::assertTrue(is_subclass_of($class, OracleException::class), "$class implements OracleException");
        self::assertTrue(is_subclass_of($class, $parent), "$class extends $parent");
    }

    /**
     * Errors 2.
     */
    #[Test]
    public function a_duplicate_gives_unique_violation_exception(): void
    {
        $sql = "insert into fx_items (id, category_id, name, price, created_at) values (1, 1, 'duplicate', 1, sysdate)";

        try {
            $this->connect()->execute($sql, [], self::options());
            self::fail('The duplicate was inserted.');
        } catch (UniqueViolationException $e) {
            self::assertSame(1, $e->getCode());
            self::assertStringStartsWith('ORA-00001', $e->getMessage());
            self::assertSame($sql, $e->sql());
            self::assertNull($e->call());
        }
    }

    /**
     * Errors 3. The expected offsets are the ones oci8 reports for the same
     * statements.
     */
    #[Test]
    public function a_syntax_error_gives_query_exception_with_the_server_offset(): void
    {
        $connection = $this->connect();

        foreach (['select 1 fro dual' => [923, 13], 'select nosuchcolumn from dual' => [904, 7]] as $sql => [$code, $offset]) {
            try {
                $connection->execute($sql, [], self::options());
                self::fail("$sql ran.");
            } catch (QueryException $e) {
                self::assertSame(QueryException::class, $e::class);
                self::assertSame($code, $e->getCode());
                self::assertSame($offset, $e->offset());
                self::assertSame($sql, $e->sql());
            }
        }
    }
}
