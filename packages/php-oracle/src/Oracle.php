<?php

declare(strict_types = 1);

namespace Rajska\Oracle;

use Rajska\Oracle\Driver\Extension\ExtensionDriver;

/**
 * The entry point.
 */
final class Oracle
{
    /**
     * Opens a Connection with a Session of its own.
     */
    public static function connect(ConnectionConfig $config): Connection
    {
        return new Connection(new ExtensionDriver()->connect($config), $config);
    }
}
