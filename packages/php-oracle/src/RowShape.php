<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * How a Cursor shapes its rows: keyed by column name, or a list in column
 * order. Backed by the case name in lowerCamelCase.
 */
enum RowShape: string
{
    case Assoc = 'assoc';
    case List = 'list';
}
