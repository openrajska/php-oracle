<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use Rajska\Oracle\Exceptions\UsageException;
use SensitiveParameter;

/**
 * The constructor of the NUMBER binds. A string must be a decimal literal
 * with `.`, independent of NLS and with no exponent (`'12.30'`, `'-.5'`).
 *
 * @internal
 */
trait BindsNumber
{
    /**
     * @throws UsageException if the string is not a decimal literal
     */
    public function __construct(#[SensitiveParameter] float | int | string | null $value)
    {
        if (\is_string($value) && preg_match('/\A[+-]?(\d+(\.\d*)?|\.\d+)\z/', $value) !== 1) {
            throw new UsageException("A NUMBER bind takes a decimal literal with '.' as the separator, such as '12.30'; the string given is not one.");
        }
        parent::__construct($value);
    }
}
