<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use Rajska\Oracle\DataType\SparseVector;
use Rajska\Oracle\DataType\VectorFormat;
use SensitiveParameter;

/**
 * The constructor of the VECTOR binds that send values.
 *
 * @internal
 */
trait BindsVector
{
    /**
     * @param SparseVector|list<float|int>|null $values
     */
    public function __construct(
        #[SensitiveParameter]
        SparseVector | array | null $values,
        public readonly VectorFormat $format = VectorFormat::Float64,
    ) {
        parent::__construct($values);
    }
}
