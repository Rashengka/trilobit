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
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    // Serial on purpose. In parallel the run failed on CI - on both PHP
    // versions, at the same step - with a worker error and a parser message
    // that names no file: "Unclosed '(' on line 9". No file in the project has
    // that: every .php under src, tests and www lints clean, and the run is
    // green here in every shape it can be given, including a fresh clone with
    // the cache cleared and assertions on. What is left is the worker
    // scheduling on a smaller machine, and a gate whose result depends on that
    // is a gate people learn to re-run instead of read. The same eighty files
    // are still checked; only the number of processes changes.
    //
    // Revisit when the run stops being quick - it is a few seconds today.
    ->withoutParallel();
