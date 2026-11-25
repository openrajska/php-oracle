<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Bind\Bind;
use Rajska\Oracle\Bind\Cursor;
use Rajska\Oracle\Bind\NVarchar;
use Rajska\Oracle\Bind\Raw;
use Rajska\Oracle\Bind\Struct;
use Rajska\Oracle\Bind\Varchar;
use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

final class ScopeTest extends ConformanceTestCase
{
    private static ?RoundTripCounter $counter = null;

    public static function tearDownAfterClass(): void
    {
        self::$counter?->close();
        self::$counter = null;
        parent::tearDownAfterClass();
    }

    #[Test]
    public function long_and_long_raw_writes_up_to_32767_bytes_round_trip(): void
    {
        $connection = $this->connect();
        $written = self::options(autocommit: false);
        foreach ([1, 4000, 32767] as $bytes) {
            $text = str_repeat('ç', intdiv($bytes, 2)) . str_repeat('x', $bytes % 2);
            $binary = substr(str_repeat("\x00\xff\x7f", $bytes), 0, $bytes);
            $connection->execute('insert into fx_long_rows (id, text) values (:id, :text)', ['id' => 100 + $bytes, 'text' => Varchar::in($text)], $written);
            $connection->execute('insert into fx_long_rows (id, text) values (:id, :text)', ['id' => 200 + $bytes, 'text' => $text], $written);
            $connection->execute('insert into fx_long_raw_rows (id, bytes) values (:id, :bytes)', ['id' => 100 + $bytes, 'bytes' => Raw::in($binary)], $written);

            self::assertSame(
                [['TEXT' => $text], ['TEXT' => $text]],
                self::rows($connection, 'select text from fx_long_rows where id in (:a, :b)', ['a' => 100 + $bytes, 'b' => 200 + $bytes], $written),
                "$bytes bytes",
            );
            self::assertSame(
                [['BYTES' => $binary]],
                self::rows($connection, 'select bytes from fx_long_raw_rows where id = :id', ['id' => 100 + $bytes], $written),
                "$bytes bytes",
            );
        }
        $connection->rollback();
    }

