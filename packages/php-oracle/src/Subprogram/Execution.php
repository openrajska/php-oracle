<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Subprogram;

use Override;
use Rajska\Oracle\Contracts\Driver\Cursor as CursorContract;
use Rajska\Oracle\Contracts\Driver\Response;

/**
 * A Call's execution as the user named it: OUT values keyed by parameter
 * name instead of the synthetic bind names of the generated block.
 *
 * @internal
 */
final readonly class Execution implements Response
{
    /**
     * @param array<string, string> $parameters the parameter name, by synthetic bind name
     */
    public function __construct(
        private Response $execution,
        private array $parameters,
    ) {
    }

    #[Override]
    public function rowCount(): int
    {
        return $this->execution->rowCount();
    }

    #[Override]
    public function cursor(): CursorContract | null
    {
        return $this->execution->cursor();
    }

    #[Override]
    public function outValues(): array
    {
        $values = [];
        foreach ($this->execution->outValues() as $bind => $value) {
            $values[$this->parameters[$bind] ?? $bind] = $value;
        }

        return $values;
    }

    #[Override]
    public function implicitResults(): array
    {
        return $this->execution->implicitResults();
    }

    #[Override]
    public function warning(): string | null
    {
        return $this->execution->warning();
    }
}
