<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds;

use SensitiveParameter;

/**
 * A bind with an explicit Oracle type and direction. The namespace is the
 * direction and the class the type: `new In\Varchar('Ada')`,
 * `new Out\Number()`, `new InOut\Clob($text)`.
 *
 * The Backend knows the type and the direction by the class, and reads
 * `value`, plus the one property a class adds: `typeName` (`Struct`,
 * `Collection`, `Indexed`) or `format` (`Vector`).
 */
abstract readonly class Bind
{
    protected function __construct(
        #[SensitiveParameter]
        public mixed $value,
    ) {
    }
}
