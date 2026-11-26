<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Binds\In;

use Rajska\Oracle\Binds\Bind;
use SensitiveParameter;

/**
 * ROWID, as its text (`'AAAR3sAAEAAAACXAAA'`).
 */
final readonly class Rowid extends Bind
{
    public function __construct(#[SensitiveParameter] string | null $rowid)
    {
        parent::__construct($rowid);
    }
}
