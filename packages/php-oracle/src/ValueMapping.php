<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * How values reach PHP. The defaults are exact; each opt-in trades exactness
 * or objects for speed.
 */
final readonly class ValueMapping
{
    public function __construct(
        public NumberMode $numbers = NumberMode::Exact,
        public TemporalMode $temporals = TemporalMode::Object,
        public LobMode $lobs = LobMode::Locator,
    ) {}
}
