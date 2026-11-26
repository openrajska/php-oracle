<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Concerns\BindsText;
use Rajska\Oracle\Binds\SendsValue;

/**
 * VARCHAR2: UTF-8 text, at most 32767 bytes. It also writes a LONG column;
 * more than 32767 bytes would take a LONG bind, which is not supported yet
 * (UnsupportedException). Bind a CLOB's longer text with `In\Clob`.
 */
final readonly class Varchar implements SendsValue
{
    use BindsText;
}
