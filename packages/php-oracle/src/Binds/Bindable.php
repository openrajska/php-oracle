<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds;

/**
 * A typed bind. The namespace is its direction and the class its Oracle
 * type: `new In\Varchar('Ada')`, `new Out\Number()`, `new InOut\Clob($text)`.
 * The Backend knows both by the class.
 */
interface Bindable
{
    //
}
