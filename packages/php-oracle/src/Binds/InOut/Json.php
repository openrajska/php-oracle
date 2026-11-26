<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsJson;

/**
 * An IN OUT JSON bind: sent as `In\Json` sends it, read back as the server writes it.
 */
final readonly class Json extends Bind
{
    use BindsJson;
}
