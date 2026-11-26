<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsText;

/**
 * CHAR: fixed-length text, compared blank-padded (`new In\Char('AB')` matches a
 * CHAR(5) `'AB   '`).
 */
final readonly class Char extends Bind
{
    use BindsText;
}
