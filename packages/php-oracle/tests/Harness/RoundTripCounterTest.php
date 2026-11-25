<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

final class RoundTripCounterTest extends TestCase
{
    private IndependentSession $observed;

    private RoundTripCounter $counter;

    protected function setUp(): void
    {
        $this->observed = IndependentSession::open();
        $this->counter = RoundTripCounter::open();
    }

    protected function tearDown(): void
    {
        $this->observed->close();
        $this->counter->close();
    }

    #[Test]
    public function an_idle_session_makes_no_round_trip(): void
    {
        self::assertSame(0, $this->counter->during($this->observed->sid(), static function (): void {}));
    }

    #[Test]
    public function reading_the_count_costs_the_observed_session_nothing(): void
    {
        $sid = $this->observed->sid();

        $first = $this->counter->count($sid);
        $this->counter->count($sid);

        self::assertSame($first, $this->counter->count($sid));
    }

    #[Test]
    public function one_anonymous_block_is_one_round_trip(): void
    {
        self::assertSame(1, $this->counter->during(
            $this->observed->sid(),
            fn() => $this->observed->execute('begin null; end;'),
        ));
    }

    #[Test]
    public function it_counts_every_round_trip(): void
    {
        self::assertSame(3, $this->counter->during($this->observed->sid(), function (): void {
            $this->observed->execute('begin null; end;');
            $this->observed->execute('begin null; end;');
            $this->observed->execute('begin null; end;');
        }));
    }
}
