<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php73\Rector\String_\SensitiveHereNowDocRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/bench',
    ])
    ->withSkip([
        __DIR__ . '/tests/Harness/Probes',
        RemoveNonExistingVarAnnotationRector::class,
        SensitiveHereNowDocRector::class,
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
    ])
    ->withImportNames()
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
