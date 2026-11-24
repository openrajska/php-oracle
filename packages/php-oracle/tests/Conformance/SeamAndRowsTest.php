<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\KeyCase;
use Rajska\Oracle\RowShape;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\TestDatabase;

/**
 * Conformance catalog, Seam and rows (#20, #24).
 */
final class SeamAndRowsTest extends ConformanceTestCase
{
    /**
     * Seam and rows 6: the server sees placeholders, and the values travel
     * as binds, for a query, DML and an Anonymous block alike.
     */
    #[Test]
    public function bind_values_never_reach_the_sql_text(): void
    {
        $tag = 'sql-text-'.bin2hex(random_bytes(6));
        $value = 'bound-value-'.bin2hex(random_bytes(6));
        $statements = [
            "select :v /* $tag */ from dual",
            "update fx_items set name = name where name = :v /* $tag */",
            "declare x varchar2(100) := :v; /* $tag */ begin null; end;",
        ];

        $connection = $this->connect();
        foreach ($statements as $sql) {
            $connection->execute($sql, ['v' => $value], self::options());
        }

        $observer = IndependentSession::open();
        try {
            $texts = array_column(
                $observer->select('select sql_text from v$sql where instr(sql_text, :tag) > 0', ['tag' => $tag]),
                'SQL_TEXT',
            );
            $leaks = $observer->select('select sql_text from v$sql where instr(sql_text, :value) > 0', ['value' => $value]);
        } finally {
            $observer->close();
        }

        foreach ($statements as $sql) {
            self::assertContains($sql, $texts);
        }
        self::assertSame([], $leaks);
    }

    /**
     * Seam and rows 1 (Calls come with `procedure()`): every statement kind
     * returns a complete Execution; the count is the DML count, else 0.
     */
    #[Test]
    public function every_statement_kind_returns_a_complete_execution(): void
    {
        $connection = $this->connect();
        $table = 'tmp_kinds_'.bin2hex(random_bytes(4));
        $kinds = [
            'DDL' => ["create table $table (id number)", 0, false],
            'DML' => ["insert into $table select level from dual connect by level <= 3", 3, false],
            'query' => ["select id from $table", 0, true],
            'Anonymous block' => ["begin update $table set id = id + 1; end;", 0, false],
            'DDL again' => ["drop table $table purge", 0, false],
        ];

        foreach ($kinds as $kind => [$sql, $count, $query]) {
            $execution = $connection->execute($sql, [], self::options());
            self::assertSame($count, $execution->rowCount(), $kind);
            self::assertSame($query, $execution->cursor() !== null, $kind);
            self::assertSame([], $execution->outValues(), $kind);
            self::assertSame([], $execution->implicitResults(), $kind);
            self::assertNull($execution->warning(), $kind);
        }
    }

    /**
     * Seam and rows 3.
     */
    #[Test]
    public function native_keys_are_as_oracle_reports_them_lower_keys_are_lowercase_and_list_rows_follow_column_order(): void
    {
        $connection = $this->connect();
        $sql = 'select 1 as one, 2 as "Two", 3 as "three" from dual';

        self::assertSame([['ONE' => 1, 'Two' => 2, 'three' => 3]], self::rows($connection, $sql));
        self::assertSame(
            [['one' => 1, 'two' => 2, 'three' => 3]],
            self::rows($connection, $sql, options: self::options(keyCase: KeyCase::Lower)),
        );
        self::assertSame(
            [[1, 2, 3]],
            self::rows($connection, $sql, options: self::options(rowShape: RowShape::List)),
        );
    }

    /**
     * Seam and rows 4: under Assoc, names that collide (as Oracle reports
     * them, or once lowercased) fail when the Cursor opens; List does not
     * mind them.
     */
    #[Test]
    public function duplicate_column_names_under_assoc_fail_at_cursor_open_with_a_hint(): void
    {
        $connection = $this->connect();
        $cases = [
            'select 1 as a, 2 as a from dual' => self::options(),
            'select 1 as a, 2 as "a" from dual' => self::options(keyCase: KeyCase::Lower),
        ];

        foreach ($cases as $sql => $options) {
            try {
                $connection->execute($sql, [], $options);
                self::fail("$sql opened a Cursor.");
            } catch (UsageException $e) {
                self::assertStringContainsString('alias', $e->getMessage());
                self::assertStringContainsString('RowShape::List', $e->getMessage());
                self::assertSame($sql, $e->sql());
            }
        }

        self::assertSame(
            [[1, 2]],
            self::rows($connection, 'select 1 as a, 2 as a from dual', options: self::options(rowShape: RowShape::List)),
        );
    }

