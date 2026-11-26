<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsText;

/**
 * NCHAR: fixed-length text in the national character set.
 */
final readonly class NChar extends Bind
{
    use BindsText;
}
