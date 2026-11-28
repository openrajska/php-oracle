<?php

declare(strict_types = 1);

use Pest\Rector\Rules\Pest2ToPest3\UsesToExtendRector;
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
        // Each test file names its TestCase with uses().
        UsesToExtendRector::class,
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
