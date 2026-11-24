<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverExecution;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * What one statement produced.
 */
final class Execution
{
    /**
     * @internal use Connection::run()
     *
     * @param list<string> $bindNames every bind's name as the user wrote it
     */
    public function __construct(
        private readonly DriverExecution $driver,
        private readonly string $sql,
        private readonly array $bindNames = [],
    ) {
    }

    /**
     * The INSERT / UPDATE / DELETE / MERGE count; 0 for anything else.
     */
    public function affected(): int
    {
        return $this->driver->rowCount();
    }

    /**
     * The value an OUT or IN OUT bind brought back, by the bind's name.
     *
     * @throws UsageException if no OUT or IN OUT bind has that name
     */
    public function out(string $name): OutValue
    {
        $values = $this->driver->outValues();
        $key = \array_key_exists($name, $values) ? $name : self::find($name, array_keys($values));
        if ($key !== null) {
            /** @var list<mixed> $list */
            $list = $values[$key];

            return new OutValue($key, $list, $this->sql);
        }

        $in = self::find($name, $this->bindNames);
        if ($in !== null) {
            throw new UsageException("Bind '$in' is IN only, so it has no OUT value; bind it with out() or inOut() of a typed bind to read it back.", 0, $this->sql);
        }
        $names = $values === [] ? 'the statement has none' : "they are '".implode("', '", array_keys($values))."'";

        throw new UsageException("No OUT bind is named '$name'; $names.", 0, $this->sql);
    }

    /**
     * The rows of a query.
     *
     * @throws UsageException if the statement was not a query
     */
    public function rows(): Cursor
    {
        $cursor = $this->driver->cursor()
            ?? throw new UsageException('The statement is not a query, so it has no rows.', 0, $this->sql);

        return new Cursor($cursor);
    }

    /**
     * The name among `$names` that equals `$name` ignoring case: bind names
     * are case-insensitive.
     *
     * @param list<int|string> $names
     */
    private static function find(string $name, array $names): ?string
    {
        foreach ($names as $candidate) {
            if (strcasecmp((string) $candidate, $name) === 0) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