    /**
     * Seam and rows 21 and Native surface 10: every field of every column,
     * before the first row and for an empty result, with exactly Column's
     * keys and enums as backing values.
     */
    #[Test]
    public function columns_describe_every_field_before_the_first_row_also_for_an_empty_result(): void
    {
        $connection = $this->connect();
        $schema = strtoupper(TestDatabase::fromEnvironment()->user);
        $cursor = $connection->execute(
            <<<'SQL'
                select i.id, i.price, i.name, i.created_at, i.rowid,
                       cast(null as number) unconstrained, cast(null as float(53)) float53,
                       cast(null as char(5)) ch, cast(null as nchar(5)) nch, cast(null as nvarchar2(10)) nv,
                       cast(null as raw(16)) r,
                       cast(null as timestamp(3)) ts, cast(null as timestamp with time zone) tstz,
                       cast(null as timestamp(9) with local time zone) tsltz,
                       cast(null as interval day(3) to second(2)) ids, cast(null as interval year(4) to month) iym,
                       cast(null as binary_float) bf, cast(null as binary_double) bd, true b,
                       cast(null as urowid) ur, empty_clob() cl, to_nclob('') ncl, empty_blob() bl
                  from fx_items i
                 where 1 = 0
                SQL,
            [],
            self::options(),
        )->cursor();
        self::assertNotNull($cursor);

        self::assertSame([
            self::column('ID', 'number', nullable: false, precision: 10, scale: 0),
            self::column('PRICE', 'number', nullable: false, precision: 10, scale: 2),
            self::column('NAME', 'varchar', nullable: false, maxSize: 100),
            self::column('CREATED_AT', 'date', nullable: false),
            self::column('ROWID', 'rowid', nullable: false),
            self::column('UNCONSTRAINED', 'number'),
            self::column('FLOAT53', 'number', precision: 53),
            self::column('CH', 'char', maxSize: 5),
            self::column('NCH', 'nChar', maxSize: 5),
            self::column('NV', 'nVarchar', maxSize: 10),
            self::column('R', 'raw', maxSize: 16),
            self::column('TS', 'timestamp', scale: 3),
            self::column('TSTZ', 'timestampTz', scale: 6),
            self::column('TSLTZ', 'timestampLtz', scale: 9),
            self::column('IDS', 'intervalDs', precision: 3, scale: 2),
            self::column('IYM', 'intervalYm', precision: 4),
            self::column('BF', 'binaryFloat'),
            self::column('BD', 'binaryDouble'),
            self::column('B', 'boolean'),
            self::column('UR', 'urowid'),
            self::column('CL', 'clob'),
            self::column('NCL', 'nClob'),
            self::column('BL', 'blob'),
        ], $cursor->columns());
        self::assertSame([], $cursor->fetchBatch());

        $cursor = $connection->execute(
            <<<'SQL'
                select cast(null as json) j,
                       to_vector('[1,2,3]', 3, float32) v, to_vector('[1,2]', *, *) flexible,
                       to_vector('[10,[1],[1.0]]', 10, float64, sparse) sparse,
                       fx_point(1, 2, 'p') point, fx_number_list(1, 2) numbers,
                       cursor(select 1 from dual) nested, bfilename('NO_SUCH_DIRECTORY', 'x') locator
                  from dual
                SQL,
            [],
            self::options(),
        )->cursor();
        self::assertNotNull($cursor);

        self::assertSame([
            self::column('J', 'json'),
            self::column('V', 'vector', vectorDimensions: 3, vectorFormat: 'float32'),
            self::column('FLEXIBLE', 'vector'),
            self::column('SPARSE', 'vector', vectorDimensions: 10, vectorFormat: 'float64', sparse: true),
            self::column('POINT', 'object', objectType: "$schema.FX_POINT"),
            self::column('NUMBERS', 'collection', objectType: "$schema.FX_NUMBER_LIST"),
            self::column('NESTED', 'cursor'),
            self::column('LOCATOR', 'bfile'),
        ], $cursor->columns());
        $cursor->close();
    }

