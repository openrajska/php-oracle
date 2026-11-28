<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

/**
 * ORA-00001: a unique constraint was violated.
 */
final class UniqueViolationException extends QueryException
{
}
