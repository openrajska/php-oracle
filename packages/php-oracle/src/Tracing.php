<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

/**
 * The tracing attributes a DBA sees in V$SESSION. Unset ones are empty.
 */
final readonly class Tracing
{
    public function __construct(
        public string | null $module = null,
        public string | null $action = null,
        public string | null $clientIdentifier = null,
        public string | null $clientInfo = null,
    ) {
    }

    /**
     * @return array{module: ?string, action: ?string, clientIdentifier: ?string, clientInfo: ?string}
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'action' => $this->action,
            'clientIdentifier' => $this->clientIdentifier,
            'clientInfo' => $this->clientInfo,
        ];
    }
}
