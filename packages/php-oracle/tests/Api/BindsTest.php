<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Connection;
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

    #[Test]
    public function a_list_of_binds_fails_naming_the_named_form(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("key every value by its placeholder name without ':'");

        // @phpstan-ignore argument.type (static analysis already flags a list)
        $this->connection->select('select * from fx_items where id = :id', [1]);
    }
}
