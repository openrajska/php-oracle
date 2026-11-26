<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsText;

/**
 * NVARCHAR2: text in the national character set.
 */
final readonly class NVarchar extends Bind
{
    use BindsText;
}
