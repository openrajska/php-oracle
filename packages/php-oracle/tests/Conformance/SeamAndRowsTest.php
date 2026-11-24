<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;
use Rajska\Oracle\Tests\Support\IndependentSession;

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
}
