<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsLob;

/**
 * An IN OUT BLOB bind. Sent as `In\Blob` sends it; the server sends back a locator, as `Out\Blob`.
 */
final readonly class Blob extends Bind
{
    use BindsLob;
}
