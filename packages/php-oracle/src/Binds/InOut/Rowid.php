<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\InOut;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * An IN OUT ROWID bind: sent as `In\Rowid` sends it, read back as the server writes it.
 */
final readonly class Rowid extends Bind
{
    public function __construct(#[SensitiveParameter] string | null $rowid)
    {
        parent::__construct($rowid);
    }
}
