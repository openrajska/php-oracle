<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An Associative array (`TABLE OF … INDEX BY PLS_INTEGER`, declared in a
 * package) as `array<int, mixed>`: keys may be sparse or negative, and they
 * come back in key order.
 *
 * ```php
 * new In\Indexed('hr.payroll.amounts', [10 => 1.5, -3 => 2]);
 * ```
 *
 * - The type is named as `In\Struct`'s is, usually `PACKAGE.TYPE`. Elements
 *   map as `In\Struct`'s attributes do.
 * - Keys must be `int`s in the 32-bit signed range. `INDEX BY VARCHAR2` is
 *   not supported yet and fails loudly.
 * - A `null` value is a NULL of the type.
 */
final readonly class Indexed implements SendsValue
{
    /**
     * @param array<int, mixed>|null $value
     */
    public function __construct(public string $name, #[SensitiveParameter] public array | null $value)
    {
    }
}
