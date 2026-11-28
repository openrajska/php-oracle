<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsText;
use Rajska\Oracle\Binds\SendsValue;

/**
 * NVARCHAR2: text in the national character set.
 */
final readonly class NVarchar implements SendsValue
{
    use BindsText;
}
