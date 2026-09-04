<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * The refactorings the project has already agreed to.
 *
 * It runs in the gate as a dry run, so it never changes anything on its own;
 * a suggestion it makes is a change somebody has to look at and apply. The
 * sets are chosen to answer questions rather than to have opinions: is this
 * written the way PHP 8.4 lets it be written, is any of it dead, and is
 * anything typed more loosely than the code already proves it to be.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/www',
    ])
    ->withSkip([
        // A standalone script from before composer; see .php-cs-fixer.dist.php.
        __DIR__ . '/tests/Tooling/CheckLeaksTest.php',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
