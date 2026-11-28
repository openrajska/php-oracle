<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\SendsValue;
use SensitiveParameter;

/**
 * An Object of a named Object type, as an array keyed by attribute name:
 * the shape a fetched Object has, so one binds back unchanged.
 *
 * ```php
 * new In\Struct('hr.address', ['STREET' => 'Main St', 'CITY' => null]);
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
 * - A `null` value is a NULL of the type. Each check runs before the
 *   statement's Round trip; the type is described on its first use in a
 *   Session.
 */
final readonly class Struct implements SendsValue
{
    /**
     * @param array<string, mixed>|null $value
     */
    public function __construct(public string $name, #[SensitiveParameter] public array | null $value)
    {
    }
}
