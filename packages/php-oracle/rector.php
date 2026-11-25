<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
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
        // Raises PHP issues on purpose (see SuiteRulesTest).
        __DIR__ . '/tests/Harness/Probes',
        // Breaks static closures that call self::method().
        LocallyCalledStaticMethodToNonStaticRector::class,
        // Drops @var annotations PHPStan needs to narrow return values.
        RemoveNonExistingVarAnnotationRector::class,
        // Renames heredoc markers and dedents the SQL inside them.
        SensitiveHereNowDocRector::class,
        // Drops the closure that carries #[\SensitiveParameter].
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
