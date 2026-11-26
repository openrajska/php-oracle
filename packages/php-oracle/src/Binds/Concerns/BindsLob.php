<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use Rajska\Oracle\Exceptions\UsageException;
use SensitiveParameter;

/**
 * The constructor of the LOB binds. A value goes into a temporary LOB on the
 * Session when the statement runs, in chunks: a string, or a stream
 * resource read from its current position to its end (it is neither
 * rewound nor closed). Text must be UTF-8.
 *
 * @internal
 */
trait BindsLob
{
    /** @var resource|string|null */
    public readonly mixed $value;

    /**
     * @param resource|string|null $value
     *
     * @throws UsageException if the value is not a string, an open stream or null
     */
    public function __construct(#[SensitiveParameter] mixed $value)
    {
        if ($value !== null && !\is_string($value) && (!\is_resource($value) || get_resource_type($value) !== 'stream')) {
            throw new UsageException(\sprintf('A LOB bind takes a string, an open stream resource or null, but was given %s.', get_debug_type($value)));
        }
        $this->value = $value;
    }
}
