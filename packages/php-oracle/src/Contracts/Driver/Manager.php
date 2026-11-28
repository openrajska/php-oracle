<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Contracts\Driver;

use Rajska\Oracle\ConnectionConfig;

/**
 * A Backend, as Core sees it: the entry point of the Driver contract.
 *
 * Every Backend gives a distinct Session per `connect()` and throws Core's
 * public exceptions. The conformance catalog of spec #32 fixes the rest.
 *
 * @internal
 */
interface Manager
{
    public function connect(ConnectionConfig $config): Session;
}
