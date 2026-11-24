<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

/**
 * Watches fx_tx_rows (fixtures/sql/11_transaction.sql) from an
 * IndependentSession of its own, as another Connection would: it sees
 * committed rows only, and finds a row locked when a transaction holds it.
 * One per test, so a test opens one extra Session, not one per look.
 */
final class TransactionObserver
{
    private function __construct(private readonly IndependentSession $session)
    {
    }

    /**
     * Opens the observer and empties fx_tx_rows.
     */
    public static function open(): self
    {
        $observer = new self(IndependentSession::open());
        $observer->clear();

        return $observer;
    }

    /**
     * The committed ids in fx_tx_rows, in order.
     *
     * @return list<int>
     *
     * @phpstan-impure
     */
    public function committed(): array
    {
        return array_map(static fn (array $row): int => (int) $row['ID'], $this->select('select id from fx_tx_rows order by id'));
    }

    /**
     * Rows of a query, committed data only.
     *
     * @param array<string, scalar|null> $binds
     *
     * @return list<array<string, string|null>>
     *
     * @phpstan-impure
     */
    public function select(string $sql, array $binds = []): array
    {
        return $this->session->select($sql, $binds);
    }

    /**
     * Runs a statement and commits it.
     *
     * @param array<string, scalar|null> $binds
     */
    public function execute(string $sql, array $binds = []): void
    {
        $this->session->execute($sql, $binds);
        $this->session->commit();
    }

    /**
     * Whether another transaction holds the fx_tx_rows row
     * (`FOR UPDATE NOWAIT` fails with ORA-00054). It never keeps the lock.
     *
     * @phpstan-impure
     */
    public function locked(int $id): bool
    {
        try {
            $this->session->select('select id from fx_tx_rows where id = :id for update nowait', ['id' => $id]);

            return false;
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 54) {
                throw $e;
            }

            return true;
        } finally {
            $this->session->rollback();
        }
    }

    /**
     * Empties fx_tx_rows, then ends the observer's Session.
     */
    public function close(): void
    {
        try {
            $this->clear();
        } finally {
            $this->session->close();
        }
    }

    private function clear(): void
    {
        $this->execute('delete from fx_tx_rows');
    }
}
