<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;

/**
 * A bind with an explicit Oracle type and direction. Each Oracle type has its
 * own class, built with `in()`, `out()` or `inOut()`.
 *
 * The Backend reads only these four properties.
 */
abstract readonly class Bind
{
    /**
     * @param ?string $typeName the type a `Struct`, `Collection` or `Indexed` names; for a `Vector`, its `VectorFormat`'s backing value
     */
    final protected function __construct(
        public Type $type,
        public Direction $direction,
        #[\SensitiveParameter] public mixed $value,
        public ?string $typeName = null,
    ) {
    }
}
