<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Statement;

/**
 * How DATE, TIMESTAMP, TSTZ and TSLTZ values reach PHP: as
 * `DateTimeImmutable`, or as NLS-independent ISO text.
 */
enum TemporalMode: string
{
    case Object = 'object';
    case String = 'string';
}
