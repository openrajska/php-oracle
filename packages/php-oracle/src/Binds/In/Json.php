<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\Binds\Concerns\BindsJson;

/**
 * Native JSON, from any value `json_encode()` takes, read back like
 * `json_decode($text, true)`.
 *
 * ```php
 * new In\Json(['name' => 'Ada', 'tags' => ['x', 'y'], 'score' => 0.1]);
 * ```
 *
 * - An associative array becomes an object, a `list` an array. `[]` is an
 *   empty array; use `new \stdClass()` for an empty object.
 * - A `float` becomes an exact NUMBER (`0.1` stores `0.1`); NaN and INF
 *   fail.
 * - A `JsonSerializable` is expanded, a `BackedEnum` becomes its value, and
 *   any other object its public properties, as `json_encode()` does.
 * - A `DateTimeInterface` becomes a TIMESTAMP: its Wall clock, its zone
 *   ignored.
 * - Strings must be UTF-8. `new In\Json(null)` is SQL NULL; `null` inside the
 *   value is JSON `null`.
 *
 * Objects are expanded when the bind is built.
 */
final readonly class Json extends Bind
{
    use BindsJson;
}
