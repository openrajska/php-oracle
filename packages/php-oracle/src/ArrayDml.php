<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use Countable;
use Rajska\Oracle\Driver\DriverArrayDml;
use Rajska\Oracle\Exceptions\UsageException;

/**
 * What one `runMany()` produced: every bind row ran, in one Round trip.
 */
final class ArrayDml implements Countable
{
    /**
     * The OUT values, per bind, one list per bind row, each LOB wrapped
     * once as a Lob.
     *
     * @var array<string, list<list<mixed>>>|null
     */
    private array | null $outValues = null;

    /**
     * @internal use Connection::runMany()
     *
     * @param int<0, max> $count the bind rows sent
     * @param list<string> $bindNames every bind's name as the user wrote it
     */
    public function __construct(
        private readonly DriverArrayDml $driver,
        private readonly int $count,
        private readonly string $sql,
        private readonly ConnectionState $connection,
        private readonly array $bindNames = [],
    ) {}

    /**
     * The INSERT / UPDATE / DELETE / MERGE count, across every bind row; 0
     * for a PL/SQL block.
     */
    public function affected(): int
    {
        return $this->driver->rowCount();
    }

    /**
     * The bind rows sent.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * The values an OUT or IN OUT bind brought back: one OutValue per bind
     * row, in row order. With `RETURNING … INTO`, each holds the rows its
     * bind row returned. An ArrayDml of no rows has none.
     *
     * @return list<OutValue>
     *
     * @throws UsageException if no OUT or IN OUT bind has that name
     */
    public function out(string $name): array
    {
        $values = $this->outValues ??= array_map(
            fn(array $rows): array => array_map(
                fn(array $values): array => Lob::wrap(Cursor::wrap($values, $this->connection), $this->connection),
                $rows,
            ),
            $this->driver->outValues(),
        );
        $key = \array_key_exists($name, $values) ? $name : $this->find($name, array_keys($values));
        if ($key !== null) {
            return array_map(fn(array $row): OutValue => new OutValue($key, $row, $this->sql), $values[$key]);
        }

        $in = $this->find($name, $this->bindNames);
        if ($in !== null) {
            throw new UsageException("Bind '$in' is IN only, so it has no OUT value; bind it with out() or inOut() of a typed bind to read it back.", 0, $this->sql);
        }
        $names = $values === [] ? 'the statement has none' : "they are '" . implode("', '", array_keys($values)) . "'";

        throw new UsageException("No OUT bind is named '$name'; $names.", 0, $this->sql);
    }

    /**
     * The name among `$names` that equals `$name` ignoring case: bind names
     * are case-insensitive.
     *
     * @param list<int|string> $names
     */
    private function find(string $name, array $names): string | null
    {
        foreach ($names as $candidate) {
            if (strcasecmp((string) $candidate, $name) === 0) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
