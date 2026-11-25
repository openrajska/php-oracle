<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Harness\RoundTripCounterTest;

use Rajska\Oracle\Tests\Support\IndependentSession;
use Rajska\Oracle\Tests\Support\RoundTripCounter;

beforeEach(function (): void {
    $this->observed = IndependentSession::open();
    $this->counter = RoundTripCounter::open();
});
afterEach(function (): void {
    $this->observed->close();
    $this->counter->close();
});
test('an idle session makes no round trip', function (): void {
    expect($this->counter->during($this->observed->sid(), static function (): void {
    }))->toBe(0);
});
test('reading the count costs the observed session nothing', function (): void {
    $sid = $this->observed->sid();

    $first = $this->counter->count($sid);
    $this->counter->count($sid);

    expect($this->counter->count($sid))->toBe($first);
});
test('one anonymous block is one round trip', function (): void {
    expect($this->counter->during(
        $this->observed->sid(),
        fn() => $this->observed->execute('begin null; end;'),
    ))->toBe(1);
});
it('counts every round trip', function (): void {
    expect($this->counter->during($this->observed->sid(), function (): void {
        $this->observed->execute('begin null; end;');
        $this->observed->execute('begin null; end;');
        $this->observed->execute('begin null; end;');
    }))->toBe(3);
});
