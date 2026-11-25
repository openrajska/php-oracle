<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Support;

/**
 * Counts the Round trips a Session makes, from the outside.
 *
 * It reads `SQL*Net roundtrips to/from client` (the statistic V$MYSTAT shows
 * a Session about itself) from V$SESSTAT in an IndependentSession of its own,
 * so reading the count never adds a Round trip to the Session it observes.
 * "Fails before any Round trip" is then:
 *
 *     self::assertSame(0, $counter->during($sid, fn () => ...));
 *
 * Get the observed Session's SID once, before counting, with
 * `select sys_context('userenv', 'sid') from dual`.
 */
final readonly class RoundTripCounter
{
    private const string SQL = <<<'SQL'
        select s.value
          from v$sesstat s
          join v$statname n on n.statistic# = s.statistic#
         where n.name = 'SQL*Net roundtrips to/from client'
           and s.sid = :sid
        SQL;

    private function __construct(private IndependentSession $observer) {}

    public static function open(): self
    {
        return new self(IndependentSession::open());
    }

    /**
     * The Round trips the Session with this SID has made so far.
     */
    public function count(int $sid): int
    {
        $rows = $this->observer->select(self::SQL, ['sid' => $sid]);
        if ($rows === []) {
            throw new \RuntimeException("No Session with SID $sid.");
        }

        return (int) $rows[0]['VALUE'];
    }

    /**
     * The Round trips the Session with this SID made while $action ran.
     *
     * @param callable(): mixed $action
     */
    public function during(int $sid, callable $action): int
    {
        $before = $this->count($sid);
        $action();

        return $this->count($sid) - $before;
    }

    public function close(): void
    {
        $this->observer->close();
    }
}
