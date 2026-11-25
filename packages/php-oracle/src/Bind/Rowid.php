<?php

declare(strict_types=1);

namespace Rajska\Oracle\Bind;

use Rajska\Oracle\Type;
use SensitiveParameter;

/**
 * ROWID, as its text (`'AAAR3sAAEAAAACXAAA'`).
 */
final readonly class Rowid extends Bind
{
    public static function in(#[SensitiveParameter] ?string $rowid): self
    {
        return new self(Type::Rowid, Direction::In, $rowid);
    }

    public static function out(): self
    {
        return new self(Type::Rowid, Direction::Out, null);
    }

    public static function inOut(#[SensitiveParameter] ?string $rowid): self
    {
        return new self(Type::Rowid, Direction::InOut, $rowid);
    }
}
