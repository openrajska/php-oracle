<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Type;

/**
 * The factories of the LOB binds. A value goes into a temporary LOB on the
 * Session when the statement runs, in chunks: a string, or a stream
 * resource read from its current position to its end (it is neither
 * rewound nor closed). Text must be UTF-8.
 *
 * @internal
 */
trait LobBind
{
    abstract private static function type(): Type;

    /**
     * @param string|resource|null $value
     *
     * @throws UsageException if the value is not a string, an open stream or null
     */
    public static function in(#[\SensitiveParameter] mixed $value): self
    {
        return new self(self::type(), Direction::In, self::checked($value));
    }

    /**
     * The server sends back a locator: for a value PL/SQL built, a
     * temporary LOB, valid until the Connection's Release.
     */
    public static function out(): self
    {
        return new self(self::type(), Direction::Out, null);
    }

    /**
     * Sent as `in()` sends it; the server sends back a locator, as `out()`.
     *
     * @param string|resource|null $value
     *
     * @throws UsageException if the value is not a string, an open stream or null
     */
    public static function inOut(#[\SensitiveParameter] mixed $value): self
    {
        return new self(self::type(), Direction::InOut, self::checked($value));
    }

    /**
     * @return string|resource|null
     */
    private static function checked(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($value === null || \is_string($value) || (\is_resource($value) && get_resource_type($value) === 'stream')) {
            return $value;
        }
        $class = substr(self::class, strrpos(self::class, '\\') + 1);

        throw new UsageException(\sprintf(
            '%s::in() takes a string, an open stream resource or null, but was given %s.',
            $class,
            get_debug_type($value),
        ));
    }
}
