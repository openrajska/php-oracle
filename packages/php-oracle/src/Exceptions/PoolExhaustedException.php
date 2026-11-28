<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Exceptions;

/**
 * The Pool stayed at its ceiling for the whole `acquireTimeout`.
 */
final class PoolExhaustedException extends ConnectionException
{
}
