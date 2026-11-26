<?php

declare(strict_types = 1);

use Pest\Rector\Set\PestSetList;
use Rajska\Oracle\Rector\KeepBindDirectionRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/bench',
    ])
    ->withSkip([
        __DIR__ . '/tests/Harness/Probes',
    ])
    ->withImportNames()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
    )
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withPhpSets()
    ->withRules([
        KeepBindDirectionRector::class,
    ]);
