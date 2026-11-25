<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\DriverCursor;
use Rajska\Oracle\Driver\DriverExecution;

/**
 * A Call's execution as the user named it: OUT values keyed by parameter
 * name instead of the synthetic bind names of the generated block.
 *
 * @internal
 */
final readonly class CallExecution implements DriverExecution
{
    /**
     * @param array<string, string> $parameters the parameter name, by synthetic bind name
     */
    public function __construct(
        private DriverExecution $execution,
        private array $parameters,
    ) {}

    public function rowCount(): int
    {
        return $this->execution->rowCount();
    }

    public function cursor(): DriverCursor | null
    {
        return $this->execution->cursor();
    }

    public function outValues(): array
    {
        $values = [];
        foreach ($this->execution->outValues() as $bind => $value) {
            $values[$this->parameters[$bind] ?? $bind] = $value;
        }

        return $values;
    }

    public function implicitResults(): array
    {
        return $this->execution->implicitResults();
    }

    public function warning(): string | null
    {
        return $this->execution->warning();
    }
}
