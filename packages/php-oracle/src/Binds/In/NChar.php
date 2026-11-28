<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsText;
use Rajska\Oracle\Binds\SendsValue;

/**
 * NCHAR: fixed-length text in the national character set.
 */
final readonly class NChar implements SendsValue
{
    use BindsText;
}