    /**
     * Seam and rows 21: LONG and LONG RAW are column-only types, and Lower
     * applies to the names as it does to the row keys.
     */
    #[Test]
    public function columns_describe_long_columns_and_follow_the_key_case(): void
    {
        $connection = $this->connect();
        // A table holds one LONG or LONG RAW column at most.
        $suffix = bin2hex(random_bytes(4));
        $connection->execute("create table tmp_long_$suffix (text long)", [], self::options());

        try {
            $connection->execute("create table tmp_long_raw_$suffix (bytes long raw)", [], self::options());
            try {
                $cursor = $connection->execute(
                    "select t.text, b.bytes from tmp_long_$suffix t cross join tmp_long_raw_$suffix b",
                    [],
                    self::options(keyCase: KeyCase::Lower),
                )->cursor();
                self::assertNotNull($cursor);
                self::assertSame([self::column('text', 'long'), self::column('bytes', 'longRaw')], $cursor->columns());
                $cursor->close();
            } finally {
                $connection->execute("drop table tmp_long_raw_$suffix purge", [], self::options());
            }
        } finally {
            $connection->execute("drop table tmp_long_$suffix purge", [], self::options());
        }
    }

    /**
     * Seam and rows 21: a BFILE column is described, and only fetching it
     * fails.
     */
    #[Test]
    public function a_bfile_column_is_described_and_only_fetching_it_fails(): void
    {
        $cursor = $this->connect()->execute("select bfilename('NO_SUCH_DIRECTORY', 'x') f from dual", [], self::options())->cursor();
        self::assertNotNull($cursor);

        self::assertSame('bfile', $cursor->columns()[0]['type']);
        $this->expectException(UnsupportedException::class);
        $cursor->fetchBatch();
    }

    /**
     * Seam and rows 12, for query Cursors: a Cursor of a closed Connection
     * fails as use after close, and the Session's next owner reads normally.
     */
    #[Test]
    public function after_close_the_connections_cursors_fail_as_use_after_close_and_the_next_owner_is_unaffected(): void
    {
        $fields = self::ownPool() + ['maxConnections' => 1];
        $connection = $this->connect(...$fields);
        $session = self::session($connection);
        $sql = 'select level as n from dual connect by level <= 3';
        $cursor = $connection->execute($sql, [], self::options(fetchSize: 1))->cursor();
        self::assertNotNull($cursor);
        self::assertSame([['N' => 1]], $cursor->fetchBatch());

        $connection->close();

        foreach (['fetchBatch()' => static fn () => $cursor->fetchBatch(), 'columns()' => static fn () => $cursor->columns()] as $call => $read) {
            try {
                $read();
                self::fail("$call after close() succeeded.");
            } catch (UsageException $e) {
                self::assertSame('The Connection is closed.', $e->getMessage(), $call);
            }
        }
        $cursor->close();

        $next = $this->connect(...$fields);
        self::assertSame($session, self::session($next));
        self::assertSame([['N' => 1], ['N' => 2], ['N' => 3]], self::rows($next, $sql, [], self::options(fetchSize: 1)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function column(
        string $name,
        string $type,
        bool $nullable = true,
        ?int $precision = null,
        ?int $scale = null,
        ?int $maxSize = null,
        ?string $objectType = null,
        ?int $vectorDimensions = null,
        ?string $vectorFormat = null,
        bool $sparse = false,
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
            'precision' => $precision,
            'scale' => $scale,
            'maxSize' => $maxSize,
            'objectType' => $objectType,
            'vectorDimensions' => $vectorDimensions,
            'vectorFormat' => $vectorFormat,
            'sparse' => $sparse,
        ];
    }
}