    /**
     * Writing more than 32767 bytes into a LONG or LONG RAW column takes a
     * LONG bind, which is later.
     *
     * @return iterable<string, array{Bind, string}>
     */
    public static function longBinds(): iterable
    {
        yield 'Varchar' => [Varchar::in(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
        yield 'NVarchar' => [NVarchar::in(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
        yield 'Varchar IN OUT' => [Varchar::inOut(str_repeat('a', 32768)), 'over 32767 bytes; bind it with Clob::in()'];
        yield 'Raw' => [Raw::in(str_repeat("\x00", 32768)), 'over 32767; bind them with Blob::in()'];
    }

    #[Test]
    #[DataProvider('longBinds')]
    public function a_long_bind_over_32767_bytes_is_unsupported_before_any_round_trip(Bind $bind, string $fix): void
    {
        $connection = $this->connect();
        $sid = self::sid($connection);

        $roundTrips = self::counter()->during($sid, function () use ($connection, $bind, $fix): void {
            try {
                $connection->execute('insert into fx_long_rows (id, text) values (1000, :v)', ['v' => $bind], self::options(autocommit: false));
                self::fail('A LONG bind over 32767 bytes ran.');
            } catch (UnsupportedException $e) {
                self::assertStringContainsString("Bind 'v'", $e->getMessage());
                self::assertStringContainsString($fix, $e->getMessage());
                self::assertStringContainsString('more than 32767 bytes', $e->getMessage());
            }
        });

        self::assertSame(0, $roundTrips);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function unfetchable(): iterable
    {
        yield 'BFILE' => ["select bfilename('NO_SUCH_DIRECTORY', 'x') v from dual", 'bfile', null];
        yield 'XMLTYPE' => ["select xmltype('<a>1</a>') v from dual", 'xmltype', null];
        yield 'XMLTYPE, NULL' => ["select case when 1 = 0 then xmltype('<a>1</a>') end v from dual", 'xmltype', null];
        yield 'SYS.ANYDATA' => ['select sys.anydata.convertnumber(5) v from dual', 'object', 'SYS.ANYDATA'];
    }

    #[Test]
    #[DataProvider('unfetchable')]
    public function a_later_or_never_column_is_described_and_only_fetching_it_fails(string $sql, string $type, ?string $objectType): void
    {
        $connection = $this->connect();
        foreach ([$sql, "$sql where 1 = 0"] as $query) {
            $cursor = $connection->execute($query, [], self::options())->cursor();
            self::assertNotNull($cursor);
            self::assertSame(['V', $type, $objectType], [$cursor->columns()[0]['name'], $cursor->columns()[0]['type'], $cursor->columns()[0]['objectType']]);
            if ($query !== $sql) {
                // No value, nothing to fail.
                self::assertSame([], $cursor->fetchBatch());
                continue;
            }
            self::assertFetchFails($cursor, $type === 'object' ? 'SYS.ANYDATA' : strtoupper($type));
        }

        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    #[Test]
    public function an_xmltype_column_fails_on_fetch_in_every_cursor_kind(): void
    {
        $connection = $this->connect();

        $out = $connection->execute(
            "begin open :c for select xmltype('<a>1</a>') v from dual; end;",
            ['c' => Cursor::out()],
            self::options(),
        )->outValues()['c'][0];
        self::assertInstanceOf(DriverCursor::class, $out);
        self::assertSame('xmltype', $out->columns()[0]['type']);
        self::assertFetchFails($out, 'XMLTYPE');

        $parent = $connection->execute("select cursor(select xmltype('<a>1</a>') v from dual) nested from dual", [], self::options())->cursor();
        self::assertNotNull($parent);
        $nested = $parent->fetchBatch()[0]['NESTED'];
        self::assertInstanceOf(DriverCursor::class, $nested);
        self::assertSame('xmltype', $nested->columns()[0]['type']);
        self::assertFetchFails($nested, 'XMLTYPE');
        $parent->close();

        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    /** SYS.ANYDATA is never supported. */
    #[Test]
    public function anydata_fails_loudly_and_null_stays_null(): void
    {
        $connection = $this->connect();

        self::assertSame([['V' => null]], self::rows($connection, 'select case when 1 = 0 then sys.anydata.convertnumber(5) end v from dual'));

        try {
            self::rows($connection, 'select sys.anydata.convertvarchar2(:v) v from dual', ['v' => 'secret']);
            self::fail('An ANYDATA value was fetched.');
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('Column V', $e->getMessage());
            self::assertStringContainsString('SYS.ANYDATA', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }

        foreach (['IN' => Struct::in('SYS.ANYDATA', []), 'OUT' => Struct::out('sys.anydata')] as $direction => $bind) {
            try {
                $connection->execute('begin :v := :v; end;', ['v' => $bind], self::options());
                self::fail("An ANYDATA $direction bind ran.");
            } catch (UnsupportedException $e) {
                self::assertStringContainsString("Bind 'v'", $e->getMessage(), $direction);
                self::assertStringContainsString('SYS.ANYDATA is an opaque type', $e->getMessage(), $direction);
            }
        }
        self::assertSame(1, self::value($connection, 'select 1 from dual'));
    }

    private static function assertFetchFails(DriverCursor $cursor, string $type): void
    {
        try {
            $rows = $cursor->fetchBatch();
            self::fail('A value was fetched: ' . var_export($rows, true));
        } catch (UnsupportedException $e) {
            self::assertStringContainsString('Column V', $e->getMessage());
            self::assertStringContainsString($type, $e->getMessage());
        } finally {
            $cursor->close();
        }
    }

    private static function counter(): RoundTripCounter
    {
        return self::$counter ??= RoundTripCounter::open();
    }
}
