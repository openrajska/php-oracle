<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Support;

/**
 * The statements of a SQL*Plus script (fixtures/sql), as sqlplus sends them:
 * `@@` includes are followed and SQL*Plus commands skipped; a SQL statement
 * ends at `;`, a PL/SQL unit or block at a line holding only `/`.
 */
final class SqlScript
{
    private const string PLSQL = '/^\s*(create\s+(or\s+replace\s+)?((non)?editionable\s+)?(package|type|procedure|function|trigger)\b|declare\b|begin\b)/i';

    private const string COMMAND = '/^\s*(set|whenever|prompt|exit|spool|define|rem)\b/i';

    /**
     * @return list<string>
     */
    public static function statements(string $path): array
    {
        $statements = [];
        $buffer = '';
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($buffer === '' && preg_match('/^\s*@@?(\S+)/', $line, $include) === 1) {
                array_push($statements, ...self::statements(\dirname($path) . '/' . $include[1]));

                continue;
            }
            if ($buffer === '' && self::skipped($line)) {
                continue;
            }
            $statement = self::append($buffer, $line);
            if ($statement !== null) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private static function skipped(string $line): bool
    {
        $trimmed = trim($line);

        return $trimmed === '' || $trimmed === '/' || str_starts_with($trimmed, '--') || preg_match(self::COMMAND, $line) === 1;
    }

    /**
     * Adds the line to the statement being read, and gives the statement
     * back once the line ends it.
     */
    private static function append(string &$buffer, string $line): string | null
    {
        $plsql = preg_match(self::PLSQL, $buffer === '' ? $line : $buffer) === 1;
        if ($plsql && trim($line) === '/') {
            [$statement, $buffer] = [$buffer, ''];

            return $statement;
        }
        $buffer .= $line . "\n";
        if ($plsql || !str_ends_with(rtrim($line), ';')) {
            return null;
        }
        [$statement, $buffer] = [rtrim(rtrim($buffer), ';'), ''];

        return $statement;
    }
}
