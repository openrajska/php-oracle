<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * An Object of a named Object type, as an array keyed by attribute name:
 * the shape a fetched Object has, so one binds back unchanged.
 *
 * ```php
 * Struct::in('hr.address', ['STREET' => 'Main St', 'CITY' => null]);
 * ```
 *
 * - The type is named under Oracle's identifier rules: unquoted parts are
 *   uppercased, a double-quoted part is kept as written, and `PKG.TYPE`
 *   names a type in a package. The current schema and synonyms resolve.
 * - Keys match the attributes ignoring case. Every attribute needs a key
 *   (`null` for a NULL one); an unknown key, or two differing only in case,
 *   fail.
 * - A nested Object is an array too, a Collection a `list`. Values map as
 *   `ValueMapping` reads them: `int`, `float` or a decimal string for
 *   NUMBER, `DateTimeInterface` (or `TemporalMode::String` text) for
 *   timestamps, `DateInterval` for INTERVALs.
 * - `in(null)` is a NULL of the type. Each check runs before the
 *   statement's Round trip; the type is described on its first use in a
 *   Session.
 */
final readonly class Struct extends Bind
{
    /**
     * @param array<string, mixed>|null $value
     */
    public static function in(string $type, #[SensitiveParameter] array | null $value): self
    {
        return new self(Type::Object, Direction::In, $value, $type);
    }

    public static function out(string $type): self
    {
        return new self(Type::Object, Direction::Out, null, $type);
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public static function inOut(string $type, #[SensitiveParameter] array | null $value): self
    {
        return new self(Type::Object, Direction::InOut, $value, $type);
    }
}
