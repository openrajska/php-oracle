<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsText;
use Rajska\Oracle\Binds\SendsValue;

/**
 * CHAR: fixed-length text, compared blank-padded (`new In\Char('AB')` matches a
 * CHAR(5) `'AB   '`).
 */
final readonly class Char implements SendsValue
{
    use BindsText;
}
