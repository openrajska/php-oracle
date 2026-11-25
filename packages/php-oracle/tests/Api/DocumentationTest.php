<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use Rajska\Oracle\Bind\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Oracle;
use Rajska\Oracle\Tests\Support\TestDatabase;

/**
 * Every example in Core's docs (README.md and docs/*.md) runs, so none can
 * rot: each fenced block opened with exactly ```php runs against the test
 * database with a fresh `$connection`, in the example schema below. A block
 * that cannot run here (a real host, a wallet, a file on disk) opens with
 * ```php no-run instead.
 *
 * A block gets `declare(strict_types=1)` and an import of every public
 * class of Core it does not import itself (`Cursor` is the bind class).
 */
final class DocumentationTest extends TestCase
{
    /**
     * The objects the examples use, dropped first and after the class.
     */
    private const array SCHEMA = [
        'create table customers (
            id number generated always as identity primary key,
            name varchar2(100) not null,
            email varchar2(200) unique,
            country char(2),
            tier char(10) default \'standard\' not null
        )',
        'create table orders (
            id number generated always as identity primary key,
            customer_id number not null references customers,
            total number(12, 2) not null,
            status varchar2(20) default \'open\' not null,
            placed_at date default sysdate not null
        )',
        'create table documents (
            id number generated always as identity primary key,
            title varchar2(200) not null,
            body clob,
            attachment blob,
            checksum raw(32)
        )',
        'create table events (
            id number generated always as identity primary key,
            happened_at timestamp with time zone not null,
            payload json
        )',
        'create or replace type address as object (street varchar2(100), city varchar2(100))',
        'create or replace type address_list as table of address',
        <<<'SQL'
            create or replace package billing as
                type amounts is table of number index by pls_integer;
                procedure close_month(month_start in date, closed out number, orders out sys_refcursor);
                function order_total(order_id in number) return number;
                procedure monthly_report;
                procedure apply_discounts(discounts in amounts, applied out number);
                procedure greet(name in varchar2);
            end billing;
            SQL,
        <<<'SQL'
            create or replace package body billing as
                procedure close_month(month_start in date, closed out number, orders out sys_refcursor) is
                begin
                    update orders set status = 'closed'
                     where status = 'open' and placed_at < add_months(month_start, 1);
                    closed := sql%rowcount;
                    open orders for select id, total from orders where status = 'closed' order by id;
                end;

                function order_total(order_id in number) return number is
                    result number;
                begin
                    select total into result from orders where id = order_id;
                    return result;
                end;

                procedure monthly_report is
                    customers_cursor sys_refcursor;
                    totals_cursor sys_refcursor;
                begin
                    open customers_cursor for select id, name from customers order by id;
                    dbms_sql.return_result(customers_cursor);
                    open totals_cursor for select status, sum(total) as total from orders group by status order by status;
                    dbms_sql.return_result(totals_cursor);
                end;

                procedure apply_discounts(discounts in amounts, applied out number) is
                begin
                    applied := discounts.count;
                end;

                procedure greet(name in varchar2) is
                begin
                    dbms_output.put_line('Hello, ' || name);
                end;
            end billing;
            SQL,
        "insert into customers (name, email, country, tier) values ('Ada Lovelace', 'ada@example.com', 'GB', 'gold')",
        "insert into customers (name, email, country) values ('Grace Hopper', 'grace@example.com', 'US')",
        "insert into orders (customer_id, total) select id, 120.50 from customers where name = 'Ada Lovelace'",
        "insert into orders (customer_id, total) select id, 99.90 from customers where name = 'Grace Hopper'",
    ];

    private const array DROP = [
        'drop procedure if exists unfinished_procedure',
        'drop package if exists billing',
        'drop type if exists address_list force',
        'drop type if exists address force',
        'drop table if exists events purge',
        'drop table if exists documents purge',
        'drop table if exists orders purge',
        'drop table if exists customers purge',
    ];

    public static function setUpBeforeClass(): void
    {
        self::runAll([...self::DROP, ...self::SCHEMA]);
    }

    public static function tearDownAfterClass(): void
    {
        self::runAll(self::DROP);
        parent::tearDownAfterClass();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        $root = \dirname(__DIR__, 2);
        $files = [$root . '/README.md', ...(glob($root . '/docs/*.md') ?: [])];
        foreach ($files as $file) {
            $markdown = (string) file_get_contents($file);
            // A block may be indented, inside a list item.
            preg_match_all('/^( *)```php\n(.*?)^\1```$/ms', $markdown, $blocks, PREG_OFFSET_CAPTURE);
            foreach ($blocks[2] as [$code, $offset]) {
                $line = substr_count($markdown, "\n", 0, $offset) + 1;
                yield substr($file, \strlen($root) + 1) . ':' . $line => [$code];
            }
        }
    }

    #[Test]
    #[DataProvider('examples')]
    public function the_example_runs(string $code): void
    {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config());
        $file = (string) tempnam(sys_get_temp_dir(), 'example');
        file_put_contents($file, self::script($code));
        ob_start();
        try {
            (static function (string $example, Connection $connection): void {
                require $example;
            })($file, $connection);
        } finally {
            ob_end_clean();
            unlink($file);
            $connection->close();
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The example as a PHP file: strict types, then the imports it lacks.
     */
    private static function script(string $code): string
    {
        preg_match_all('/^\s*use\s+[\w\\\\]*?(\w+)(?:\s+as\s+(\w+))?;/m', $code, $uses, PREG_SET_ORDER);
        $imported = array_map(static fn (array $use): string => ($use[2] ?? '') !== '' ? $use[2] : $use[1], $uses);
        $imports = '';
        foreach (self::publicClasses() as $short => $class) {
            if (!\in_array($short, $imported, true)) {
                $imports .= "use $class;\n";
            }
        }

        return "<?php\n\ndeclare(strict_types=1);\n\n$imports\n$code";
    }

    /**
     * Every public class of Core by short name, except the root `Cursor`
     * (an example names the bind class `Cursor` far more often).
     *
     * @return array<string, string>
     */
    private static function publicClasses(): array
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $classes = [];
        foreach (['' => '', 'Bind/' => 'Bind\\', 'Exceptions/' => 'Exceptions\\'] as $directory => $namespace) {
            foreach (glob("$src/{$directory}*.php") ?: [] as $file) {
                $short = basename($file, '.php');
                $classes[$short] ??= "Rajska\\Oracle\\$namespace$short";
            }
        }
        $classes['Cursor'] = Cursor::class;
        unset($classes['ConnectionState'], $classes['Call'], $classes['CallExecution']);

        return $classes;
    }

    /**
     * @param list<string> $statements
     */
    private static function runAll(array $statements): void
    {
        $connection = Oracle::connect(TestDatabase::fromEnvironment()->config(standalone: true));
        try {
            foreach ($statements as $statement) {
                $connection->run($statement);
            }
        } finally {
            $connection->close();
        }
    }
}
