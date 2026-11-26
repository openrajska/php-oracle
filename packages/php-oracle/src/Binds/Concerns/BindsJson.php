<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\Concerns;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Rajska\Oracle\Exceptions\UsageException;
use SensitiveParameter;
use UnitEnum;

/**
 * The constructor of the JSON binds. Objects are expanded here, as
 * `json_encode()` would see them.
 *
 * @internal
 */
trait BindsJson
{
    public readonly mixed $value;

    /** How deep a value may nest, as `json_encode()` allows by default. */
    private const int DEPTH = 512;

    /**
     * @throws UsageException if the value cannot be encoded as JSON
     */
    public function __construct(#[SensitiveParameter] mixed $value)
    {
        $this->value = self::encodable($value, 0);
    }

    /**
     * The value with every object other than `stdClass` and
     * `DateTimeInterface` expanded, as `json_encode()` would see it.
     */
    private static function encodable(#[SensitiveParameter] mixed $value, int $depth): mixed
    {
        if ($depth > self::DEPTH) {
            throw new UsageException(\sprintf('A JSON bind takes values nested at most %d levels deep.', self::DEPTH));
        }

        return match (true) {
            $value === null, \is_bool($value), \is_int($value), \is_string($value), $value instanceof DateTimeInterface => $value,
            \is_float($value) => is_finite($value) ? $value : throw new UsageException('A JSON bind cannot hold NAN or INF.'),
            \is_array($value) => array_map(static fn (#[SensitiveParameter] mixed $element): mixed => self::encodable($element, $depth + 1), $value),
            $value instanceof JsonSerializable => self::encodable($value->jsonSerialize(), $depth + 1),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => throw new UsageException(\sprintf('A JSON bind cannot encode the enum %s: it has no backing value.', $value::class)),
            \is_object($value) => (object) array_map(
                static fn (#[SensitiveParameter] mixed $property): mixed => self::encodable($property, $depth + 1),
                get_object_vars($value),
            ),
            default => throw new UsageException(\sprintf('A JSON bind cannot hold a %s.', get_debug_type($value))),
        };
    }
}
